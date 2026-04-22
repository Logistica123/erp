<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\Auxiliar;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Models\Tesoreria\CobroItem;
use App\Erp\Models\Tesoreria\CobroMedio;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\Echeq;
use App\Erp\Models\Tesoreria\MedioPago;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Registra un cobro de cliente con N items y N medios (SPEC 02 §7.5, RN-27).
 *
 * Flujo:
 *  1) Valida RN-27: SUM(items) == SUM(medios).
 *  2) Por cada medio:
 *     - EFECTIVO (afecta_caja)   → medio ACREDITADO, débito a caja.cuenta_contable_id
 *     - TRANSFERENCIA/MP (afecta_banco) → medio PENDIENTE (conciliar con extracto),
 *       débito a banco.cuenta_contable_id
 *     - ECHEQ (genera_echeq) → medio PENDIENTE, débito a 1.1.1.04 Valores a Depositar
 *       y se crea un erp_echeq en estado EN_CARTERA con cobro_id linkeado.
 *  3) Contrapartida: HABER 1.1.4.01 Deudores por Ventas con auxiliar=cliente.
 *  4) Estado del cobro:
 *     - Todos los medios ACREDITADOS → ACREDITADO.
 *     - Alguno ACREDITADO, otros PENDIENTE → PARCIAL_ACREDITADO.
 *     - Ninguno ACREDITADO → REGISTRADO.
 *
 * No toca la factura de venta (eso se hace al emitir — aquí el cobro vive
 * independiente, linkeado por item.factura_id). Futuras iteraciones pueden
 * marcar la factura como COBRADA cuando corresponda.
 */
class CobroService
{
    public const CODIGO_DEUDORES = '1.1.4.01';
    public const CODIGO_VALORES = '1.1.1.04';

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   empresa_id:int,
     *   usuario_id:int,
     *   fecha:string,
     *   auxiliar_id:int,
     *   moneda_id:int,
     *   cotizacion?:float,
     *   concepto?:?string,
     *   observaciones?:?string,
     *   total_retenciones?:float,
     *   items: array<int, array{
     *     tipo_item:string, factura_id?:?int, cuenta_contable_id?:?int,
     *     concepto:string, importe:float|string,
     *   }>,
     *   medios: array<int, array{
     *     medio_pago_id:int,
     *     caja_id?:?int, cuenta_bancaria_id?:?int, echeq?:?array,
     *     importe:float|string, referencia?:?string,
     *   }>,
     * }  $data
     */
    public function registrar(array $data): Cobro
    {
        if (empty($data['items']) || empty($data['medios'])) {
            throw new DomainException('COBRO_VACIO: requiere al menos un item y un medio');
        }

        $auxiliar = Auxiliar::findOrFail($data['auxiliar_id']);
        $sumaItems = round(array_sum(array_map(fn ($i) => (float) $i['importe'], $data['items'])), 2);
        $sumaMedios = round(array_sum(array_map(fn ($m) => (float) $m['importe'], $data['medios'])), 2);

        if (abs($sumaItems - $sumaMedios) > 0.01) {
            throw new DomainException(sprintf(
                'COBRO_DESBALANCEADO: items=%.2f ≠ medios=%.2f (RN-27)',
                $sumaItems,
                $sumaMedios
            ));
        }

        return DB::transaction(function () use ($data, $auxiliar, $sumaItems) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $numero = $this->proximoNumero($data['empresa_id']);

            $cobro = Cobro::create([
                'empresa_id' => $data['empresa_id'],
                'numero' => $numero,
                'fecha' => $data['fecha'],
                'auxiliar_id' => $auxiliar->id,
                'moneda_id' => $data['moneda_id'],
                'cotizacion' => $data['cotizacion'] ?? 1.0,
                'importe_total' => $sumaItems,
                'total_retenciones' => $data['total_retenciones'] ?? 0,
                'estado' => Cobro::ESTADO_REGISTRADO,
                'concepto' => $data['concepto'] ?? "Cobro {$auxiliar->nombre}",
                'observaciones' => $data['observaciones'] ?? null,
                'creado_por_user_id' => $data['usuario_id'],
            ]);

            foreach ($data['items'] as $i) {
                CobroItem::create([
                    'cobro_id' => $cobro->id,
                    'tipo_item' => $i['tipo_item'] ?? CobroItem::TIPO_FACTURA_VENTA,
                    'factura_id' => $i['factura_id'] ?? null,
                    'cuenta_contable_id' => $i['cuenta_contable_id'] ?? null,
                    'concepto' => $i['concepto'],
                    'importe' => $i['importe'],
                ]);
            }

            $movimientosAsiento = [];
            $estadosMedios = [];

            foreach ($data['medios'] as $m) {
                [$medioPersistido, $lineaAsiento, $estadoAcred] = $this->procesarMedio($m, $cobro, $auxiliar, $data['usuario_id']);
                $movimientosAsiento[] = $lineaAsiento;
                $estadosMedios[] = $estadoAcred;
            }

            // Línea contrapartida única: HABER 1.1.4.01 Deudores, auxiliar=cliente.
            $cuentaDeudores = $this->cuentaDeudores($data['empresa_id']);
            $movimientosAsiento[] = [
                'cuenta_id' => $cuentaDeudores->id,
                'debe' => 0,
                'haber' => $sumaItems,
                'glosa' => "Cobro {$cobro->numero}",
                'auxiliar_id' => $cuentaDeudores->admite_auxiliar ? $auxiliar->id : null,
            ];

            $movimientosAsiento = $this->completarCc($movimientosAsiento, $data['empresa_id']);

            $diarioTes = DB::table('erp_diarios')
                ->where('empresa_id', $data['empresa_id'])
                ->where('codigo', 'TES')
                ->value('id');

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $data['empresa_id'],
                'diario_id' => $diarioTes,
                'fecha' => $data['fecha'],
                'glosa' => sprintf('Cobro %s · %s', $cobro->numero, $auxiliar->nombre),
                'origen' => 'COBRO',
                'origen_id' => $cobro->id,
                'origen_tabla' => 'erp_cobros',
                'usuario_id' => $data['usuario_id'],
                'movimientos' => $movimientosAsiento,
            ]);
            $asiento = $this->asientoService->contabilizar($asiento);

            $cobro->update([
                'asiento_id' => $asiento->id,
                'estado' => $this->resolverEstadoInicial($estadosMedios),
            ]);

            $this->audit->logEvento(
                accion: 'COBRO_REGISTRADO',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'Cobro %s · cliente %s · $%s · asiento %d',
                    $cobro->numero,
                    $auxiliar->nombre,
                    number_format($sumaItems, 2, ',', '.'),
                    $asiento->id
                ),
                empresaId: $data['empresa_id'],
            );

            return $cobro->fresh(['items', 'medios', 'asiento']);
        });
    }

    public function anular(Cobro $cobro, string $motivo, User $usuario): Cobro
    {
        if ($cobro->estado === Cobro::ESTADO_ANULADO) {
            throw new DomainException('COBRO_YA_ANULADO');
        }
        // Si el cobro tiene medios ACREDITADOS (efectivo que ya movió caja)
        // la anulación debería revertir los asientos. Para simplificar V1, la
        // anulación se limita a cobros en REGISTRADO (sin medios acreditados).
        if ($cobro->estado === Cobro::ESTADO_ACREDITADO) {
            throw new DomainException('COBRO_ACREDITADO: contraasentar manualmente antes de anular');
        }

        return DB::transaction(function () use ($cobro, $motivo, $usuario) {
            // Si hay eCheq en EN_CARTERA asociados, los anulamos en cascada.
            Echeq::where('cobro_id', $cobro->id)
                ->where('estado', Echeq::ESTADO_EN_CARTERA)
                ->update([
                    'estado' => Echeq::ESTADO_ANULADO,
                    'motivo_rechazo' => 'Cobro anulado: '.$motivo,
                ]);

            $cobro->update([
                'estado' => Cobro::ESTADO_ANULADO,
                'motivo_anulacion' => $motivo,
            ]);

            $this->audit->logEvento(
                accion: 'COBRO_ANULADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Cobro %s anulado: %s', $cobro->numero, $motivo),
                empresaId: $cobro->empresa_id,
            );

            return $cobro->fresh();
        });
    }

    /**
     * Procesa un medio: crea erp_cobro_medios + (opcional) erp_echeq, y
     * devuelve {medio, linea_asiento, estado_acreditacion}.
     *
     * @return array{0:CobroMedio, 1:array<string,mixed>, 2:string}
     */
    private function procesarMedio(array $m, Cobro $cobro, Auxiliar $auxiliar, int $usuarioId): array
    {
        $medio = MedioPago::findOrFail($m['medio_pago_id']);
        $importe = (float) $m['importe'];
        $glosa = "Cobro {$cobro->numero} · {$medio->nombre}";

        $cuentaContableDebeId = null;
        $estadoAcred = CobroMedio::ESTADO_PENDIENTE;
        $cajaId = null;
        $cuentaBancariaId = null;
        $echeqId = null;

        if ($medio->afecta_caja) {
            $caja = Caja::where('empresa_id', $cobro->empresa_id)
                ->where('id', $m['caja_id'] ?? 0)
                ->firstOrFail();
            $cajaId = $caja->id;
            $cuentaContableDebeId = $caja->cuenta_contable_id;
            $estadoAcred = CobroMedio::ESTADO_ACREDITADO; // el efectivo entra a caja al instante
        } elseif ($medio->genera_echeq) {
            $cuentaValores = $this->cuentaValores($cobro->empresa_id);
            $cuentaContableDebeId = $cuentaValores->id;
            // Se crea erp_echeq más abajo tras crear el CobroMedio (orden FK).
        } elseif ($medio->afecta_banco) {
            $banco = CuentaBancaria::where('empresa_id', $cobro->empresa_id)
                ->where('id', $m['cuenta_bancaria_id'] ?? 0)
                ->firstOrFail();
            $cuentaBancariaId = $banco->id;
            $cuentaContableDebeId = $banco->cuenta_contable_id;
            // PENDIENTE hasta conciliar con extracto bancario
        } else {
            // Medios "abstractos" (retención, compensación, otro): deben venir
            // con cuenta_contable_id explícita.
            if (empty($m['cuenta_contable_id'])) {
                throw new DomainException('MEDIO_SIN_CUENTA: el medio '.$medio->codigo.' requiere cuenta_contable_id');
            }
            $cuentaContableDebeId = (int) $m['cuenta_contable_id'];
            $estadoAcred = CobroMedio::ESTADO_ACREDITADO; // imputación directa
        }

        $cobroMedio = CobroMedio::create([
            'cobro_id' => $cobro->id,
            'medio_pago_id' => $medio->id,
            'cuenta_bancaria_id' => $cuentaBancariaId,
            'caja_id' => $cajaId,
            'echeq_id' => null, // se setea abajo si corresponde
            'importe' => $importe,
            'referencia' => $m['referencia'] ?? null,
            'estado_acreditacion' => $estadoAcred,
        ]);

        // Si es eCheq, crear el registro en erp_echeq y linkearlo al medio.
        if ($medio->genera_echeq) {
            $echeqData = $m['echeq'] ?? null;
            if (! $echeqData || empty($echeqData['numero']) || empty($echeqData['cuit_librador'])) {
                throw new DomainException('ECHEQ_DATOS_INCOMPLETOS: se requiere numero + cuit_librador + fecha_pago');
            }
            $echeq = Echeq::create([
                'empresa_id' => $cobro->empresa_id,
                'numero' => $echeqData['numero'],
                'cuit_librador' => $echeqData['cuit_librador'],
                'razon_social_librador' => $echeqData['razon_social_librador'] ?? $auxiliar->nombre,
                'banco_origen' => $echeqData['banco_origen'] ?? null,
                'cbu_origen' => $echeqData['cbu_origen'] ?? null,
                'importe' => $importe,
                'moneda_id' => $cobro->moneda_id,
                'fecha_emision' => $echeqData['fecha_emision'] ?? $cobro->fecha,
                'fecha_pago' => $echeqData['fecha_pago'] ?? $cobro->fecha,
                'estado' => Echeq::ESTADO_EN_CARTERA,
                'cobro_id' => $cobro->id,
            ]);
            $cobroMedio->update(['echeq_id' => $echeq->id]);
            $echeqId = $echeq->id;
            $glosa .= " eCheq {$echeq->numero}";
        }

        $lineaAsiento = [
            'cuenta_id' => $cuentaContableDebeId,
            'debe' => $importe,
            'haber' => 0,
            'glosa' => $glosa,
            'auxiliar_id' => null, // la contrapartida (Deudores) ya lleva el auxiliar
        ];

        return [$cobroMedio, $lineaAsiento, $estadoAcred];
    }

    /**
     * @param  array<int, string>  $estados
     */
    private function resolverEstadoInicial(array $estados): string
    {
        $acreditados = count(array_filter($estados, fn ($e) => $e === CobroMedio::ESTADO_ACREDITADO));
        $total = count($estados);

        if ($acreditados === $total) {
            return Cobro::ESTADO_ACREDITADO;
        }
        if ($acreditados > 0) {
            return Cobro::ESTADO_PARCIAL_ACREDITADO;
        }

        return Cobro::ESTADO_REGISTRADO;
    }

    private function cuentaDeudores(int $empresaId): CuentaContable
    {
        $c = CuentaContable::where('empresa_id', $empresaId)->where('codigo', self::CODIGO_DEUDORES)->first();
        if (! $c) {
            throw new DomainException('CUENTA_CONTABLE_NO_ENCONTRADA: '.self::CODIGO_DEUDORES.' Deudores por Ventas');
        }

        return $c;
    }

    private function cuentaValores(int $empresaId): CuentaContable
    {
        $c = CuentaContable::where('empresa_id', $empresaId)->where('codigo', self::CODIGO_VALORES)->first();
        if (! $c) {
            throw new DomainException('CUENTA_CONTABLE_NO_ENCONTRADA: '.self::CODIGO_VALORES.' Valores a Depositar');
        }

        return $c;
    }

    private function proximoNumero(int $empresaId): string
    {
        $ultimo = DB::table('erp_cobros')
            ->where('empresa_id', $empresaId)
            ->where('numero', 'like', 'REC-%')
            ->orderByDesc('id')
            ->value('numero');

        $n = 1;
        if ($ultimo && preg_match('/REC-(?:\d+-)?(\d+)/', $ultimo, $match)) {
            $n = ((int) $match[1]) + 1;
        }

        return sprintf('REC-%s-%06d', date('Y'), $n);
    }

    /**
     * @param  array<int, array<string, mixed>>  $movs
     * @return array<int, array<string, mixed>>
     */
    private function completarCc(array $movs, int $empresaId): array
    {
        $ccFallback = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'CENTRAL')
            ->value('id');

        foreach ($movs as &$m) {
            if (empty($m['centro_costo_id'])) {
                $cuenta = CuentaContable::find($m['cuenta_id']);
                if ($cuenta && $cuenta->admite_cc && $ccFallback) {
                    $m['centro_costo_id'] = (int) $ccFallback;
                }
            }
        }

        return $movs;
    }
}

<?php

namespace App\Erp\Services;

use App\Erp\Models\Asiento;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Models\Tesoreria\TransferenciaInterna;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Transferencia entre dos cuentas bancarias propias (SPEC 02 RN-20).
 *
 * registrar():
 *  · Crea erp_transferencias_internas en estado PENDIENTE.
 *  · Crea DOS movimientos bancarios en extracto virtual "manual" de cada
 *    cuenta: egreso en origen, ingreso en destino. Ambos estado PENDIENTE
 *    hasta que se concilien con extractos reales.
 *
 * contabilizar():
 *  · Genera UN asiento en diario BAN: DEBE cuenta destino · HABER cuenta origen.
 *  · Si las monedas difieren, aplica tipo_cambio y la diferencia en ARS
 *    entre importe_origen y importe_destino se imputa a 4.2.04 (ganancia)
 *    o 5.4.03 (pérdida). Sin diferencia relevante, asiento es balanceado
 *    directo en ARS.
 *  · Estado pasa a CONCILIADA. Los movimientos bancarios quedan marcados
 *    con movimiento_origen_id / movimiento_destino_id + asiento_id.
 *
 * Flujo operativo: el usuario registra la TI en el ERP, hace la
 * transferencia real en el home banking, y al importar extractos de ambas
 * cuentas los movimientos bancarios se concilian contra la TI
 * (reemplazando los mov "virtuales" o linkeando los del extracto real —
 * según decisión del operador).
 */
class TransferenciaInternaService
{
    public const CODIGO_DIF_CAMBIO_POS = '4.2.04';
    public const CODIGO_DIF_CAMBIO_NEG = '5.4.03';

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   empresa_id:int,
     *   usuario_id:int,
     *   fecha:string,
     *   cuenta_origen_id:int,
     *   cuenta_destino_id:int,
     *   importe_origen:float|string,
     *   importe_destino?:float|string|null,
     *   tipo_cambio?:float|string|null,
     *   concepto?:?string,
     * }  $data
     */
    public function registrar(array $data): TransferenciaInterna
    {
        if ((int) $data['cuenta_origen_id'] === (int) $data['cuenta_destino_id']) {
            throw new DomainException('TI_CUENTAS_IGUALES: origen y destino deben ser distintas');
        }

        $origen = CuentaBancaria::with('moneda')->findOrFail($data['cuenta_origen_id']);
        $destino = CuentaBancaria::with('moneda')->findOrFail($data['cuenta_destino_id']);

        if ($origen->empresa_id !== $destino->empresa_id || $origen->empresa_id !== $data['empresa_id']) {
            throw new DomainException('TI_EMPRESA_MISMATCH: las cuentas deben ser de la misma empresa');
        }

        $importeOrigen = round((float) $data['importe_origen'], 2);
        if ($importeOrigen <= 0) {
            throw new DomainException('TI_IMPORTE_INVALIDO: importe_origen debe ser > 0');
        }

        $mismaMoneda = $origen->moneda_id === $destino->moneda_id;
        $tipoCambio = round((float) ($data['tipo_cambio'] ?? 1.0), 4);
        $importeDestino = isset($data['importe_destino'])
            ? round((float) $data['importe_destino'], 2)
            : round($importeOrigen * $tipoCambio, 2);

        if ($mismaMoneda && abs($importeOrigen - $importeDestino) > 0.01) {
            throw new DomainException(sprintf(
                'TI_IMPORTES_DESBALANCEADOS: con misma moneda, importe_origen (%.2f) debe igualar importe_destino (%.2f)',
                $importeOrigen,
                $importeDestino
            ));
        }

        return DB::transaction(function () use ($data, $origen, $destino, $importeOrigen, $importeDestino, $tipoCambio) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $ti = TransferenciaInterna::create([
                'empresa_id' => $data['empresa_id'],
                'numero' => $this->proximoNumero($data['empresa_id']),
                'fecha' => $data['fecha'],
                'cuenta_origen_id' => $origen->id,
                'cuenta_destino_id' => $destino->id,
                'moneda_origen_id' => $origen->moneda_id,
                'moneda_destino_id' => $destino->moneda_id,
                'importe_origen' => $importeOrigen,
                'importe_destino' => $importeDestino,
                'tipo_cambio' => $tipoCambio,
                'estado' => TransferenciaInterna::ESTADO_PENDIENTE,
                'concepto' => $data['concepto'] ?? null,
                'creado_por_user_id' => $data['usuario_id'],
            ]);

            // Crear movimiento de egreso en origen y ingreso en destino,
            // ambos en extracto virtual del día.
            $movOrigen = $this->crearMovVirtual($origen, $data['fecha'], $data['usuario_id'],
                concepto: sprintf('TI %s → %s', $ti->numero, $destino->codigo),
                debito: $importeOrigen,
                credito: 0,
            );
            $movDestino = $this->crearMovVirtual($destino, $data['fecha'], $data['usuario_id'],
                concepto: sprintf('TI %s ← %s', $ti->numero, $origen->codigo),
                debito: 0,
                credito: $importeDestino,
            );

            $ti->update([
                'movimiento_origen_id' => $movOrigen->id,
                'movimiento_destino_id' => $movDestino->id,
            ]);

            $this->audit->logEvento(
                accion: 'TI_REGISTRADA',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'TI %s · %s → %s · %s%.2f → %s%.2f',
                    $ti->numero,
                    $origen->codigo, $destino->codigo,
                    $origen->moneda?->codigo ?? '?', $importeOrigen,
                    $destino->moneda?->codigo ?? '?', $importeDestino
                ),
                empresaId: $data['empresa_id'],
            );

            return $ti->fresh();
        });
    }

    /**
     * Contabiliza la transferencia (RN-20). Genera UN asiento en diario BAN.
     * Si monedas difieren, imputa la diferencia en ARS a diferencia de cambio.
     */
    public function contabilizar(TransferenciaInterna $ti, User $usuario): TransferenciaInterna
    {
        if ($ti->estado === TransferenciaInterna::ESTADO_CONCILIADA) {
            throw new DomainException('TI_YA_CONCILIADA');
        }
        if ($ti->estado === TransferenciaInterna::ESTADO_ANULADA) {
            throw new DomainException('TI_ANULADA');
        }

        $origen = CuentaBancaria::with('moneda')->findOrFail($ti->cuenta_origen_id);
        $destino = CuentaBancaria::with('moneda')->findOrFail($ti->cuenta_destino_id);

        return DB::transaction(function () use ($ti, $origen, $destino, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $importeArsOrigen = $this->aArs($ti->importe_origen, $origen, $ti->fecha);
            $importeArsDestino = $this->aArs($ti->importe_destino, $destino, $ti->fecha);
            $diferencia = round($importeArsDestino - $importeArsOrigen, 2);

            $glosa = sprintf('Transferencia interna %s · %s → %s', $ti->numero, $origen->codigo, $destino->codigo);

            $movimientos = [
                ['cuenta_id' => $destino->cuenta_contable_id, 'debe' => $importeArsDestino, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => $origen->cuenta_contable_id, 'debe' => 0, 'haber' => $importeArsOrigen, 'glosa' => $glosa],
            ];

            // Si hay diferencia en ARS, agregar contrapartida a dif cambio.
            if (abs($diferencia) > 0.01) {
                $codigo = $diferencia > 0 ? self::CODIGO_DIF_CAMBIO_POS : self::CODIGO_DIF_CAMBIO_NEG;
                $cuentaDif = CuentaContable::where('empresa_id', $ti->empresa_id)->where('codigo', $codigo)->first();
                if (! $cuentaDif) {
                    throw new DomainException("CUENTA_CONTABLE_NO_ENCONTRADA: {$codigo}");
                }
                $abs = abs($diferencia);
                if ($diferencia > 0) {
                    // Ganancia → HABER
                    $movimientos[] = ['cuenta_id' => $cuentaDif->id, 'debe' => 0, 'haber' => $abs, 'glosa' => 'Diferencia de cambio TI'];
                } else {
                    // Pérdida → DEBE
                    $movimientos[] = ['cuenta_id' => $cuentaDif->id, 'debe' => $abs, 'haber' => 0, 'glosa' => 'Diferencia de cambio TI'];
                }
            }

            $movimientos = $this->completarCc($movimientos, $ti->empresa_id);

            $diarioBan = DB::table('erp_diarios')
                ->where('empresa_id', $ti->empresa_id)
                ->where('codigo', 'BAN')
                ->value('id');

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $ti->empresa_id,
                'diario_id' => $diarioBan,
                'fecha' => $ti->fecha->toDateString(),
                'glosa' => $glosa,
                'origen' => 'BANCO',
                'origen_id' => $ti->id,
                'origen_tabla' => 'erp_transferencias_internas',
                'usuario_id' => $usuario->id,
                'movimientos' => $movimientos,
            ]);
            $asiento = $this->asientoService->contabilizar($asiento);

            $ti->update([
                'asiento_id' => $asiento->id,
                'estado' => TransferenciaInterna::ESTADO_CONCILIADA,
            ]);

            // Marcar ambos movs bancarios como CONCILIADOS contra el asiento
            if ($ti->movimiento_origen_id) {
                MovimientoBancario::where('id', $ti->movimiento_origen_id)->update([
                    'estado' => 'CONCILIADO',
                    'asiento_id' => $asiento->id,
                ]);
            }
            if ($ti->movimiento_destino_id) {
                MovimientoBancario::where('id', $ti->movimiento_destino_id)->update([
                    'estado' => 'CONCILIADO',
                    'asiento_id' => $asiento->id,
                ]);
            }

            $this->audit->logEvento(
                accion: 'TI_CONTABILIZADA',
                modulo: 'tesoreria',
                descripcion: sprintf('TI %s contabilizada · asiento %d · dif cambio ARS %.2f', $ti->numero, $asiento->id, $diferencia),
                empresaId: $ti->empresa_id,
            );

            return $ti->fresh();
        });
    }

    public function anular(TransferenciaInterna $ti, string $motivo, User $usuario): TransferenciaInterna
    {
        if ($ti->estado === TransferenciaInterna::ESTADO_CONCILIADA) {
            throw new DomainException('TI_CONCILIADA: contraasentar antes de anular');
        }
        if ($ti->estado === TransferenciaInterna::ESTADO_ANULADA) {
            throw new DomainException('TI_YA_ANULADA');
        }

        return DB::transaction(function () use ($ti, $motivo, $usuario) {
            // Eliminar los movimientos virtuales creados al registrar (aún no
            // conciliados). Si ya están CONCILIADOS no deberíamos llegar acá.
            if ($ti->movimiento_origen_id) {
                MovimientoBancario::where('id', $ti->movimiento_origen_id)
                    ->where('estado', 'PENDIENTE')
                    ->delete();
            }
            if ($ti->movimiento_destino_id) {
                MovimientoBancario::where('id', $ti->movimiento_destino_id)
                    ->where('estado', 'PENDIENTE')
                    ->delete();
            }

            $ti->update(['estado' => TransferenciaInterna::ESTADO_ANULADA]);

            $this->audit->logEvento(
                accion: 'TI_ANULADA',
                modulo: 'tesoreria',
                descripcion: sprintf('TI %s anulada: %s', $ti->numero, $motivo),
                empresaId: $ti->empresa_id,
            );

            return $ti->fresh();
        });
    }

    private function aArs(float|string $importe, CuentaBancaria $cuenta, Carbon|string $fecha): float
    {
        $importe = (float) $importe;
        $moneda = $cuenta->moneda?->codigo;
        if (! $moneda || $moneda === 'ARS') {
            return $importe;
        }

        $fechaStr = $fecha instanceof Carbon ? $fecha->toDateString() : (string) $fecha;

        $cot = DB::table('erp_cotizaciones as c')
            ->join('erp_monedas as m', 'm.id', '=', 'c.moneda_id')
            ->where('c.empresa_id', $cuenta->empresa_id)
            ->where('m.codigo', $moneda)
            ->where('c.tipo', 'OFICIAL')
            ->where('c.fecha', '<=', $fechaStr)
            ->orderByDesc('c.fecha')
            ->value('c.valor_referencia');

        if ($cot === null) {
            throw new DomainException("SIN_COTIZACION: no hay cotización {$moneda} OFICIAL para {$fechaStr}");
        }

        return round($importe * (float) $cot, 2);
    }

    private function crearMovVirtual(CuentaBancaria $cuenta, string $fecha, int $usuarioId, string $concepto, float $debito, float $credito): MovimientoBancario
    {
        $hashVirtual = hash('sha256', "ti|{$cuenta->id}|{$fecha}|".uniqid());

        $extracto = ExtractoBancario::firstOrCreate(
            ['hash_archivo' => hash('sha256', "manual|{$cuenta->id}|{$fecha}")],
            [
                'cuenta_bancaria_id' => $cuenta->id,
                'fecha_desde' => $fecha,
                'fecha_hasta' => $fecha,
                'nombre_archivo' => "Manual · {$fecha}",
                'cant_movimientos' => 0,
                'importado_por_user_id' => $usuarioId,
                'importado_at' => now(),
                'observaciones' => 'Extracto virtual para TI y cargas manuales.',
            ]
        );

        return MovimientoBancario::create([
            'extracto_id' => $extracto->id,
            'cuenta_bancaria_id' => $cuenta->id,
            'fecha' => $fecha,
            'concepto' => $concepto,
            'debito' => $debito,
            'credito' => $credito,
            'estado' => 'PENDIENTE',
            'hash_linea' => $hashVirtual,
        ]);
    }

    private function proximoNumero(int $empresaId): string
    {
        $ultimo = DB::table('erp_transferencias_internas')
            ->where('empresa_id', $empresaId)
            ->orderByDesc('id')
            ->value('numero');

        $n = 1;
        if ($ultimo && preg_match('/TI-\d{4}-(\d+)/', $ultimo, $match)) {
            $n = ((int) $match[1]) + 1;
        }

        return sprintf('TI-%s-%06d', date('Y'), $n);
    }

    /**
     * @param  array<int, array<string, mixed>>  $movs
     * @return array<int, array<string, mixed>>
     */
    private function completarCc(array $movs, int $empresaId): array
    {
        // Mini-tanda 2026-07-13 bug 1: resolver unificado (CENTRAL→GENERAL).
        $ccFallback = \App\Erp\Models\CentroCosto::operativoId($empresaId);

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

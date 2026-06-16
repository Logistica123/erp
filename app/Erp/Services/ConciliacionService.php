<?php

namespace App\Erp\Services;

use App\Erp\Models\Tesoreria\Cobro;
use App\Erp\Models\Tesoreria\CobroMedio;
use App\Erp\Models\Tesoreria\Conciliacion;
use App\Erp\Models\Tesoreria\Echeq;
use App\Erp\Models\Tesoreria\MotivoIgnorado;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Models\Tesoreria\TransferenciaInterna;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Conciliación polimórfica de movimientos bancarios (SPEC 02 RN-14/21/26).
 *
 * RN-14: un movimiento puede conciliarse contra N referencias (1-a-N) pero
 *        una misma referencia no puede estar en múltiples movimientos (N-a-1
 *        prohibido). Al crear el registro validamos que referencia no esté
 *        ya usada.
 *
 * RN-21: al conciliar, el movimiento pasa a CONCILIADO y sus campos son
 *        readonly. Para modificar hay que DESCONCILIAR primero.
 *
 * RN-26: marcar IGNORADO requiere motivo_ignorado_id del catálogo
 *        erp_motivos_ignorado.
 *
 * Tipos de referencia soportados:
 *   · ORDEN_PAGO            → OrdenPago (al conciliar, OP pasa a PAGADA)
 *   · COBRO                 → Cobro     (al conciliar, el medio del cobro
 *                                        pasa a ACREDITADO y el cobro a
 *                                        ACREDITADO / PARCIAL_ACREDITADO)
 *   · TRANSFERENCIA_INTERNA → TI        (solo linkea; contabilizar() de TI
 *                                        es lo que cierra el ciclo)
 *   · ASIENTO_MANUAL        → Asiento   (para imputaciones libres)
 *   · ECHEQ                 → Echeq     (dispara EcheqService::acreditar
 *                                        vía ruta separada)
 *   · REGLA_AUTO            → ConciliacionRegla (auto-imputación)
 */
class ConciliacionService
{
    public function __construct(
        private readonly MovimientoBancarioService $movService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Concilia un movimiento bancario contra una referencia concreta.
     * Devuelve el MovimientoBancario actualizado. Si el movimiento queda
     * totalmente cubierto (1-a-1) pasa a CONCILIADO; 1-a-N soportado via
     * importe_conciliado parcial.
     *
     * @param  array{
     *   referencia_tipo:string,
     *   referencia_id:int,
     *   importe_conciliado?:float|null,
     *   cuenta_contable_contraparte_id?:?int,
     *   auxiliar_id?:?int,
     *   centro_costo_id?:?int,
     *   glosa?:?string,
     *   modo?:string,
     *   observacion?:?string,
     * }  $data
     */
    public function conciliar(MovimientoBancario $mov, array $data, User $usuario): MovimientoBancario
    {
        $tipo = strtoupper($data['referencia_tipo']);
        $refId = (int) $data['referencia_id'];

        if (! in_array($tipo, [
            Conciliacion::REF_ORDEN_PAGO,
            Conciliacion::REF_COBRO,
            Conciliacion::REF_TRANSFERENCIA_INTERNA,
            Conciliacion::REF_ASIENTO_MANUAL,
            Conciliacion::REF_ECHEQ,
            Conciliacion::REF_REGLA_AUTO,
        ], true)) {
            throw new DomainException("REFERENCIA_TIPO_INVALIDA: {$tipo}");
        }

        if ($mov->estado === MovimientoBancario::ESTADO_IGNORADO) {
            throw new DomainException('MOVIMIENTO_IGNORADO: reabrir antes de conciliar (RN-21)');
        }

        // RN-14: un origen (ej. OP #123) NO puede estar conciliado en más de
        // un movimiento al mismo tiempo. Validamos.
        $yaExiste = Conciliacion::where('referencia_tipo', $tipo)
            ->where('referencia_id', $refId)
            ->whereHas('movimientoBancario', fn ($q) => $q->where('estado', MovimientoBancario::ESTADO_CONCILIADO))
            ->exists();
        if ($yaExiste) {
            throw new DomainException(sprintf(
                'RN-14: %s #%d ya está conciliado en otro movimiento — si la partida viene fraccionada, usá múltiples OP/Cobros.',
                $tipo,
                $refId
            ));
        }

        return DB::transaction(function () use ($mov, $tipo, $refId, $data, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $importeMov = (float) max($mov->debito, $mov->credito);
            $importeConciliado = round(
                (float) ($data['importe_conciliado'] ?? $importeMov),
                2
            );

            // Según el tipo, resolvemos la contrapartida contable y aplicamos
            // efectos colaterales sobre el origen.
            [$cuentaContraparteId, $auxiliarId, $glosa] = $this->resolverContraparte(
                $mov, $tipo, $refId, $data, $usuario
            );

            // Generar asiento contable vía MovimientoBancarioService (reusa
            // su lógica de DEBE/HABER según naturaleza del mov bancario).
            if ($cuentaContraparteId !== null) {
                $mov = $this->movService->conciliar(
                    mov: $mov,
                    cuentaContableContraparteId: $cuentaContraparteId,
                    usuarioId: $usuario->id,
                    centroCostoId: $data['centro_costo_id'] ?? null,
                    auxiliarId: $auxiliarId,
                    glosa: $data['glosa'] ?? $glosa,
                );
            }

            // Registrar la conciliación polimórfica (trazabilidad RN-14).
            Conciliacion::create([
                'movimiento_bancario_id' => $mov->id,
                'referencia_tipo' => $tipo,
                'referencia_id' => $refId,
                'importe_conciliado' => $importeConciliado,
                'user_id' => $usuario->id,
                'modo' => $data['modo'] ?? Conciliacion::MODO_MANUAL,
                'observacion' => $data['observacion'] ?? null,
            ]);

            // Efectos colaterales por tipo de referencia.
            $this->aplicarEfectosColaterales($tipo, $refId, $mov, $importeConciliado);

            $this->audit->logEvento(
                accion: 'MOVIMIENTO_CONCILIADO',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'Mov #%d conciliado contra %s #%d · importe $%s',
                    $mov->id, $tipo, $refId, number_format($importeConciliado, 2, ',', '.')
                ),
                empresaId: $mov->cuentaBancaria?->empresa_id,
            );

            return $mov->fresh(['asiento', 'cuentaBancaria']);
        });
    }

    /**
     * Desconcilia un movimiento: revierte estado a ETIQUETADO/PENDIENTE,
     * elimina los registros de erp_conciliaciones, anula el asiento (si hay).
     */
    public function desconciliar(MovimientoBancario $mov, string $motivo, User $usuario): MovimientoBancario
    {
        if ($mov->estado !== MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_NO_CONCILIADO: estado actual '.$mov->estado);
        }

        return DB::transaction(function () use ($mov, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $conciliaciones = Conciliacion::where('movimiento_bancario_id', $mov->id)->get();
            $refs = $conciliaciones->map(fn ($c) => $c->referencia_tipo.'#'.$c->referencia_id)->implode(', ');

            // Revertir efectos colaterales por tipo.
            foreach ($conciliaciones as $c) {
                $this->revertirEfectosColaterales($c->referencia_tipo, $c->referencia_id);
            }

            // Anular asiento (reversa) si lo tenía.
            if ($mov->asiento_id) {
                /** @var AsientoService $asientoSvc */
                $asientoSvc = app(AsientoService::class);
                $asiento = \App\Erp\Models\Asiento::find($mov->asiento_id);
                if ($asiento && $asiento->estado === \App\Erp\Models\Asiento::ESTADO_CONTABILIZADO) {
                    $asientoSvc->anular($asiento, $usuario->id, "Desconciliación mov #{$mov->id}: {$motivo}");
                }
            }

            Conciliacion::where('movimiento_bancario_id', $mov->id)->delete();

            $mov->update([
                'estado' => $mov->cuenta_contable_propuesta_id
                    ? MovimientoBancario::ESTADO_ETIQUETADO
                    : MovimientoBancario::ESTADO_PENDIENTE,
                'asiento_id' => null,
                'observacion' => trim(($mov->observacion ?? '').' · DESCONCILIADO: '.$motivo),
            ]);

            $this->audit->logEvento(
                accion: 'MOVIMIENTO_DESCONCILIADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Mov #%d desconciliado (%s) motivo: %s', $mov->id, $refs, $motivo),
                empresaId: $mov->cuentaBancaria?->empresa_id,
            );

            return $mov->fresh();
        });
    }

    /**
     * RN-26: marcar movimiento como IGNORADO requiere motivo_ignorado_id
     * del catálogo erp_motivos_ignorado + opcional observación.
     */
    public function ignorar(MovimientoBancario $mov, int $motivoIgnoradoId, ?string $observacion, User $usuario): MovimientoBancario
    {
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_CONCILIADO: desconciliá antes de ignorar');
        }

        $motivo = MotivoIgnorado::where('id', $motivoIgnoradoId)->where('activo', true)->first();
        if (! $motivo) {
            throw new DomainException('MOTIVO_IGNORADO_INVALIDO: id='.$motivoIgnoradoId);
        }

        $mov->update([
            'estado' => MovimientoBancario::ESTADO_IGNORADO,
            'motivo_ignorado_id' => $motivo->id,
            'observacion' => $observacion,
        ]);

        $this->audit->logEvento(
            accion: 'MOVIMIENTO_IGNORADO',
            modulo: 'tesoreria',
            descripcion: sprintf('Mov #%d ignorado · motivo %s', $mov->id, $motivo->codigo),
            empresaId: $mov->cuentaBancaria?->empresa_id,
        );

        return $mov->fresh();
    }

    /**
     * v1.27 Sprint A — Conciliación directa para tipos automáticos
     * (COMISION_BANCARIA, IMPUESTO_DEBITO_CREDITO, INTERES_GANADO).
     *
     * Usa `erp_banco_config` para resolver la cuenta contrapartida. El
     * operador concilia con 1 click sin elegir factura.
     *
     * Caso COMISION_BANCARIA / IMPUESTO_DEBITO_CREDITO:
     *   movimiento es débito en banco → Debe Gasto / Haber Banco
     * Caso INTERES_GANADO:
     *   movimiento es crédito en banco → Debe Banco / Haber Resultado
     */
    public function conciliarDirecto(MovimientoBancario $mov, User $usuario): MovimientoBancario
    {
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_YA_CONCILIADO');
        }
        $tipoAuto = $mov->tipo_operativo;
        if (! in_array($tipoAuto, ['COMISION_BANCARIA', 'IMPUESTO_DEBITO_CREDITO', 'INTERES_GANADO'], true)) {
            throw new DomainException("TIPO_NO_AUTO: el movimiento es {$tipoAuto}, no se concilia directo. Usa el flujo contra factura.");
        }

        $cfg = \App\Erp\Models\Tesoreria\BancoConfig::where('cuenta_bancaria_id', $mov->cuenta_bancaria_id)->first();
        if (! $cfg) {
            throw new DomainException("BANCO_CONFIG_AUSENTE: configurá las cuentas contables del banco en /erp/tesoreria/extracto-config.");
        }
        $contrapartidaId = match ($tipoAuto) {
            'COMISION_BANCARIA' => $cfg->cuenta_gastos_bancarios_id,
            'IMPUESTO_DEBITO_CREDITO' => $cfg->cuenta_imp_debito_credito_id,
            'INTERES_GANADO' => $cfg->cuenta_intereses_ganados_id,
        };

        $cuentaBanco = $mov->cuentaBancaria;
        $cuentaBancoContableId = $cuentaBanco->cuenta_contable_id;
        $empresaId = $cuentaBanco->empresa_id;
        $monto = (float) max($mov->debito, $mov->credito);
        if ($monto <= 0) {
            throw new DomainException("MOVIMIENTO_SIN_IMPORTE");
        }

        return DB::transaction(function () use ($mov, $usuario, $tipoAuto, $contrapartidaId, $cuentaBancoContableId, $empresaId, $monto) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            // Si es egreso del banco (debito > 0): Debe contrapartida / Haber banco.
            // Si es ingreso al banco (credito > 0): Debe banco / Haber contrapartida.
            $esEgreso = (float) $mov->debito > 0.005;
            $movsAsiento = [
                [
                    'cuenta_id' => $esEgreso ? $contrapartidaId : $cuentaBancoContableId,
                    'centro_costo_id' => null,
                    'auxiliar_id' => null,
                    'debe' => $monto,
                    'haber' => 0,
                    'glosa' => $tipoAuto.' — '.($mov->concepto ?? ''),
                ],
                [
                    'cuenta_id' => $esEgreso ? $cuentaBancoContableId : $contrapartidaId,
                    'centro_costo_id' => null,
                    'auxiliar_id' => null,
                    'debe' => 0,
                    'haber' => $monto,
                    'glosa' => $tipoAuto.' — '.($mov->concepto ?? ''),
                ],
            ];

            $diarioBan = DB::table('erp_diarios')
                ->where('empresa_id', $empresaId)->where('codigo', 'BAN')
                ->value('id')
                ?? DB::table('erp_diarios')
                    ->where('empresa_id', $empresaId)->where('codigo', 'GEN')
                    ->value('id');
            if (! $diarioBan) {
                throw new DomainException('DIARIO_BAN_INEXISTENTE: creá un diario con código BAN o GEN.');
            }

            $asientoSvc = app(AsientoService::class);
            $asiento = $asientoSvc->crearBorrador([
                'empresa_id' => $empresaId,
                'diario_id' => $diarioBan,
                'fecha' => $mov->fecha,
                'concepto' => "Conciliación directa {$tipoAuto} mov #{$mov->id}",
                'movimientos' => $movsAsiento,
                'user_id' => $usuario->id,
            ]);
            $asiento = $asientoSvc->contabilizar($asiento);

            // Registro en erp_conciliaciones (referencia ASIENTO_MANUAL).
            Conciliacion::create([
                'movimiento_bancario_id' => $mov->id,
                'referencia_tipo' => 'ASIENTO_MANUAL',
                'referencia_id' => $asiento->id,
                'importe_conciliado' => $monto,
                'user_id' => $usuario->id,
                'modo' => 'MANUAL',
                'observacion' => "Conciliación directa tipo {$tipoAuto}",
            ]);

            $mov->update([
                'estado' => MovimientoBancario::ESTADO_CONCILIADO,
                'asiento_id' => $asiento->id,
                'monto_conciliado' => $monto,
            ]);

            $this->audit->logEvento(
                accion: 'MOVIMIENTO_CONCILIADO_DIRECTO',
                modulo: 'tesoreria',
                descripcion: sprintf('Mov #%d (%s) conciliado directo → asiento #%d',
                    $mov->id, $tipoAuto, $asiento->id),
                empresaId: $empresaId,
            );

            return $mov->fresh(['asiento', 'cuentaBancaria']);
        });
    }

    /**
     * v1.27 §15 — Sugiere top-N facturas usando matching por CUIT primero.
     *
     * Algoritmo:
     *  1. Extraer CUIT del concepto del movimiento (con validación DV).
     *  2. Si hay CUIT → buscar contraparte (auxiliar.cuit). Si existe:
     *     filtrar facturas de ese auxiliar + ordenar por proximidad monto.
     *  3. Si CUIT no detectado o sin facturas pendientes → fallback puro
     *     por monto con flag `motivo_fallback`.
     *
     * Devuelve estructura enriquecida:
     *   - sugerencias: array de facturas con campo cuit_coincide.
     *   - cuit_detectado: el CUIT extraído (o null).
     *   - contraparte: ['id', 'nombre', 'cuit'] si se mapeó (o null).
     *   - motivo_fallback: null si todo OK, sino código (ej CUIT_NO_REGISTRADO).
     */
    public function sugerirFacturas(MovimientoBancario $mov, int $top = 10): array
    {
        // Wrap del método legacy para mantener compat: si el caller quiere
        // solo el array de sugerencias, lo extrae con ['sugerencias'].
        $resultado = $this->sugerirFacturasConMatchingCuit($mov, $top);
        return $resultado['sugerencias'];
    }

    /**
     * v1.27 §15 — Versión enriquecida con metadata del matching.
     */
    public function sugerirFacturasConMatchingCuit(MovimientoBancario $mov, int $top = 10): array
    {
        $monto = (float) max($mov->debito, $mov->credito);
        if ($monto <= 0.005) {
            return ['sugerencias' => [], 'cuit_detectado' => null, 'contraparte' => null,
                'motivo_fallback' => 'MOV_SIN_IMPORTE'];
        }

        $cuenta = $mov->cuentaBancaria;
        $empresaId = $cuenta?->empresa_id ?? 1;
        $tipo = $mov->tipo_operativo;

        $buscarVenta = in_array($tipo, ['TRANSFERENCIA_RECIBIDA', 'DEPOSITO', 'OTRO'], true)
            && (float) $mov->credito > 0;
        $buscarCompra = in_array($tipo, ['TRANSFERENCIA_ENVIADA', 'PAGO_SERVICIO', 'OTRO'], true)
            && (float) $mov->debito > 0;

        if (! $buscarVenta && ! $buscarCompra) {
            return ['sugerencias' => [], 'cuit_detectado' => null, 'contraparte' => null,
                'motivo_fallback' => 'TIPO_SIN_FACTURAS'];
        }

        // v1.47.1 Bug #2 — Paso 1: usar el CUIT que ya resolvió el parser
        // (columna `Nro doc` del CSV → cuit_contraparte) ANTES de re-extraer del
        // concepto. El concepto sólo es fallback.
        $cuitDetectado = preg_replace('/[^0-9]/', '', (string) ($mov->cuit_contraparte ?? ''));
        if (! $cuitDetectado || strlen($cuitDetectado) !== 11) {
            $cuitDetectado = $this->extraerCuit($mov->concepto ?? '');
        }

        // §15 — Paso 2: si CUIT detectado, buscar contraparte.
        $contraparte = null;
        if ($cuitDetectado) {
            $auxiliarRow = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('cuit', $cuitDetectado)
                ->first(['id', 'nombre', 'cuit', 'tipo']);
            if ($auxiliarRow) {
                $contraparte = [
                    'id' => $auxiliarRow->id,
                    'nombre' => $auxiliarRow->nombre,
                    'cuit' => $auxiliarRow->cuit,
                    'tipo' => $auxiliarRow->tipo,
                ];
            }
        }

        // Si tenemos contraparte, filtrar facturas SOLO de ese auxiliar.
        if ($contraparte) {
            $sugerencias = $this->buscarFacturasDe($contraparte['id'], $buscarVenta, $buscarCompra,
                $empresaId, $monto, $top);
            $sugerencias = $this->aplicarScoreCompuesto($sugerencias, $cuitDetectado, $monto);

            if (! empty($sugerencias)) {
                return [
                    'sugerencias' => $sugerencias,
                    'cuit_detectado' => $cuitDetectado,
                    'contraparte' => $contraparte,
                    'motivo_fallback' => null,
                ];
            }
            // CUIT match pero sin facturas pendientes.
            return [
                'sugerencias' => [],
                'cuit_detectado' => $cuitDetectado,
                'contraparte' => $contraparte,
                'motivo_fallback' => 'CONTRAPARTE_SIN_FACTURAS_PENDIENTES',
            ];
        }

        // §15 — Paso 3: fallback puro por monto.
        $sugerencias = $this->buscarFacturasPorMonto($buscarVenta, $buscarCompra,
            $empresaId, $monto, $top);
        // v1.47.1 Bug #3 — score compuesto: aunque sea fallback por monto, si
        // alguna factura comparte el CUIT del mov sube; las que no, topean 50%.
        $sugerencias = $this->aplicarScoreCompuesto($sugerencias, $cuitDetectado, $monto);

        return [
            'sugerencias' => $sugerencias,
            'cuit_detectado' => $cuitDetectado,
            'contraparte' => null,
            'motivo_fallback' => $cuitDetectado
                ? 'CUIT_NO_REGISTRADO'
                : 'CUIT_NO_DETECTADO_EN_CONCEPTO',
        ];
    }

    /**
     * §15 — Extrae el primer CUIT/CUIL válido del concepto.
     * Acepta formato con o sin guiones. Valida dígito verificador.
     */
    private function extraerCuit(string $concepto): ?string
    {
        // Formato con guiones: XX-XXXXXXXX-X.
        if (preg_match('/\b(\d{2})-?(\d{8})-?(\d{1})\b/', $concepto, $m)) {
            $candidato = $m[1].$m[2].$m[3];
            if ($this->validarDvCuit($candidato)) return $candidato;
        }
        // Formato sin guiones: 11 dígitos seguidos. Iteramos por si hay varios.
        if (preg_match_all('/\b(\d{11})\b/', $concepto, $mm)) {
            foreach ($mm[1] as $candidato) {
                if ($this->validarDvCuit($candidato)) return $candidato;
            }
        }
        return null;
    }

    private function validarDvCuit(string $cuit): bool
    {
        if (strlen($cuit) !== 11) return false;
        $factores = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $suma = 0;
        for ($i = 0; $i < 10; $i++) {
            $suma += (int) $cuit[$i] * $factores[$i];
        }
        $resto = $suma % 11;
        $dv = $resto === 0 ? 0 : ($resto === 1 ? 9 : 11 - $resto);
        return $dv === (int) $cuit[10];
    }

    private function buscarFacturasDe(int $auxiliarId, bool $venta, bool $compra,
        int $empresaId, float $monto, int $top): array
    {
        $result = [];
        if ($venta) {
            $facturas = DB::table('erp_facturas_venta as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
                ->where('f.empresa_id', $empresaId)
                ->whereNull('f.deleted_at')
                ->where('f.auxiliar_id', $auxiliarId)
                ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'])
                ->select('f.id', 'f.numero', 'f.punto_venta_id', 'f.imp_total',
                    'f.fecha_emision', 'f.auxiliar_id', 'a.nombre as cliente_nombre', 'a.cuit',
                    'tc.codigo_interno as tipo_codigo', 'tc.letra')
                ->orderByRaw('ABS(f.imp_total - ?)', [$monto])
                ->limit($top)
                ->get();
            foreach ($facturas as $f) {
                $result[] = $this->formatVenta($f, $monto);
            }
        }
        if ($compra) {
            $facturas = DB::table('erp_facturas_compra as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
                ->where('f.empresa_id', $empresaId)
                ->whereNull('f.deleted_at')
                ->where('f.auxiliar_id', $auxiliarId)
                ->whereIn('f.estado', ['RECIBIDA', 'CONTROLADA', 'PAGO_PARCIAL'])
                ->select('f.id', 'f.numero', 'f.punto_venta', 'f.imp_total',
                    'f.fecha_emision', 'f.cuit_emisor', 'f.razon_social_emisor', 'f.auxiliar_id',
                    'tc.codigo_interno as tipo_codigo', 'tc.letra')
                ->orderByRaw('ABS(f.imp_total - ?)', [$monto])
                ->limit($top)
                ->get();
            foreach ($facturas as $f) {
                $result[] = $this->formatCompra($f, $monto);
            }
        }
        usort($result, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($result, 0, $top);
    }

    private function buscarFacturasPorMonto(bool $venta, bool $compra,
        int $empresaId, float $monto, int $top): array
    {
        $result = [];
        if ($venta) {
            $facturas = DB::table('erp_facturas_venta as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
                ->where('f.empresa_id', $empresaId)
                ->whereNull('f.deleted_at')
                ->whereIn('f.estado', ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'])
                ->select('f.id', 'f.numero', 'f.punto_venta_id', 'f.imp_total',
                    'f.fecha_emision', 'f.auxiliar_id', 'a.nombre as cliente_nombre', 'a.cuit',
                    'tc.codigo_interno as tipo_codigo', 'tc.letra')
                ->orderByRaw('ABS(f.imp_total - ?)', [$monto])
                ->limit($top * 2)
                ->get();
            foreach ($facturas as $f) {
                $result[] = $this->formatVenta($f, $monto);
            }
        }
        if ($compra) {
            $facturas = DB::table('erp_facturas_compra as f')
                ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
                ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
                ->where('f.empresa_id', $empresaId)
                ->whereNull('f.deleted_at')
                ->whereIn('f.estado', ['RECIBIDA', 'CONTROLADA', 'PAGO_PARCIAL'])
                ->select('f.id', 'f.numero', 'f.punto_venta', 'f.imp_total',
                    'f.fecha_emision', 'f.cuit_emisor', 'f.razon_social_emisor', 'f.auxiliar_id',
                    'tc.codigo_interno as tipo_codigo', 'tc.letra')
                ->orderByRaw('ABS(f.imp_total - ?)', [$monto])
                ->limit($top * 2)
                ->get();
            foreach ($facturas as $f) {
                $result[] = $this->formatCompra($f, $monto);
            }
        }
        usort($result, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($result, 0, $top);
    }

    /**
     * v1.47.1 Bug #3 — Score compuesto CUIT + monto.
     *   - 100% sólo si CUIT coincide Y monto exacto (±$0.01).
     *   - CUIT coincide (monto inexacto) → score por proximidad, tope 95.
     *   - CUIT NO coincide → tope 50 + flag cuit_no_coincide (badge rojo).
     * Reordena: primero las que coinciden por CUIT, luego por score desc.
     *
     * @param  array<int,array<string,mixed>>  $sugerencias
     * @return array<int,array<string,mixed>>
     */
    private function aplicarScoreCompuesto(array $sugerencias, ?string $cuitMov, float $monto): array
    {
        $cuitMovNorm = $cuitMov ? preg_replace('/[^0-9]/', '', $cuitMov) : null;
        foreach ($sugerencias as &$s) {
            $cuitFac = isset($s['cuit']) ? preg_replace('/[^0-9]/', '', (string) $s['cuit']) : '';
            $coincide = $cuitMovNorm && $cuitFac && $cuitFac === $cuitMovNorm;
            $saldo = (float) ($s['saldo_pendiente'] ?? $s['imp_total'] ?? 0);
            $exacto = abs($saldo - $monto) < 0.01;
            $base = (int) ($s['score'] ?? 0); // proximidad de monto 0-100

            if ($coincide && $exacto) {
                $s['score'] = 100;
            } elseif ($coincide) {
                $s['score'] = min($base, 95);
            } else {
                $s['score'] = min($base, 50);
            }
            $s['cuit_coincide'] = (bool) $coincide;
            $s['cuit_no_coincide'] = $cuitMovNorm ? ! $coincide : false;
        }
        unset($s);

        usort($sugerencias, function ($a, $b) {
            if ($a['cuit_coincide'] !== $b['cuit_coincide']) {
                return $a['cuit_coincide'] ? -1 : 1;
            }
            return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
        });
        return $sugerencias;
    }

    private function formatVenta($f, float $monto): array
    {
        $proximidad = $monto > 0 ? 1 - min(1, abs((float) $f->imp_total - $monto) / $monto) : 0;
        return [
            'tipo' => 'FACTURA_VENTA',
            'factura_id' => $f->id,
            'numero' => $f->numero,
            'pv_id' => $f->punto_venta_id,
            'tipo_codigo' => $f->tipo_codigo,
            'letra' => $f->letra,
            'cliente_id' => $f->auxiliar_id,
            'cliente_nombre' => $f->cliente_nombre,
            'cuit' => $f->cuit ?? null,
            'imp_total' => (float) $f->imp_total,
            'saldo_pendiente' => (float) $f->imp_total,
            'fecha_emision' => $f->fecha_emision,
            'score' => round($proximidad * 100),
        ];
    }

    private function formatCompra($f, float $monto): array
    {
        $proximidad = $monto > 0 ? 1 - min(1, abs((float) $f->imp_total - $monto) / $monto) : 0;
        return [
            'tipo' => 'FACTURA_COMPRA',
            'factura_id' => $f->id,
            'numero' => $f->numero,
            'pv' => $f->punto_venta,
            'tipo_codigo' => $f->tipo_codigo,
            'letra' => $f->letra,
            'cuit' => $f->cuit_emisor,
            'proveedor_nombre' => $f->razon_social_emisor,
            'proveedor_id' => $f->auxiliar_id,
            'imp_total' => (float) $f->imp_total,
            'saldo_pendiente' => (float) $f->imp_total,
            'fecha_emision' => $f->fecha_emision,
            'score' => round($proximidad * 100),
        ];
    }

    /**
     * v1.27 Sprint C + §15 — Conciliar movimiento bancario contra una factura.
     * Crea asiento de cobro (venta) o pago (compra) + registro en
     * erp_conciliaciones. Soporta conciliación parcial.
     *
     * §15: el parámetro $motivo es opcional. Si se proporciona, marca la
     * conciliación como MANUAL en `observacion` (típico cuando el operador
     * concilia contra una factura que NO matcheaba por CUIT — debe
     * justificar la decisión).
     */
    public function conciliarContraFactura(MovimientoBancario $mov, string $tipoFactura, int $facturaId, float $monto, User $usuario, ?string $motivo = null): MovimientoBancario
    {
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_YA_CONCILIADO');
        }
        if (! in_array($tipoFactura, ['VENTA', 'COMPRA'], true)) {
            throw new DomainException('TIPO_FACTURA_INVALIDO');
        }
        if ($monto <= 0) throw new DomainException('MONTO_INVALIDO');

        $cuentaBanco = $mov->cuentaBancaria;
        $empresaId = $cuentaBanco->empresa_id;
        $montoMov = (float) max($mov->debito, $mov->credito);
        if ($monto > ($montoMov - (float) $mov->monto_conciliado) + 0.005) {
            throw new DomainException('MONTO_EXCEDE_SALDO_MOVIMIENTO');
        }

        return DB::transaction(function () use ($mov, $tipoFactura, $facturaId, $monto, $usuario, $cuentaBanco, $empresaId, $montoMov, $motivo) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $tabla = $tipoFactura === 'VENTA' ? 'erp_facturas_venta' : 'erp_facturas_compra';
            $factura = DB::table($tabla)
                ->where('id', $facturaId)->where('empresa_id', $empresaId)
                ->whereNull('deleted_at')->first();
            if (! $factura) {
                throw new DomainException("FACTURA_NO_ENCONTRADA: {$tipoFactura} #{$facturaId}");
            }

            $auxiliarId = $factura->auxiliar_id;
            if (! $auxiliarId) throw new DomainException('FACTURA_SIN_AUXILIAR');

            $cuentaAux = DB::table('erp_auxiliares')->where('id', $auxiliarId)->value('cuenta_contable_default_id');
            if (! $cuentaAux) {
                // Fallback: 1.1.4.01 (Deudores) o 2.1.1.01 (Proveedores).
                $cuentaAux = DB::table('erp_cuentas_contables')
                    ->where('empresa_id', $empresaId)
                    ->where('codigo', $tipoFactura === 'VENTA' ? '1.1.4.01' : '2.1.1.01')
                    ->value('id');
            }
            if (! $cuentaAux) throw new DomainException('CUENTA_CONTABLE_AUX_NO_ENCONTRADA');

            $cuentaBancoContable = $cuentaBanco->cuenta_contable_id;
            $diarioBan = DB::table('erp_diarios')
                ->where('empresa_id', $empresaId)->where('codigo', 'BAN')->value('id')
                ?? DB::table('erp_diarios')
                    ->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
            if (! $diarioBan) throw new DomainException('DIARIO_BAN_INEXISTENTE');

            // Asiento de cobro (venta): Debe Banco / Haber Deudor cliente.
            // Asiento de pago (compra): Debe Proveedor / Haber Banco.
            $debeBanco = $tipoFactura === 'VENTA';
            $glosa = ($tipoFactura === 'VENTA' ? 'Cobro factura venta #' : 'Pago factura compra #').$factura->numero;
            $movsAsiento = [
                [
                    'cuenta_id' => $debeBanco ? $cuentaBancoContable : $cuentaAux,
                    'auxiliar_id' => $debeBanco ? null : $auxiliarId,
                    'centro_costo_id' => null,
                    'debe' => $monto, 'haber' => 0, 'glosa' => $glosa,
                ],
                [
                    'cuenta_id' => $debeBanco ? $cuentaAux : $cuentaBancoContable,
                    'auxiliar_id' => $debeBanco ? $auxiliarId : null,
                    'centro_costo_id' => null,
                    'debe' => 0, 'haber' => $monto, 'glosa' => $glosa,
                ],
            ];

            $asientoSvc = app(AsientoService::class);
            $asiento = $asientoSvc->crearBorrador([
                'empresa_id' => $empresaId, 'diario_id' => $diarioBan,
                'fecha' => $mov->fecha, 'concepto' => $glosa,
                'movimientos' => $movsAsiento, 'user_id' => $usuario->id,
            ]);
            $asiento = $asientoSvc->contabilizar($asiento);

            // Crear registro de conciliación (referencia_tipo polimórfico).
            $refTipo = $tipoFactura === 'VENTA' ? 'COBRO' : 'ORDEN_PAGO';
            // No tenemos un Cobro/OP intermediario per se, pero el referencia_id
            // apunta a la factura. Para Sprint C usamos ASIENTO_MANUAL para
            // simplificar (la factura queda vinculada vía glosa + monto).
            $obsBase = sprintf('Conciliación contra %s #%d (auxiliar #%d)',
                $tipoFactura, $facturaId, $auxiliarId);
            if ($motivo !== null && $motivo !== '') {
                $obsBase .= ' · [MANUAL] '.$motivo;
            }
            Conciliacion::create([
                'movimiento_bancario_id' => $mov->id,
                'referencia_tipo' => 'ASIENTO_MANUAL',
                'referencia_id' => $asiento->id,
                'importe_conciliado' => $monto,
                'user_id' => $usuario->id,
                'modo' => 'MANUAL',
                'observacion' => $obsBase,
            ]);

            $nuevoMontoConciliado = (float) $mov->monto_conciliado + $monto;
            $totalmenteConciliado = abs($nuevoMontoConciliado - $montoMov) < 0.01;
            // El enum estado no incluye PARCIAL — si es parcial, dejamos
            // ETIQUETADO (el monto_conciliado refleja lo cobrado/pagado).
            $mov->update([
                'estado' => $totalmenteConciliado
                    ? MovimientoBancario::ESTADO_CONCILIADO
                    : MovimientoBancario::ESTADO_ETIQUETADO,
                'asiento_id' => $totalmenteConciliado ? $asiento->id : $mov->asiento_id,
                'monto_conciliado' => $nuevoMontoConciliado,
            ]);

            $this->audit->logEvento(
                accion: 'MOVIMIENTO_CONCILIADO_FACTURA',
                modulo: 'tesoreria',
                descripcion: sprintf('Mov #%d conciliado contra %s #%d por $%.2f (asiento #%d)',
                    $mov->id, $tipoFactura, $facturaId, $monto, $asiento->id),
                empresaId: $empresaId,
            );

            return $mov->fresh(['asiento', 'cuentaBancaria']);
        });
    }

    /**
     * v1.47.2 — Concilia UN movimiento bancario contra N facturas (1:N) con un
     * único asiento consolidado. Las facturas pueden ser de distintos auxiliares
     * (flujo B manual con motivo). Soporta diferencia explícita contra una cuenta
     * de ajuste (ej. retención).
     *
     * @param  array<int,array{id:int,tipo:string,monto_imputado:float}>  $facturas
     */
    public function conciliarMultiplesFacturas(MovimientoBancario $mov, array $facturas, User $usuario, ?string $motivo = null, bool $permitirDiferencia = false, ?int $cuentaAjusteId = null, ?int $motivoDiferenciaId = null): MovimientoBancario
    {
        if ($mov->estado === MovimientoBancario::ESTADO_CONCILIADO) {
            throw new DomainException('MOVIMIENTO_YA_CONCILIADO');
        }
        if (empty($facturas)) throw new DomainException('SIN_FACTURAS');

        // v1.48 Bloque D — si viene un motivo del catálogo, resuelve la cuenta de
        // ajuste y el texto desde erp_conciliacion_motivos (pisa los sueltos).
        $motivoTipo = null;
        if ($motivoDiferenciaId) {
            $cat = DB::table('erp_conciliacion_motivos')->where('id', $motivoDiferenciaId)->where('activo', 1)->first();
            if (! $cat) throw new DomainException('MOTIVO_DIFERENCIA_INVALIDO: id='.$motivoDiferenciaId);
            $cuentaAjusteId = $cat->cuenta_ajuste_id ?: $cuentaAjusteId;
            $motivo = $cat->nombre ?: $motivo;
            $motivoTipo = $cat->tipo;
            $permitirDiferencia = true;
        }

        $cuentaBanco = $mov->cuentaBancaria;
        $empresaId = $cuentaBanco->empresa_id;
        $montoMov = (float) max($mov->debito, $mov->credito);
        $esCobro = (float) $mov->credito > 0.005;

        $sumImputado = round(array_sum(array_map(fn ($f) => (float) $f['monto_imputado'], $facturas)), 2);
        $ajuste = round($montoMov - $sumImputado, 2); // lo que sobra del mov vs lo imputado

        if (abs($ajuste) > 0.01) {
            if (! $permitirDiferencia || ! $cuentaAjusteId || ! $motivo) {
                throw new DomainException(sprintf(
                    'DIFERENCIA_NO_PERMITIDA: mov $%.2f vs facturas $%.2f (dif $%.2f). Requiere permitir_diferencia + cuenta de ajuste + motivo.',
                    $montoMov, $sumImputado, $ajuste,
                ));
            }
        }

        return DB::transaction(function () use ($mov, $facturas, $usuario, $cuentaBanco, $empresaId, $montoMov, $esCobro, $sumImputado, $ajuste, $motivo, $cuentaAjusteId, $motivoDiferenciaId, $motivoTipo) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $diarioBan = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'BAN')->value('id')
                ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
            if (! $diarioBan) throw new DomainException('DIARIO_BAN_INEXISTENTE');

            $lineasFactura = [];
            foreach ($facturas as $f) {
                if (! in_array($f['tipo'], ['VENTA', 'COMPRA'], true)) throw new DomainException('TIPO_FACTURA_INVALIDO');
                $tabla = $f['tipo'] === 'VENTA' ? 'erp_facturas_venta' : 'erp_facturas_compra';
                $factura = DB::table($tabla)->where('id', $f['id'])->where('empresa_id', $empresaId)->whereNull('deleted_at')->first();
                if (! $factura) throw new DomainException("FACTURA_NO_ENCONTRADA: {$f['tipo']} #{$f['id']}");
                $auxId = $factura->auxiliar_id;
                $cuentaAux = DB::table('erp_auxiliares')->where('id', $auxId)->value('cuenta_contable_default_id')
                    ?? DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)
                        ->where('codigo', $f['tipo'] === 'VENTA' ? '1.1.4.01' : '2.1.1.01')->value('id');
                $lineasFactura[] = ['cuenta_id' => $cuentaAux, 'auxiliar_id' => $auxId, 'monto' => round((float) $f['monto_imputado'], 2),
                    'factura_tabla' => $tabla, 'factura_id' => $f['id']];
            }

            $glosa = ($esCobro ? 'Cobro' : 'Pago') . ' múltiple ' . $mov->id . ' (' . count($facturas) . ' facturas)';
            $movs = [];
            // Banco siempre por el monto real del movimiento.
            if ($esCobro) {
                $movs[] = ['cuenta_id' => $cuentaBanco->cuenta_contable_id, 'debe' => $montoMov, 'haber' => 0, 'glosa' => $glosa];
                foreach ($lineasFactura as $lf) {
                    $movs[] = ['cuenta_id' => $lf['cuenta_id'], 'auxiliar_id' => $lf['auxiliar_id'], 'debe' => 0, 'haber' => $lf['monto'], 'glosa' => $glosa];
                }
                if (abs($ajuste) > 0.01) {
                    // ajuste = montoMov - sumImputado. Si >0: HABER; si <0: DEBE.
                    $ajuste > 0
                        ? $movs[] = ['cuenta_id' => $cuentaAjusteId, 'debe' => 0, 'haber' => $ajuste, 'glosa' => "Ajuste: {$motivo}"]
                        : $movs[] = ['cuenta_id' => $cuentaAjusteId, 'debe' => -$ajuste, 'haber' => 0, 'glosa' => "Ajuste: {$motivo}"];
                }
            } else {
                foreach ($lineasFactura as $lf) {
                    $movs[] = ['cuenta_id' => $lf['cuenta_id'], 'auxiliar_id' => $lf['auxiliar_id'], 'debe' => $lf['monto'], 'haber' => 0, 'glosa' => $glosa];
                }
                if (abs($ajuste) > 0.01) {
                    $ajuste > 0
                        ? $movs[] = ['cuenta_id' => $cuentaAjusteId, 'debe' => $ajuste, 'haber' => 0, 'glosa' => "Ajuste: {$motivo}"]
                        : $movs[] = ['cuenta_id' => $cuentaAjusteId, 'debe' => 0, 'haber' => -$ajuste, 'glosa' => "Ajuste: {$motivo}"];
                }
                $movs[] = ['cuenta_id' => $cuentaBanco->cuenta_contable_id, 'debe' => 0, 'haber' => $montoMov, 'glosa' => $glosa];
            }

            $asientoSvc = app(AsientoService::class);
            $asiento = $asientoSvc->crearBorrador([
                'empresa_id' => $empresaId, 'diario_id' => $diarioBan, 'fecha' => $mov->fecha,
                'glosa' => $glosa, 'origen' => 'BANCO', 'origen_tabla' => 'erp_movimientos_bancarios', 'origen_id' => $mov->id,
                'observaciones' => $motivo, 'usuario_id' => $usuario->id, 'movimientos' => $movs,
            ]);
            $asiento = $asientoSvc->contabilizar($asiento);

            foreach ($lineasFactura as $lf) {
                Conciliacion::create([
                    'movimiento_bancario_id' => $mov->id, 'referencia_tipo' => 'ASIENTO_MANUAL',
                    'referencia_id' => $asiento->id, 'importe_conciliado' => $lf['monto'],
                    'user_id' => $usuario->id, 'modo' => $motivo ? 'MANUAL' : 'AUTO',
                    'observacion' => sprintf('Conciliación múltiple contra %s #%d%s', $lf['factura_tabla'], $lf['factura_id'], $motivo ? " · {$motivo}" : ''),
                ]);
            }

            // v1.48 Bloque E — motivo ANTICIPO_PROVEEDOR deja el mov como
            // pendiente de facturar (el distribuidor debe emitir NC).
            $datosMov = [
                'estado' => MovimientoBancario::ESTADO_CONCILIADO,
                'asiento_id' => $asiento->id,
                'monto_conciliado' => $sumImputado,
                'motivo_diferencia_id' => $motivoDiferenciaId ?: $mov->motivo_diferencia_id,
            ];
            if ($motivoTipo === 'ANTICIPO_PROVEEDOR' && abs($ajuste) > 0.01) {
                $datosMov['pendiente_factura_complementaria'] = 1;
                $datosMov['distribuidor_pendiente_id'] = $lineasFactura[0]['auxiliar_id'] ?? null;
                $datosMov['monto_pendiente_facturar'] = round(abs($ajuste), 2);
                $datosMov['observaciones_pendiente'] = $motivo;
            }
            $mov->update($datosMov);

            $this->audit->logEvento(
                accion: 'MOVIMIENTO_CONCILIADO_MULTIPLE', modulo: 'tesoreria',
                descripcion: sprintf('Mov #%d conciliado contra %d facturas por $%.2f (asiento #%d)%s',
                    $mov->id, count($facturas), $sumImputado, $asiento->id, $motivo ? " · {$motivo}" : ''),
                empresaId: $empresaId,
            );
            return $mov->fresh(['asiento', 'cuentaBancaria']);
        });
    }

    /**
     * Auto-conciliación masiva sobre movimientos PENDIENTE/ETIQUETADO:
     *  - ETIQUETADO → confirma cuenta propuesta (crea asiento vía
     *    MovimientoBancarioService::conciliar)
     *  - PENDIENTE con importe exacto y fecha cercana a una OP LIBERADA →
     *    vincula contra la OP
     *  - PENDIENTE con importe exacto y fecha cercana a un Cobro con
     *    medio TRANSFERENCIA PENDIENTE → vincula contra el cobro
     *
     * @return array{procesados:int, conciliados:int, pendientes:int, detalles:array<int, string>}
     */
    public function autoconciliar(int $cuentaBancariaId, string $desde, string $hasta, int $rangoDias, User $usuario): array
    {
        $movs = MovimientoBancario::where('cuenta_bancaria_id', $cuentaBancariaId)
            ->whereIn('estado', [MovimientoBancario::ESTADO_PENDIENTE, MovimientoBancario::ESTADO_ETIQUETADO])
            ->whereBetween('fecha', [$desde, $hasta])
            ->get();

        $conciliados = 0;
        $detalles = [];

        foreach ($movs as $mov) {
            try {
                if ($mov->estado === MovimientoBancario::ESTADO_ETIQUETADO && $mov->cuenta_contable_propuesta_id) {
                    // Confirmar etiqueta → asiento contable con la cuenta propuesta
                    $this->movService->conciliar(
                        mov: $mov,
                        cuentaContableContraparteId: $mov->cuenta_contable_propuesta_id,
                        usuarioId: $usuario->id,
                        glosa: 'Auto-conciliación por etiqueta '.$mov->etiqueta_sugerida,
                    );
                    Conciliacion::create([
                        'movimiento_bancario_id' => $mov->id,
                        'referencia_tipo' => Conciliacion::REF_REGLA_AUTO,
                        'referencia_id' => 0,
                        'importe_conciliado' => (float) max($mov->debito, $mov->credito),
                        'user_id' => $usuario->id,
                        'modo' => Conciliacion::MODO_AUTO,
                        'observacion' => 'Etiqueta '.$mov->etiqueta_sugerida,
                    ]);
                    $conciliados++;
                    $detalles[] = "Mov #{$mov->id} ETIQUETADO → CONCILIADO ({$mov->etiqueta_sugerida})";
                    continue;
                }

                // PENDIENTE: buscar match contra OP LIBERADA
                $importeMov = (float) max($mov->debito, $mov->credito);
                if ($mov->debito > 0) {
                    $op = OrdenPago::where('empresa_id', $mov->cuentaBancaria->empresa_id)
                        ->where('estado', OrdenPago::ESTADO_LIBERADA)
                        ->whereBetween('fecha', [
                            $mov->fecha->copy()->subDays($rangoDias)->toDateString(),
                            $mov->fecha->copy()->addDays($rangoDias)->toDateString(),
                        ])
                        ->whereRaw('ROUND(importe, 2) = ?', [round($importeMov, 2)])
                        ->first();
                    if ($op) {
                        $this->conciliar($mov, [
                            'referencia_tipo' => Conciliacion::REF_ORDEN_PAGO,
                            'referencia_id' => $op->id,
                            'modo' => Conciliacion::MODO_AUTO,
                        ], $usuario);
                        $conciliados++;
                        $detalles[] = "Mov #{$mov->id} → OP #{$op->id} ({$op->numero})";
                        continue;
                    }
                }

                // PENDIENTE: buscar match contra Cobro TRANSFERENCIA
                if ($mov->credito > 0) {
                    $cobroId = DB::table('erp_cobro_medios as cm')
                        ->join('erp_cobros as c', 'c.id', '=', 'cm.cobro_id')
                        ->join('erp_medios_pago as mp', 'mp.id', '=', 'cm.medio_pago_id')
                        ->where('c.empresa_id', $mov->cuentaBancaria->empresa_id)
                        ->where('mp.afecta_banco', true)
                        ->where('cm.estado_acreditacion', CobroMedio::ESTADO_PENDIENTE)
                        ->where('cm.cuenta_bancaria_id', $mov->cuenta_bancaria_id)
                        ->whereRaw('ROUND(cm.importe, 2) = ?', [round($importeMov, 2)])
                        ->whereBetween('c.fecha', [
                            $mov->fecha->copy()->subDays($rangoDias)->toDateString(),
                            $mov->fecha->copy()->addDays($rangoDias)->toDateString(),
                        ])
                        ->value('c.id');
                    if ($cobroId) {
                        $this->conciliar($mov, [
                            'referencia_tipo' => Conciliacion::REF_COBRO,
                            'referencia_id' => $cobroId,
                            'modo' => Conciliacion::MODO_AUTO,
                        ], $usuario);
                        $conciliados++;
                        $detalles[] = "Mov #{$mov->id} → Cobro #{$cobroId}";
                        continue;
                    }
                }
            } catch (DomainException $e) {
                $detalles[] = "Mov #{$mov->id} error: {$e->getMessage()}";
            }
        }

        return [
            'procesados' => $movs->count(),
            'conciliados' => $conciliados,
            'pendientes' => $movs->count() - $conciliados,
            'detalles' => $detalles,
        ];
    }

    /**
     * Según el tipo de referencia, devuelve la cuenta contable de
     * contrapartida para el asiento automático.
     *
     * @return array{0:?int, 1:?int, 2:string}  [cuenta_contable_id, auxiliar_id, glosa_sugerida]
     */
    private function resolverContraparte(MovimientoBancario $mov, string $tipo, int $refId, array $data, User $usuario): array
    {
        switch ($tipo) {
            case Conciliacion::REF_ORDEN_PAGO:
                $op = OrdenPago::with('auxiliar')->findOrFail($refId);
                $codigo = match (strtoupper($op->auxiliar?->tipo ?? '')) {
                    'DISTRIBUIDOR', 'PERSONA', 'EMPLEADO' => '2.1.1.03',
                    default => '2.1.1.01',
                };
                $cc = DB::table('erp_cuentas_contables')
                    ->where('empresa_id', $op->empresa_id)->where('codigo', $codigo)->value('id');

                return [(int) $cc, (int) $op->auxiliar_id, "Pago OP {$op->numero} · {$op->auxiliar?->nombre}"];

            case Conciliacion::REF_COBRO:
                $cobro = Cobro::with('auxiliar')->findOrFail($refId);
                $cc = DB::table('erp_cuentas_contables')
                    ->where('empresa_id', $cobro->empresa_id)->where('codigo', '1.1.4.01')->value('id');

                return [(int) $cc, (int) $cobro->auxiliar_id, "Cobro {$cobro->numero} · {$cobro->auxiliar?->nombre}"];

            case Conciliacion::REF_TRANSFERENCIA_INTERNA:
                // TI se contabiliza con un asiento propio; aquí solo linkeamos
                // sin generar asiento nuevo.
                return [null, null, "TI #{$refId}"];

            case Conciliacion::REF_ASIENTO_MANUAL:
                // Requiere cuenta_contable_contraparte_id explícita.
                if (empty($data['cuenta_contable_contraparte_id'])) {
                    throw new DomainException('ASIENTO_MANUAL_REQUIERE_CUENTA: enviá cuenta_contable_contraparte_id');
                }

                return [(int) $data['cuenta_contable_contraparte_id'], $data['auxiliar_id'] ?? null, $data['glosa'] ?? 'Asiento manual'];

            case Conciliacion::REF_ECHEQ:
                // eCheq se acredita via EcheqService::acreditar (flujo propio);
                // acá solo linkeamos. El asiento lo creó EcheqService.
                return [null, null, "eCheq #{$refId}"];

            case Conciliacion::REF_REGLA_AUTO:
                // Usa la cuenta propuesta del movimiento.
                return [(int) ($mov->cuenta_contable_propuesta_id ?? 0), null, $mov->etiqueta_sugerida ?? 'Auto-imputación'];
        }

        return [null, null, ''];
    }

    private function aplicarEfectosColaterales(string $tipo, int $refId, MovimientoBancario $mov, float $importeConciliado): void
    {
        switch ($tipo) {
            case Conciliacion::REF_ORDEN_PAGO:
                OrdenPago::where('id', $refId)->update([
                    'estado' => OrdenPago::ESTADO_PAGADA,
                    'fecha_pago' => now(),
                    'asiento_id' => $mov->asiento_id,
                ]);
                break;

            case Conciliacion::REF_COBRO:
                // Marcar medio TRANSFERENCIA pendiente como ACREDITADO y
                // reescalar estado del cobro.
                $afectados = CobroMedio::where('cobro_id', $refId)
                    ->where('estado_acreditacion', CobroMedio::ESTADO_PENDIENTE)
                    ->where('cuenta_bancaria_id', $mov->cuenta_bancaria_id)
                    ->whereRaw('ROUND(importe, 2) = ?', [round($importeConciliado, 2)])
                    ->limit(1)
                    ->update([
                        'estado_acreditacion' => CobroMedio::ESTADO_ACREDITADO,
                        'movimiento_bancario_id' => $mov->id,
                    ]);

                $this->reescalarEstadoCobro($refId);
                break;

            case Conciliacion::REF_TRANSFERENCIA_INTERNA:
                // Linkear el mov a la TI correspondiente.
                $ti = TransferenciaInterna::find($refId);
                if ($ti) {
                    if ($mov->debito > 0) {
                        $ti->update(['movimiento_origen_id' => $mov->id]);
                    } else {
                        $ti->update(['movimiento_destino_id' => $mov->id]);
                    }
                    // Si ambos lados ya están vinculados, pasar a CONCILIADA.
                    $ti->refresh();
                    if ($ti->movimiento_origen_id && $ti->movimiento_destino_id && $ti->estado === TransferenciaInterna::ESTADO_PENDIENTE) {
                        $ti->update(['estado' => TransferenciaInterna::ESTADO_PARCIAL]);
                    }
                }
                break;
        }
    }

    private function revertirEfectosColaterales(string $tipo, int $refId): void
    {
        switch ($tipo) {
            case Conciliacion::REF_ORDEN_PAGO:
                OrdenPago::where('id', $refId)
                    ->where('estado', OrdenPago::ESTADO_PAGADA)
                    ->update([
                        'estado' => OrdenPago::ESTADO_LIBERADA,
                        'fecha_pago' => null,
                        'asiento_id' => null,
                    ]);
                break;

            case Conciliacion::REF_COBRO:
                CobroMedio::where('cobro_id', $refId)
                    ->where('estado_acreditacion', CobroMedio::ESTADO_ACREDITADO)
                    ->update([
                        'estado_acreditacion' => CobroMedio::ESTADO_PENDIENTE,
                        'movimiento_bancario_id' => null,
                    ]);
                $this->reescalarEstadoCobro($refId);
                break;
        }
    }

    private function reescalarEstadoCobro(int $cobroId): void
    {
        $cobro = Cobro::find($cobroId);
        if (! $cobro || in_array($cobro->estado, [Cobro::ESTADO_ANULADO, Cobro::ESTADO_RECHAZADO], true)) {
            return;
        }

        $estados = CobroMedio::where('cobro_id', $cobroId)->pluck('estado_acreditacion');
        if ($estados->isEmpty()) {
            return;
        }

        $nuevo = match (true) {
            $estados->every(fn ($e) => $e === CobroMedio::ESTADO_ACREDITADO) => Cobro::ESTADO_ACREDITADO,
            $estados->contains(CobroMedio::ESTADO_RECHAZADO) && $estados->contains(CobroMedio::ESTADO_ACREDITADO) => Cobro::ESTADO_RECHAZADO_PARCIAL,
            $estados->contains(CobroMedio::ESTADO_ACREDITADO) => Cobro::ESTADO_PARCIAL_ACREDITADO,
            default => Cobro::ESTADO_REGISTRADO,
        };

        $cobro->update(['estado' => $nuevo]);
    }
}

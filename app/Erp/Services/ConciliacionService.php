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

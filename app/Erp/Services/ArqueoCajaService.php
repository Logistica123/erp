<?php

namespace App\Erp\Services;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Tesoreria\ArqueoCaja;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Arqueo de caja (SPEC 02 §7.7, RN-16, RN-22, RN-23).
 *
 * RN-16 enforced por trigger SQL trg_caja_saldo_bu (rechaza UPDATE que
 *   deje saldo_actual < 0).
 * RN-22 alerta soft: fechas sin arqueo al cerrar período se listan sin
 *   bloquear (findFechasSinArqueo()).
 * RN-23 si saldo_fisico ≠ saldo_teorico, se genera asiento automático
 *   en diario AJU:
 *     · diferencia > 0 (sobrante): DEBE caja, HABER 4.2.07 Sobrante de Caja
 *     · diferencia < 0 (faltante): DEBE 5.4.09 Faltante de Caja, HABER caja
 */
class ArqueoCajaService
{
    public const CODIGO_SOBRANTE = '4.2.07';
    public const CODIGO_FALTANTE = '5.4.09';

    /** v1.42 — Tolerancia de redondeo: |dif| ≤ TOL = auto-ajuste (D-42-4/13). */
    public const TOLERANCIA_AUTOAJUSTE = 1.00;

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * v1.42 — Registra un arqueo con flujo de 3 caminos según |dif|:
     *   - = 0       → CIERRA_OK (sin asiento, sin tocar saldo).
     *   - ≤ $1      → CERRADO_CON_AJUSTE (asiento RN-23 + ajusta saldo).
     *   - > $1      → PENDIENTE_AUTORIZACION (no toca saldo; supervisor decide
     *                 via autorizar()).
     *
     * Acepta opcionalmente la grilla `denominaciones` (billete a billete) que
     * se persiste en erp_arqueos_caja_denominaciones. Si está presente, el
     * saldo_fisico se valida como la suma de los subtotales.
     *
     * @param  array{
     *   caja_id:int,
     *   fecha:string,
     *   saldo_fisico:float|string,
     *   motivo?:?string,
     *   usuario_id:int,
     *   denominaciones?:array<int,array{valor:float|string, cantidad:int}>,
     * }  $data
     */
    public function registrar(array $data): ArqueoCaja
    {
        $caja = Caja::findOrFail($data['caja_id']);
        if (! $caja->activo) {
            throw new DomainException("CAJA_INACTIVA: la caja {$caja->codigo} está desactivada (no opera).");
        }
        // D-42-2: el operador debe estar autorizado para esta caja.
        $this->validarOperador((int) $data['usuario_id'], (int) $caja->id);

        $fecha = Carbon::parse($data['fecha'])->toDateString();
        $saldoFisico = round((float) $data['saldo_fisico'], 2);
        $denominaciones = $data['denominaciones'] ?? [];

        // Validar consistencia de la grilla (si vino).
        $sumaDenom = 0.0;
        foreach ($denominaciones as $d) {
            $sumaDenom += round(((float) $d['valor']) * (int) $d['cantidad'], 2);
        }
        $sumaDenom = round($sumaDenom, 2);
        if (! empty($denominaciones) && abs($sumaDenom - $saldoFisico) > 0.01) {
            throw new DomainException(sprintf(
                'GRILLA_INCONSISTENTE: suma de denominaciones $%.2f ≠ saldo_fisico $%.2f',
                $sumaDenom, $saldoFisico,
            ));
        }

        // D-42-3: múltiples arqueos por día permitidos. Ya no rebotamos por
        // existir un arqueo previo en la misma fecha.

        $saldoTeorico = (float) $caja->saldo_actual;
        $diferencia = round($saldoFisico - $saldoTeorico, 2);

        if (abs($diferencia) > 0.01 && empty($data['motivo'])) {
            throw new DomainException('ARQUEO_MOTIVO_REQUERIDO: con diferencia distinta de cero se requiere motivo (mín 10 chars).');
        }
        if (abs($diferencia) > 0.01 && strlen(trim((string) $data['motivo'])) < 10) {
            throw new DomainException('ARQUEO_MOTIVO_CORTO: el motivo debe tener al menos 10 caracteres.');
        }

        // 3 caminos por |diferencia|.
        $abs = abs($diferencia);
        $estado = $abs < 0.01
            ? 'CIERRA_OK'
            : ($abs <= self::TOLERANCIA_AUTOAJUSTE ? 'CERRADO_CON_AJUSTE' : 'PENDIENTE_AUTORIZACION');

        return DB::transaction(function () use ($caja, $data, $fecha, $saldoFisico, $saldoTeorico, $diferencia, $estado, $denominaciones) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $arqueo = ArqueoCaja::create([
                'caja_id' => $caja->id,
                'fecha' => $fecha,
                'saldo_teorico' => $saldoTeorico,
                'saldo_fisico' => $saldoFisico,
                'motivo' => $data['motivo'] ?? null,
                'estado' => $estado,
                'realizado_por_user_id' => $data['usuario_id'],
            ]);

            // Persistir denominaciones (si vinieron).
            $this->guardarDenominaciones($arqueo->id, $denominaciones);

            // Auto-ajuste si |dif| ≤ tolerancia. Pendiente autorización: NO
            // toca saldo ni genera asiento — eso lo hace autorizar().
            if ($estado === 'CERRADO_CON_AJUSTE') {
                $asiento = $this->asientoDiferencia($caja, $fecha, $diferencia, $data['usuario_id'],
                    $data['motivo'] ?? 'Diferencia de arqueo (auto-ajuste ≤ tolerancia)');
                $arqueo->update(['asiento_ajuste_id' => $asiento->id]);
                $caja->update(['saldo_actual' => $saldoFisico]);
            }

            $this->audit->logEvento(
                accion: 'ARQUEO_REGISTRADO',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'Arqueo caja %s al %s · teórico=%.2f · físico=%.2f · diferencia=%.2f · estado=%s',
                    $caja->codigo, $fecha, $saldoTeorico, $saldoFisico, $diferencia, $estado
                ),
                empresaId: $caja->empresa_id,
            );

            return $arqueo->fresh();
        });
    }

    /**
     * v1.42 — Resuelve un arqueo en PENDIENTE_AUTORIZACION según la decisión
     * del supervisor:
     *   - AJUSTAR                → genera asiento RN-23 + ajusta saldo + CERRADO_CON_AJUSTE.
     *   - CERRAR_CON_DISCREPANCIA → sin asiento, saldo teórico SE MANTIENE (queda
     *                                la diferencia documentada en el arqueo) +
     *                                CERRADO_CON_DISCREPANCIA.
     *   - RECHAZAR               → RECHAZADO. El operador puede registrar uno nuevo.
     *
     * @param  array{decision:string, motivo?:?string, usuario_id:int}  $data
     */
    public function autorizar(ArqueoCaja $arqueo, array $data): ArqueoCaja
    {
        if ($arqueo->estado !== 'PENDIENTE_AUTORIZACION') {
            throw new DomainException("ARQUEO_NO_PENDIENTE: estado actual {$arqueo->estado}, no se puede autorizar.");
        }
        $decision = $data['decision'] ?? '';
        if (! in_array($decision, ['AJUSTAR', 'CERRAR_CON_DISCREPANCIA', 'RECHAZAR'], true)) {
            throw new DomainException("DECISION_INVALIDA: {$decision}");
        }
        $motivo = trim((string) ($data['motivo'] ?? ''));
        if ($decision !== 'AJUSTAR' && strlen($motivo) < 10) {
            throw new DomainException('MOTIVO_AUTORIZACION_CORTO: requerido min 10 chars para CERRAR_CON_DISCREPANCIA o RECHAZAR.');
        }
        $usuarioId = (int) $data['usuario_id'];
        $caja = Caja::findOrFail($arqueo->caja_id);

        return DB::transaction(function () use ($arqueo, $caja, $decision, $motivo, $usuarioId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            $diferencia = round((float) $arqueo->saldo_fisico - (float) $arqueo->saldo_teorico, 2);
            $nuevoEstado = match ($decision) {
                'AJUSTAR' => 'CERRADO_CON_AJUSTE',
                'CERRAR_CON_DISCREPANCIA' => 'CERRADO_CON_DISCREPANCIA',
                'RECHAZAR' => 'RECHAZADO',
            };

            if ($decision === 'AJUSTAR' && abs($diferencia) > 0.01) {
                $asiento = $this->asientoDiferencia($caja, (string) $arqueo->fecha, $diferencia, $usuarioId,
                    $motivo !== '' ? $motivo : ('Autorización ajuste arqueo #' . $arqueo->id));
                $arqueo->update(['asiento_ajuste_id' => $asiento->id]);
                $caja->update(['saldo_actual' => (float) $arqueo->saldo_fisico]);
            }

            $arqueo->update([
                'estado' => $nuevoEstado,
                'autorizado_por_user_id' => $usuarioId,
                'fecha_autorizacion' => now(),
                'decision_autorizacion' => $decision,
                'motivo_autorizacion' => $motivo !== '' ? $motivo : null,
            ]);

            $this->audit->logEvento(
                accion: 'ARQUEO_AUTORIZADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Arqueo #%d caja %s: %s (user %d) — %s',
                    $arqueo->id, $caja->codigo, $decision, $usuarioId, $motivo ?: '(sin motivo)'),
                empresaId: $caja->empresa_id,
            );

            return $arqueo->fresh();
        });
    }

    /**
     * D-42-2 — Valida que el user esté en la lista de operadores autorizados
     * de la caja. super_admin queda exento (puede operar cualquier caja para
     * resolver emergencias).
     */
    public function validarOperador(int $userId, int $cajaId): void
    {
        // super_admin bypass.
        $esSuperAdmin = DB::table('erp_usuario_rol as ur')
            ->join('erp_roles as r', 'r.id', '=', 'ur.rol_id')
            ->join('erp_usuario_perfil as up', 'up.id', '=', 'ur.usuario_perfil_id')
            ->where('up.user_id', $userId)
            ->where('r.codigo', 'super_admin')
            ->exists();
        if ($esSuperAdmin) return;

        $autorizado = DB::table('erp_cajas_operadores')
            ->where('user_id', $userId)
            ->where('caja_id', $cajaId)
            ->whereNull('fecha_baja')
            ->exists();
        if (! $autorizado) {
            throw new DomainException(
                "OPERADOR_NO_AUTORIZADO: el usuario #{$userId} no está en la lista de operadores autorizados de la caja #{$cajaId}. " .
                "Pedir alta a un super_admin desde el panel de operadores."
            );
        }
    }

    /**
     * Persiste la grilla de billetes en erp_arqueos_caja_denominaciones.
     * @param  array<int,array{valor:float|string, cantidad:int}>  $denominaciones
     */
    private function guardarDenominaciones(int $arqueoId, array $denominaciones): void
    {
        if (empty($denominaciones)) return;
        $rows = [];
        foreach ($denominaciones as $d) {
            $valor = round((float) $d['valor'], 2);
            $cantidad = (int) $d['cantidad'];
            if ($cantidad <= 0) continue;
            $rows[] = [
                'arqueo_id' => $arqueoId,
                'valor_billete' => $valor,
                'cantidad' => $cantidad,
                'subtotal' => round($valor * $cantidad, 2),
            ];
        }
        if (! empty($rows)) {
            DB::table('erp_arqueos_caja_denominaciones')->insert($rows);
        }
    }

    /**
     * RN-22: devuelve lista de fechas operativas (días hábiles con
     * movimiento en la caja) dentro del rango que no tienen arqueo.
     *
     * @return array<int, string>  fechas en formato YYYY-MM-DD
     */
    public function fechasSinArqueo(int $cajaId, string $desde, string $hasta): array
    {
        $diasConMovimiento = DB::table('erp_asientos as a')
            ->join('erp_movimientos_asiento as m', 'm.asiento_id', '=', 'a.id')
            ->join('erp_cajas as c', 'c.cuenta_contable_id', '=', 'm.cuenta_id')
            ->where('c.id', $cajaId)
            ->where('a.estado', 'CONTABILIZADO')
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->distinct()
            ->pluck('a.fecha')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->all();

        $conArqueo = ArqueoCaja::where('caja_id', $cajaId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->pluck('fecha')
            ->map(fn ($d) => $d instanceof \DateTimeInterface ? $d->format('Y-m-d') : (string) $d)
            ->all();

        return array_values(array_diff($diasConMovimiento, $conArqueo));
    }

    /**
     * Movimientos de caja en un rango (líneas de asientos contabilizados
     * que imputan a la cuenta contable de la caja).
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    public function movimientos(Caja $caja, string $desde, string $hasta)
    {
        return DB::table('erp_asientos as a')
            ->join('erp_movimientos_asiento as m', 'm.asiento_id', '=', 'a.id')
            ->leftJoin('erp_diarios as d', 'd.id', '=', 'a.diario_id')
            ->where('m.cuenta_id', $caja->cuenta_contable_id)
            ->whereIn('a.estado', ['CONTABILIZADO', 'ANULADO'])
            ->whereBetween('a.fecha', [$desde, $hasta])
            ->orderBy('a.fecha')->orderBy('a.numero')->orderBy('m.linea')
            ->select([
                'a.id as asiento_id', 'a.numero', 'a.fecha', 'a.glosa', 'a.estado',
                'd.codigo as diario', 'm.debe', 'm.haber', 'm.glosa as linea_glosa',
            ])
            ->get();
    }

    private function asientoDiferencia(Caja $caja, string $fecha, float $diferencia, int $usuarioId, string $motivo): \App\Erp\Models\Asiento
    {
        $codigoAjuste = $diferencia > 0 ? self::CODIGO_SOBRANTE : self::CODIGO_FALTANTE;
        $cuentaAjuste = CuentaContable::where('empresa_id', $caja->empresa_id)
            ->where('codigo', $codigoAjuste)
            ->first();
        if (! $cuentaAjuste) {
            throw new DomainException("CUENTA_CONTABLE_NO_ENCONTRADA: {$codigoAjuste} (para ajuste de arqueo)");
        }

        $importe = abs($diferencia);
        $glosa = sprintf('Ajuste arqueo caja %s %s: %s', $caja->codigo, $fecha, $motivo);

        $movimientos = $diferencia > 0
            ? [
                ['cuenta_id' => $caja->cuenta_contable_id, 'debe' => $importe, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => $cuentaAjuste->id, 'debe' => 0, 'haber' => $importe, 'glosa' => $glosa],
            ]
            : [
                ['cuenta_id' => $cuentaAjuste->id, 'debe' => $importe, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => $caja->cuenta_contable_id, 'debe' => 0, 'haber' => $importe, 'glosa' => $glosa],
            ];

        $movimientos = $this->completarCc($movimientos, $caja->empresa_id);

        $diarioAju = DB::table('erp_diarios')
            ->where('empresa_id', $caja->empresa_id)
            ->where('codigo', 'AJU')
            ->value('id');

        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => $caja->empresa_id,
            'diario_id' => $diarioAju,
            'fecha' => $fecha,
            'glosa' => $glosa,
            'origen' => 'AJUSTE',
            'origen_id' => $caja->id,
            'origen_tabla' => 'erp_cajas',
            'usuario_id' => $usuarioId,
            'movimientos' => $movimientos,
        ]);

        return $this->asientoService->contabilizar($asiento);
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

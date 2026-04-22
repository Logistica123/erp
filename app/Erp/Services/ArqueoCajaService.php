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

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{
     *   caja_id:int,
     *   fecha:string,
     *   saldo_fisico:float|string,
     *   motivo?:?string,
     *   usuario_id:int,
     * }  $data
     */
    public function registrar(array $data): ArqueoCaja
    {
        $caja = Caja::findOrFail($data['caja_id']);
        $fecha = Carbon::parse($data['fecha'])->toDateString();
        $saldoFisico = round((float) $data['saldo_fisico'], 2);

        $existente = ArqueoCaja::where('caja_id', $caja->id)->where('fecha', $fecha)->first();
        if ($existente) {
            throw new DomainException(sprintf(
                'ARQUEO_DUPLICADO: ya existe arqueo para caja %s en %s (id=%d)',
                $caja->codigo,
                $fecha,
                $existente->id
            ));
        }

        $saldoTeorico = (float) $caja->saldo_actual;
        $diferencia = round($saldoFisico - $saldoTeorico, 2);

        if (abs($diferencia) > 0.01 && empty($data['motivo'])) {
            throw new DomainException('ARQUEO_MOTIVO_REQUERIDO: con diferencia distinta de cero se requiere motivo');
        }

        return DB::transaction(function () use ($caja, $data, $fecha, $saldoFisico, $saldoTeorico, $diferencia) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $arqueo = ArqueoCaja::create([
                'caja_id' => $caja->id,
                'fecha' => $fecha,
                'saldo_teorico' => $saldoTeorico,
                'saldo_fisico' => $saldoFisico,
                'motivo' => $data['motivo'] ?? null,
                'realizado_por_user_id' => $data['usuario_id'],
            ]);

            // RN-23: si hay diferencia, generar asiento automático.
            if (abs($diferencia) > 0.01) {
                $asiento = $this->asientoDiferencia($caja, $fecha, $diferencia, $data['usuario_id'], $data['motivo'] ?? 'Diferencia de arqueo');
                $arqueo->update(['asiento_ajuste_id' => $asiento->id]);

                // Ajustar saldo de caja para que quede alineado al físico.
                // El trigger trg_caja_saldo_bu validará no-negativo (RN-16).
                $caja->update(['saldo_actual' => $saldoFisico]);
            }

            $this->audit->logEvento(
                accion: 'ARQUEO_REGISTRADO',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'Arqueo caja %s al %s · teórico=%.2f · físico=%.2f · diferencia=%.2f',
                    $caja->codigo, $fecha, $saldoTeorico, $saldoFisico, $diferencia
                ),
                empresaId: $caja->empresa_id,
            );

            return $arqueo->fresh();
        });
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

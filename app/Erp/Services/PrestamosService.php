<?php

namespace App\Erp\Services;

use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 Fase D — Préstamos con cronograma.
 *
 * Sistemas de amortización soportados:
 *   - FRANCES:    cuota fija. Capital crece, interés decrece.
 *   - ALEMAN:     capital fijo (= capital/n). Cuota e interés decrecen.
 *   - AMERICANO:  cuotas de solo interés; capital al final.
 *   - BULLET:     todo capital + intereses al vencimiento (1 cuota).
 *
 * Tasa: si vino `tasa_mensual` se usa esa. Si vino `tasa_nominal_anual` se
 * divide por 12. Si no vino ninguna → cronograma sin interés (i=0).
 */
class PrestamosService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function listar(int $empresaId, ?string $tipo = null, ?string $estado = 'VIGENTE'): array
    {
        $q = DB::table('erp_prestamos as p')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'p.contraparte_auxiliar_id')
            ->where('p.empresa_id', $empresaId)
            ->select([
                'p.*',
                'a.codigo as contraparte_codigo', 'a.nombre as contraparte_nombre',
                DB::raw('(SELECT SUM(c.capital) FROM erp_prestamos_cuotas c WHERE c.prestamo_id = p.id AND c.estado != "PAGADA") as capital_adeudado'),
                DB::raw('(SELECT COUNT(*) FROM erp_prestamos_cuotas c WHERE c.prestamo_id = p.id AND c.estado = "PAGADA") as cuotas_pagadas'),
                DB::raw('(SELECT MIN(c.fecha_vencimiento) FROM erp_prestamos_cuotas c WHERE c.prestamo_id = p.id AND c.estado != "PAGADA") as proxima_fecha'),
                DB::raw('(SELECT c.total_cuota FROM erp_prestamos_cuotas c WHERE c.prestamo_id = p.id AND c.estado != "PAGADA" ORDER BY c.fecha_vencimiento LIMIT 1) as proxima_cuota'),
            ])
            ->orderByDesc('p.fecha_otorgamiento');
        if ($tipo) $q->where('p.tipo', $tipo);
        if ($estado) $q->where('p.estado', $estado);
        return $q->get()->all();
    }

    /**
     * @param  array{
     *   empresa_id:int, tipo:string, contraparte_auxiliar_id:int, nombre:string,
     *   capital:float|string, moneda?:string, tasa_mensual?:?float, tasa_nominal_anual?:?float,
     *   sistema_amortizacion?:string, plazo_cuotas:int, fecha_otorgamiento:string,
     *   fecha_primera_cuota:string, cuenta_contable_id?:?int, observaciones?:?string,
     *   usuario_id:int
     * }  $data
     */
    public function crear(array $data): int
    {
        if (! in_array($data['tipo'], ['OTORGADO', 'RECIBIDO'], true)) {
            throw new DomainException("TIPO_INVALIDO: {$data['tipo']}");
        }
        $capital = round((float) $data['capital'], 2);
        if ($capital <= 0) throw new DomainException("CAPITAL_INVALIDO: debe ser mayor a 0.");
        $n = (int) $data['plazo_cuotas'];
        if ($n < 1) throw new DomainException("PLAZO_INVALIDO: debe ser ≥ 1.");

        $sistema = $data['sistema_amortizacion'] ?? 'FRANCES';
        if ($sistema === 'BULLET' && $n !== 1) {
            // Para BULLET, fuerza n=1.
            $n = 1;
        }
        if (! in_array($sistema, ['FRANCES', 'ALEMAN', 'AMERICANO', 'BULLET'], true)) {
            throw new DomainException("SISTEMA_AMORTIZACION_INVALIDO: {$sistema}");
        }

        $tasaMensual = $this->tasaMensual($data['tasa_mensual'] ?? null, $data['tasa_nominal_anual'] ?? null);

        return DB::transaction(function () use ($data, $capital, $n, $sistema, $tasaMensual) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $prestamoId = DB::table('erp_prestamos')->insertGetId([
                'empresa_id' => $data['empresa_id'],
                'tipo' => $data['tipo'],
                'contraparte_auxiliar_id' => $data['contraparte_auxiliar_id'],
                'nombre' => $data['nombre'],
                'capital' => $capital,
                'moneda' => $data['moneda'] ?? 'ARS',
                'tasa_mensual' => $data['tasa_mensual'] ?? null,
                'tasa_nominal_anual' => $data['tasa_nominal_anual'] ?? null,
                'sistema_amortizacion' => $sistema,
                'plazo_cuotas' => $n,
                'fecha_otorgamiento' => $data['fecha_otorgamiento'],
                'fecha_primera_cuota' => $data['fecha_primera_cuota'],
                'cuenta_contable_id' => $data['cuenta_contable_id'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
                'estado' => 'VIGENTE',
            ]);

            $cuotas = $this->generarCronograma($capital, $n, $tasaMensual, $sistema, $data['fecha_primera_cuota']);
            $rows = [];
            foreach ($cuotas as $i => $c) {
                $rows[] = [
                    'prestamo_id' => $prestamoId,
                    'numero_cuota' => $i + 1,
                    'fecha_vencimiento' => $c['fecha'],
                    'capital' => $c['capital'],
                    'interes' => $c['interes'],
                    'total_cuota' => $c['total'],
                    'capital_adeudado_post' => $c['saldo'],
                    'estado' => 'PENDIENTE',
                ];
            }
            DB::table('erp_prestamos_cuotas')->insert($rows);

            $this->audit->logEvento(
                accion: 'PRESTAMO_CREADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Préstamo #%d %s "%s" capital=$%.2f %s plazo=%d sistema=%s',
                    $prestamoId, $data['tipo'], $data['nombre'], $capital, $data['moneda'] ?? 'ARS', $n, $sistema),
                empresaId: $data['empresa_id'],
            );

            return $prestamoId;
        });
    }

    public function detalle(int $prestamoId): array
    {
        $p = DB::table('erp_prestamos as p')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'p.contraparte_auxiliar_id')
            ->where('p.id', $prestamoId)
            ->select(['p.*', 'a.codigo as contraparte_codigo', 'a.nombre as contraparte_nombre'])
            ->first();
        if (! $p) throw new DomainException("PRESTAMO_NO_ENCONTRADO");
        $cuotas = DB::table('erp_prestamos_cuotas')
            ->where('prestamo_id', $prestamoId)
            ->orderBy('numero_cuota')
            ->get()->all();
        return ['prestamo' => $p, 'cuotas' => $cuotas];
    }

    /**
     * Marca cuota como PAGADA con importe + fecha + opcionalmente OP/Recibo asociado.
     *
     * @param  array{fecha_pago:string, importe_pagado:float|string, op_pago_id?:?int, recibo_cobro_id?:?int, observaciones?:?string, usuario_id:int}  $data
     */
    public function pagarCuota(int $prestamoId, int $cuotaId, array $data): void
    {
        $cuota = DB::table('erp_prestamos_cuotas')->where('id', $cuotaId)
            ->where('prestamo_id', $prestamoId)->first();
        if (! $cuota) throw new DomainException("CUOTA_NO_ENCONTRADA");
        if ($cuota->estado === 'PAGADA') throw new DomainException("CUOTA_YA_PAGADA");

        DB::transaction(function () use ($prestamoId, $cuotaId, $cuota, $data) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            DB::table('erp_prestamos_cuotas')->where('id', $cuotaId)->update([
                'estado' => 'PAGADA',
                'fecha_pago' => $data['fecha_pago'],
                'importe_pagado' => round((float) $data['importe_pagado'], 2),
                'op_pago_id' => $data['op_pago_id'] ?? null,
                'recibo_cobro_id' => $data['recibo_cobro_id'] ?? null,
                'observaciones' => $data['observaciones'] ?? null,
            ]);

            // Si todas las cuotas están pagadas → marcar préstamo CANCELADO.
            $pendientes = DB::table('erp_prestamos_cuotas')
                ->where('prestamo_id', $prestamoId)
                ->where('estado', '!=', 'PAGADA')->count();
            if ($pendientes === 0) {
                DB::table('erp_prestamos')->where('id', $prestamoId)
                    ->update(['estado' => 'CANCELADO']);
            }

            $this->audit->logEvento(
                accion: 'PRESTAMO_CUOTA_PAGADA',
                modulo: 'tesoreria',
                descripcion: sprintf('Préstamo #%d cuota #%d pagada $%.2f',
                    $prestamoId, $cuota->numero_cuota, (float) $data['importe_pagado']),
            );
        });
    }

    public function cancelar(int $prestamoId, string $motivo, int $usuarioId, bool $incobrable = false): void
    {
        $p = DB::table('erp_prestamos')->find($prestamoId);
        if (! $p) throw new DomainException("PRESTAMO_NO_ENCONTRADO");

        DB::transaction(function () use ($p, $prestamoId, $motivo, $usuarioId, $incobrable) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);
            DB::table('erp_prestamos')->where('id', $prestamoId)->update([
                'estado' => $incobrable ? 'INCOBRABLE' : 'CANCELADO',
                'observaciones' => trim(($p->observaciones ?? '') . "\nCancelación " . now()->toDateString() . ": " . $motivo),
            ]);
            $this->audit->logEvento(
                accion: $incobrable ? 'PRESTAMO_INCOBRABLE' : 'PRESTAMO_CANCELADO',
                modulo: 'tesoreria',
                descripcion: "Préstamo #{$prestamoId}: {$motivo}",
            );
        });
    }

    private function tasaMensual(?float $tasaMensual, ?float $tna): float
    {
        if ($tasaMensual !== null && $tasaMensual > 0) {
            // Si viene como porcentaje (4 = 4%), normalizar a decimal.
            return (float) $tasaMensual / 100.0;
        }
        if ($tna !== null && $tna > 0) {
            return ((float) $tna / 100.0) / 12.0;
        }
        return 0.0;
    }

    /**
     * @return array<int,array{fecha:string, capital:float, interes:float, total:float, saldo:float}>
     */
    private function generarCronograma(float $capital, int $n, float $i, string $sistema, string $primeraFecha): array
    {
        $fechas = [];
        $base = Carbon::parse($primeraFecha);
        for ($k = 0; $k < $n; $k++) {
            $fechas[] = $base->copy()->addMonthsNoOverflow($k)->toDateString();
        }

        $out = [];
        $saldo = $capital;

        if ($sistema === 'BULLET' || $n === 1) {
            $interes = round($capital * $i * $n, 2);
            $out[] = ['fecha' => $fechas[0], 'capital' => $capital, 'interes' => $interes, 'total' => round($capital + $interes, 2), 'saldo' => 0.0];
            return $out;
        }

        if ($sistema === 'AMERICANO') {
            $interesMensual = round($capital * $i, 2);
            for ($k = 0; $k < $n; $k++) {
                $esUltima = $k === $n - 1;
                $capCuota = $esUltima ? $capital : 0.0;
                $out[] = [
                    'fecha' => $fechas[$k],
                    'capital' => $capCuota,
                    'interes' => $interesMensual,
                    'total' => round($capCuota + $interesMensual, 2),
                    'saldo' => $esUltima ? 0.0 : $capital,
                ];
            }
            return $out;
        }

        if ($sistema === 'ALEMAN') {
            $capCuota = round($capital / $n, 2);
            for ($k = 0; $k < $n; $k++) {
                $intCuota = round($saldo * $i, 2);
                $capReal = ($k === $n - 1) ? round($saldo, 2) : $capCuota;
                $saldoPost = round($saldo - $capReal, 2);
                $out[] = [
                    'fecha' => $fechas[$k],
                    'capital' => $capReal,
                    'interes' => $intCuota,
                    'total' => round($capReal + $intCuota, 2),
                    'saldo' => $saldoPost,
                ];
                $saldo = $saldoPost;
            }
            return $out;
        }

        // FRANCES (default): cuota fija = C * i / (1 - (1+i)^-n)
        if ($i < 0.0000001) {
            $cuotaFija = round($capital / $n, 2);
            for ($k = 0; $k < $n; $k++) {
                $capReal = ($k === $n - 1) ? round($saldo, 2) : $cuotaFija;
                $saldoPost = round($saldo - $capReal, 2);
                $out[] = ['fecha' => $fechas[$k], 'capital' => $capReal, 'interes' => 0.0, 'total' => $capReal, 'saldo' => $saldoPost];
                $saldo = $saldoPost;
            }
            return $out;
        }
        $cuotaFija = round($capital * $i / (1 - pow(1 + $i, -$n)), 2);
        for ($k = 0; $k < $n; $k++) {
            $intCuota = round($saldo * $i, 2);
            $capCuota = round($cuotaFija - $intCuota, 2);
            if ($k === $n - 1) {
                $capCuota = round($saldo, 2);
                $cuotaFinal = round($capCuota + $intCuota, 2);
            } else {
                $cuotaFinal = $cuotaFija;
            }
            $saldoPost = round($saldo - $capCuota, 2);
            $out[] = [
                'fecha' => $fechas[$k],
                'capital' => $capCuota,
                'interes' => $intCuota,
                'total' => $cuotaFinal,
                'saldo' => $saldoPost,
            ];
            $saldo = $saldoPost;
        }
        return $out;
    }
}

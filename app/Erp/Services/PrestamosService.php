<?php

namespace App\Erp\Services;

use App\Erp\Services\Prestamos\ParserPlanAfip;
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
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AsientoService $asientos,
    ) {}

    // Cuentas contables del plan AFIP (por código, resueltas en runtime).
    private const CUENTA_PLAN_FISCAL = '2.1.3.13';   // Planes de Pago Fiscales (pasivo)
    private const CUENTA_INTERES_FISCAL = '5.6.01';  // Multas e Intereses Fiscales (gasto)

    /**
     * Importa un plan de facilidades de ARCA/AFIP desde el texto del PDF
     * "Mis Facilidades". Lo carga como préstamo RECIBIDO con su cronograma del
     * 1° vencimiento, vinculado al auxiliar Organismo PLAN-{numero} y a la cuenta
     * de pasivo 2.1.3.13 "Planes de Pago Fiscales".
     *
     * @return array{prestamo_id:int, numero_plan:string, cuotas:int}
     */
    public function importarPlanAfip(string $textoPdf, int $empresaId, int $usuarioId): array
    {
        $plan = (new ParserPlanAfip())->parse($textoPdf);
        $numero = $plan['numero_plan'];

        return DB::transaction(function () use ($plan, $numero, $empresaId, $usuarioId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            // Cuenta de pasivo del plan (la misma para todos los planes fiscales).
            $ctaPlanId = (int) DB::table('erp_cuentas_contables')
                ->where('empresa_id', $empresaId)->where('codigo', self::CUENTA_PLAN_FISCAL)->value('id');
            if (! $ctaPlanId) {
                throw new DomainException('CUENTA_PLAN_NO_EXISTE: falta la cuenta '.self::CUENTA_PLAN_FISCAL.' (Planes de Pago Fiscales).');
            }

            // Resolver/crear el auxiliar Organismo PLAN-{numero}.
            $codigoAux = 'PLAN-'.$numero;
            $aux = DB::table('erp_auxiliares')->where('empresa_id', $empresaId)
                ->where('tipo', 'Organismo')->where('codigo', $codigoAux)->first();
            if ($aux) {
                $auxId = $aux->id;
            } else {
                $auxId = DB::table('erp_auxiliares')->insertGetId([
                    'empresa_id' => $empresaId, 'tipo' => 'Organismo', 'codigo' => $codigoAux,
                    'nombre' => 'AFIP - Plan de pagos '.$numero,
                    'cuit' => $plan['cuit'] ?: null,
                    'cuenta_contable_default_id' => $ctaPlanId,
                    'activo' => 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            // Idempotencia: si ya existe un préstamo RECIBIDO para ese plan, frenar.
            $existe = DB::table('erp_prestamos')->where('empresa_id', $empresaId)
                ->where('tipo', 'RECIBIDO')->where('contraparte_auxiliar_id', $auxId)->exists();
            if ($existe) {
                throw new DomainException('PLAN_YA_CARGADO: el plan '.$numero.' ya está cargado como préstamo.');
            }

            $cuotas = $plan['cuotas'];
            $prestamoId = DB::table('erp_prestamos')->insertGetId([
                'empresa_id' => $empresaId,
                'tipo' => 'RECIBIDO',
                'contraparte_auxiliar_id' => $auxId,
                'nombre' => 'AFIP - Plan de pagos '.$numero,
                'capital' => $plan['total_capital'],
                'moneda' => 'ARS',
                'sistema_amortizacion' => 'FRANCES',
                'plazo_cuotas' => count($cuotas),
                'fecha_otorgamiento' => $plan['fecha_consolidacion'] ?? ($cuotas[0]['fecha_venc'] ?? today()->toDateString()),
                'fecha_primera_cuota' => $cuotas[0]['fecha_venc'] ?? today()->toDateString(),
                'cuenta_contable_id' => $ctaPlanId,
                'observaciones' => sprintf('Importado del PDF ARCA "Mis Facilidades". Plan %s · CUIT %s · consolidación %s. Cronograma 1° vencimiento.',
                    $numero, $plan['cuit'] ?? '—', $plan['fecha_consolidacion'] ?? '—'),
                'estado' => 'VIGENTE',
            ]);

            $saldo = (float) $plan['total_capital'];
            $rows = [];
            foreach ($cuotas as $c) {
                $saldo = round($saldo - $c['capital'], 2);
                $rows[] = [
                    'prestamo_id' => $prestamoId,
                    'numero_cuota' => $c['numero'],
                    'fecha_vencimiento' => $c['fecha_venc'],
                    'capital' => $c['capital'],
                    'interes' => $c['interes'],
                    'total_cuota' => $c['total'],
                    'capital_adeudado_post' => max(0.0, $saldo),
                    'estado' => 'PENDIENTE',
                ];
            }
            DB::table('erp_prestamos_cuotas')->insert($rows);

            $this->audit->logEvento(
                accion: 'PRESTAMO_PLAN_AFIP_IMPORTADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Plan AFIP %s importado como préstamo #%d (%d cuotas, capital $%.2f)',
                    $numero, $prestamoId, count($cuotas), $plan['total_capital']),
                empresaId: $empresaId,
            );

            return ['prestamo_id' => $prestamoId, 'numero_plan' => $numero, 'cuotas' => count($cuotas)];
        });
    }

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
     * @param  array{fecha_pago:string, importe_pagado:float|string, op_pago_id?:?int, recibo_cobro_id?:?int, medio_pago_id?:?int, observaciones?:?string, usuario_id:int}  $data
     */
    public function pagarCuota(int $prestamoId, int $cuotaId, array $data): void
    {
        $cuota = DB::table('erp_prestamos_cuotas')->where('id', $cuotaId)
            ->where('prestamo_id', $prestamoId)->first();
        if (! $cuota) throw new DomainException("CUOTA_NO_ENCONTRADA");
        if ($cuota->estado === 'PAGADA') throw new DomainException("CUOTA_YA_PAGADA");
        $prestamo = DB::table('erp_prestamos')->where('id', $prestamoId)->first();

        DB::transaction(function () use ($prestamoId, $cuotaId, $cuota, $prestamo, $data) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $importe = round((float) $data['importe_pagado'], 2);
            $asientoId = null;
            // Si se indicó medio de pago (cuenta bancaria) y el préstamo tiene
            // cuenta contable, generamos el asiento: D capital (cuenta del plan,
            // con auxiliar) + D intereses/mora + H banco. Sin medio_pago_id se
            // mantiene el comportamiento previo (solo seguimiento, sin asiento).
            if (! empty($data['medio_pago_id']) && $prestamo && $prestamo->cuenta_contable_id) {
                $asientoId = $this->asentarPagoCuota($prestamo, $cuota, $importe, (int) $data['medio_pago_id'], (string) $data['fecha_pago'], (int) $data['usuario_id']);
            }

            DB::table('erp_prestamos_cuotas')->where('id', $cuotaId)->update([
                'estado' => 'PAGADA',
                'fecha_pago' => $data['fecha_pago'],
                'importe_pagado' => $importe,
                'op_pago_id' => $data['op_pago_id'] ?? null,
                'recibo_cobro_id' => $data['recibo_cobro_id'] ?? null,
                'asiento_id' => $asientoId,
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
                descripcion: sprintf('Préstamo #%d cuota #%d pagada $%.2f%s',
                    $prestamoId, $cuota->numero_cuota, $importe, $asientoId ? " (asiento #{$asientoId})" : ''),
            );
        });
    }

    /**
     * Genera el asiento del pago de una cuota de préstamo recibido (típicamente
     * un plan AFIP). D capital (cuenta del plan, con auxiliar de la contraparte)
     * + D intereses/mora a su cuenta de gasto + H banco (medio de pago). El
     * capital se imputa primero; el resto del importe va a intereses → siempre
     * cuadra.
     */
    private function asentarPagoCuota(object $prestamo, object $cuota, float $importe, int $medioPagoId, string $fechaPago, int $usuarioId): int
    {
        $empresaId = (int) $prestamo->empresa_id;

        $ctaMedioId = (int) DB::table('erp_cuentas_bancarias')->where('id', $medioPagoId)->value('cuenta_contable_id');
        if (! $ctaMedioId) throw new DomainException('MEDIO_PAGO_SIN_CUENTA: el medio de pago no tiene cuenta contable.');
        $ctaInteresId = (int) DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('codigo', self::CUENTA_INTERES_FISCAL)->value('id');
        if (! $ctaInteresId) throw new DomainException('CUENTA_INTERES_NO_EXISTE: falta '.self::CUENTA_INTERES_FISCAL.'.');

        $diarioId = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'TES')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
        if (! $diarioId) throw new DomainException('DIARIO_NO_EXISTE: falta el diario TES/GEN.');
        $ccGeneral = DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');

        $cc = fn (int $ctaId) => $this->admiteCc($ctaId) ? $ccGeneral : null;
        $capital = min(round((float) $cuota->capital, 2), $importe);
        $intereses = round($importe - $capital, 2); // financiero + eventual resarcitorio

        $ctaPlanId = (int) $prestamo->cuenta_contable_id;
        $movs = [[
            'cuenta_id' => $ctaPlanId,
            'centro_costo_id' => $cc($ctaPlanId),
            'auxiliar_id' => (int) $prestamo->contraparte_auxiliar_id,
            'debe' => $capital,
            'haber' => 0,
            'glosa' => 'Capital cuota '.$cuota->numero_cuota.' — '.$prestamo->nombre,
        ]];
        if ($intereses > 0) {
            $movs[] = [
                'cuenta_id' => $ctaInteresId,
                'centro_costo_id' => $cc($ctaInteresId),
                'auxiliar_id' => null,
                'debe' => $intereses,
                'haber' => 0,
                'glosa' => 'Intereses cuota '.$cuota->numero_cuota.' — '.$prestamo->nombre,
            ];
        }
        $movs[] = [
            'cuenta_id' => $ctaMedioId,
            'centro_costo_id' => $cc($ctaMedioId),
            'auxiliar_id' => null,
            'debe' => 0,
            'haber' => $importe,
            'glosa' => 'Pago cuota '.$cuota->numero_cuota.' — '.$prestamo->nombre,
        ];

        $asiento = $this->asientos->crearBorrador([
            'empresa_id' => $empresaId,
            'diario_id' => $diarioId,
            'fecha' => $fechaPago,
            'glosa' => sprintf('Pago cuota %d/%d — %s', $cuota->numero_cuota, $prestamo->plazo_cuotas, $prestamo->nombre),
            'origen' => 'PAGO',
            'origen_id' => (int) $prestamo->id,
            'origen_tabla' => 'erp_prestamos',
            'usuario_id' => $usuarioId,
            'movimientos' => $movs,
        ]);
        $asiento = $this->asientos->contabilizar($asiento);
        return (int) $asiento->id;
    }

    private function admiteCc(int $cuentaId): bool
    {
        return (bool) DB::table('erp_cuentas_contables')->where('id', $cuentaId)->value('admite_cc');
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

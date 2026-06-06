<?php

namespace App\Erp\Services;

use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.42 Fase B — Flujo de Fondos matricial.
 *
 * Responsabilidades:
 *  - ABM de escenarios (REALISTA/OPTIMISTA/PESIMISTA/CUSTOM) por año.
 *  - Matriz proyectado vs real con granularidad MES/SEMANA/DIA.
 *  - Override manual con motivo (≥10 chars).
 *  - Recálculo del proyectado a partir de:
 *     · facturas de venta pendientes + calendario de cobros (auto_calculable),
 *     · calendario fiscal recurrente,
 *     · órdenes de pago programadas (cuando estén).
 *  - Captura de "real" a partir de recibos / OPs / extractos contabilizados.
 *  - Clonar escenario.
 */
class FlujoFondosService
{
    public const VARIANCE_THRESHOLD = 15.0;

    public function __construct(private readonly AuditLogger $audit) {}

    public function listarEscenarios(int $empresaId, ?int $anio = null): array
    {
        $q = DB::table('erp_flujo_escenarios')
            ->where('empresa_id', $empresaId)
            ->orderByDesc('anio')->orderBy('tipo');
        if ($anio) $q->where('anio', $anio);
        return $q->get()->all();
    }

    /**
     * @param  array{empresa_id:int, nombre:string, tipo:string, anio:int, descripcion?:?string, es_default?:bool, usuario_id:int}  $data
     */
    public function crearEscenario(array $data): int
    {
        $exists = DB::table('erp_flujo_escenarios')
            ->where('empresa_id', $data['empresa_id'])
            ->where('nombre', $data['nombre'])
            ->where('anio', $data['anio'])
            ->exists();
        if ($exists) {
            throw new DomainException("ESCENARIO_DUPLICADO: ya existe {$data['nombre']} {$data['anio']}.");
        }
        $id = DB::table('erp_flujo_escenarios')->insertGetId([
            'empresa_id' => $data['empresa_id'],
            'nombre' => $data['nombre'],
            'tipo' => $data['tipo'],
            'anio' => $data['anio'],
            'descripcion' => $data['descripcion'] ?? null,
            'es_default' => (bool) ($data['es_default'] ?? false),
            'creado_por_user_id' => $data['usuario_id'],
            'estado' => 'BORRADOR',
        ]);
        $this->audit->logEvento(
            accion: 'FLUJO_ESCENARIO_CREADO',
            modulo: 'tesoreria',
            descripcion: sprintf('Escenario flujo %s %d (%s) id=%d', $data['nombre'], $data['anio'], $data['tipo'], $id),
            empresaId: $data['empresa_id'],
        );
        return $id;
    }

    /**
     * Clona todas las líneas de un escenario origen a uno destino nuevo,
     * opcionalmente aplicando un factor multiplicador a los proyectados.
     */
    public function clonarEscenario(int $origenId, array $destino, float $factorProyectado = 1.0): int
    {
        $origen = DB::table('erp_flujo_escenarios')->find($origenId);
        if (! $origen) throw new DomainException("ESCENARIO_ORIGEN_NO_ENCONTRADO");

        $nuevoId = $this->crearEscenario([
            'empresa_id' => $origen->empresa_id,
            'nombre' => $destino['nombre'],
            'tipo' => $destino['tipo'],
            'anio' => $destino['anio'] ?? $origen->anio,
            'descripcion' => $destino['descripcion'] ?? "Clon de #{$origenId} (factor x{$factorProyectado})",
            'usuario_id' => $destino['usuario_id'],
        ]);

        // Clonar líneas con factor opcional.
        $rows = DB::table('erp_flujo_lineas')->where('escenario_id', $origenId)->get();
        $batch = [];
        foreach ($rows as $r) {
            $batch[] = [
                'escenario_id' => $nuevoId,
                'categoria_id' => $r->categoria_id,
                'fecha' => $r->fecha,
                'anio' => $r->anio, 'mes' => $r->mes,
                'semana_iso' => $r->semana_iso, 'semana_mes' => $r->semana_mes,
                'importe_proyectado' => round($r->importe_proyectado * $factorProyectado, 2),
                'importe_real' => null,
                'moneda' => $r->moneda,
                'origen' => 'PROYECCION_MANUAL',
                'observaciones' => 'Clon de escenario #' . $origenId,
            ];
            if (count($batch) >= 500) {
                DB::table('erp_flujo_lineas')->insert($batch);
                $batch = [];
            }
        }
        if (! empty($batch)) DB::table('erp_flujo_lineas')->insert($batch);

        return $nuevoId;
    }

    /**
     * Devuelve la matriz {categoria_id, periodo_key: {proy, real, override?}} para
     * una granularidad y rango.
     *
     * @return array{
     *   escenario: object,
     *   categorias: array<int,object>,
     *   periodos: array<int,array{key:string,label:string,fecha_desde:string,fecha_hasta:string}>,
     *   celdas: array<string, array{proy:float, real:?float, override_manual:bool, override_count:int}>
     * }
     */
    public function matriz(int $escenarioId, string $granularidad = 'MES', ?string $desde = null, ?string $hasta = null): array
    {
        $escenario = DB::table('erp_flujo_escenarios')->find($escenarioId);
        if (! $escenario) throw new DomainException("ESCENARIO_NO_ENCONTRADO");

        $categorias = DB::table('erp_flujo_categorias')
            ->where('empresa_id', $escenario->empresa_id)
            ->where('activa', 1)
            ->orderBy('tipo')->orderBy('orden_presentacion')->orderBy('codigo')
            ->get()->all();

        // Construir periodos según granularidad.
        $anio = $escenario->anio;
        $desde ??= "{$anio}-01-01";
        $hasta ??= "{$anio}-12-31";
        $periodos = $this->construirPeriodos($granularidad, $desde, $hasta);

        // Cargar todas las líneas del escenario en el rango.
        $lineas = DB::table('erp_flujo_lineas')
            ->where('escenario_id', $escenarioId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->get();

        // Agrupar a la celda (categoria + periodo_key).
        $celdas = [];
        foreach ($lineas as $l) {
            $key = $this->periodoKey($granularidad, (string) $l->fecha, (int) $l->anio, (int) $l->mes, (int) $l->semana_iso);
            $cellKey = $l->categoria_id . '::' . $key;
            if (! isset($celdas[$cellKey])) {
                $celdas[$cellKey] = ['proy' => 0.0, 'real' => null, 'override_manual' => false, 'override_count' => 0];
            }
            $celdas[$cellKey]['proy'] += (float) $l->importe_proyectado;
            if ($l->importe_real !== null) {
                $celdas[$cellKey]['real'] = ($celdas[$cellKey]['real'] ?? 0) + (float) $l->importe_real;
            }
            if ((int) $l->override_manual === 1) {
                $celdas[$cellKey]['override_manual'] = true;
                $celdas[$cellKey]['override_count']++;
            }
        }

        return [
            'escenario' => $escenario,
            'categorias' => $categorias,
            'periodos' => $periodos,
            'celdas' => $celdas,
        ];
    }

    /**
     * Override de la celda (categoria + periodo) con motivo.
     * Crea una línea PROYECCION_MANUAL con override_manual=TRUE, fechada al primer día del período.
     */
    public function overrideCelda(int $escenarioId, int $categoriaId, string $periodoKey, float $nuevoProyectado, string $motivo, int $usuarioId): int
    {
        if (strlen(trim($motivo)) < 10) {
            throw new DomainException('MOTIVO_OVERRIDE_CORTO: mínimo 10 caracteres.');
        }
        // Resolver fecha desde periodoKey
        [$fecha, $anio, $mes, $semIso, $semMes] = $this->resolverFechaDesdeKey($periodoKey);

        return DB::transaction(function () use ($escenarioId, $categoriaId, $fecha, $anio, $mes, $semIso, $semMes, $nuevoProyectado, $motivo, $usuarioId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            // Marcar previas del mismo periodo como observaciones (no las borramos, queda histórico).
            DB::table('erp_flujo_lineas')
                ->where('escenario_id', $escenarioId)
                ->where('categoria_id', $categoriaId)
                ->where('fecha', $fecha)
                ->where('override_manual', false)
                ->update(['observaciones' => DB::raw("CONCAT(COALESCE(observaciones,''),' [reemplazado por override ' . $usuarioId . ']')")]);

            $id = DB::table('erp_flujo_lineas')->insertGetId([
                'escenario_id' => $escenarioId,
                'categoria_id' => $categoriaId,
                'fecha' => $fecha,
                'anio' => $anio, 'mes' => $mes,
                'semana_iso' => $semIso, 'semana_mes' => $semMes,
                'importe_proyectado' => $nuevoProyectado,
                'moneda' => 'ARS',
                'origen' => 'PROYECCION_MANUAL',
                'override_manual' => true,
                'motivo_override' => $motivo,
                'override_por_user_id' => $usuarioId,
                'fecha_override' => now(),
            ]);
            $this->audit->logEvento(
                accion: 'FLUJO_OVERRIDE_MANUAL',
                modulo: 'tesoreria',
                descripcion: sprintf('Escenario #%d categoria #%d fecha %s = $%.2f. Motivo: %s',
                    $escenarioId, $categoriaId, $fecha, $nuevoProyectado, $motivo),
            );
            return $id;
        });
    }

    /**
     * Recálculo MVP del proyectado: genera líneas a partir del calendario fiscal recurrente.
     * Borra líneas auto previas (PROYECCION_AUTO_CALENDARIO) y las regenera.
     */
    public function recalcular(int $escenarioId, int $usuarioId): array
    {
        $escenario = DB::table('erp_flujo_escenarios')->find($escenarioId);
        if (! $escenario) throw new DomainException("ESCENARIO_NO_ENCONTRADO");

        return DB::transaction(function () use ($escenario, $escenarioId, $usuarioId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            DB::table('erp_flujo_lineas')
                ->where('escenario_id', $escenarioId)
                ->whereIn('origen', ['PROYECCION_AUTO_CALENDARIO', 'PROYECCION_AUTO_FACTURA'])
                ->where('override_manual', false)
                ->delete();

            $fiscal = DB::table('erp_flujo_calendario_fiscal')->where('activo', 1)->get();
            $batch = [];
            foreach ($fiscal as $f) {
                foreach ($this->fechasParaPeriodicidad($escenario->anio, $f->periodicidad, (int) $f->dia_vencimiento) as $fecha) {
                    $c = Carbon::parse($fecha);
                    $batch[] = [
                        'escenario_id' => $escenarioId,
                        'categoria_id' => $f->categoria_id,
                        'fecha' => $fecha,
                        'anio' => $c->year, 'mes' => $c->month,
                        'semana_iso' => $c->isoWeek, 'semana_mes' => (int) ceil($c->day / 7),
                        'importe_proyectado' => -1 * (float) ($f->importe_referencial ?? 0),
                        'moneda' => 'ARS',
                        'origen' => 'PROYECCION_AUTO_CALENDARIO',
                    ];
                }
            }
            $insertados = 0;
            foreach (array_chunk($batch, 500) as $chunk) {
                DB::table('erp_flujo_lineas')->insert($chunk);
                $insertados += count($chunk);
            }

            $this->audit->logEvento(
                accion: 'FLUJO_RECALCULADO',
                modulo: 'tesoreria',
                descripcion: sprintf('Escenario #%d recalculado: %d líneas auto-calendario insertadas.', $escenarioId, $insertados),
                empresaId: $escenario->empresa_id,
            );

            return ['insertados_calendario' => $insertados];
        });
    }

    /**
     * Drill-down: lista de líneas que componen una celda.
     */
    public function drillCelda(int $escenarioId, int $categoriaId, string $periodoKey): array
    {
        [$fecha] = $this->resolverFechaDesdeKey($periodoKey);
        // Para granularidad MES/SEMANA, tomar el rango completo.
        [$desde, $hasta] = $this->rangoFromKey($periodoKey);
        return DB::table('erp_flujo_lineas')
            ->where('escenario_id', $escenarioId)
            ->where('categoria_id', $categoriaId)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('fecha')
            ->get()->all();
    }

    // -- helpers de períodos --------------------------------------------------

    /** @return array<int,array{key:string,label:string,fecha_desde:string,fecha_hasta:string}> */
    private function construirPeriodos(string $granularidad, string $desde, string $hasta): array
    {
        $start = Carbon::parse($desde)->startOfDay();
        $end = Carbon::parse($hasta)->endOfDay();
        $out = [];
        if ($granularidad === 'MES') {
            $cur = $start->copy()->startOfMonth();
            while ($cur <= $end) {
                $monthEnd = $cur->copy()->endOfMonth();
                $out[] = [
                    'key' => 'M-' . $cur->year . '-' . str_pad((string) $cur->month, 2, '0', STR_PAD_LEFT),
                    'label' => $cur->locale('es')->isoFormat('MMM YY'),
                    'fecha_desde' => $cur->toDateString(),
                    'fecha_hasta' => $monthEnd->toDateString(),
                ];
                $cur->addMonthNoOverflow();
            }
        } elseif ($granularidad === 'SEMANA') {
            $cur = $start->copy()->startOfWeek();
            while ($cur <= $end) {
                $weekEnd = $cur->copy()->endOfWeek();
                $out[] = [
                    'key' => 'W-' . $cur->isoWeekYear . '-' . str_pad((string) $cur->isoWeek, 2, '0', STR_PAD_LEFT),
                    'label' => 'S' . $cur->isoWeek . ' (' . $cur->format('d/m') . ')',
                    'fecha_desde' => $cur->toDateString(),
                    'fecha_hasta' => $weekEnd->toDateString(),
                ];
                $cur->addWeek();
            }
        } else { // DIA
            $cur = $start->copy();
            while ($cur <= $end) {
                $out[] = [
                    'key' => 'D-' . $cur->toDateString(),
                    'label' => $cur->format('d/m'),
                    'fecha_desde' => $cur->toDateString(),
                    'fecha_hasta' => $cur->toDateString(),
                ];
                $cur->addDay();
            }
        }
        return $out;
    }

    private function periodoKey(string $granularidad, string $fecha, int $anio, int $mes, int $semIso): string
    {
        if ($granularidad === 'MES') {
            return 'M-' . $anio . '-' . str_pad((string) $mes, 2, '0', STR_PAD_LEFT);
        }
        if ($granularidad === 'SEMANA') {
            $c = Carbon::parse($fecha);
            return 'W-' . $c->isoWeekYear . '-' . str_pad((string) $semIso, 2, '0', STR_PAD_LEFT);
        }
        return 'D-' . $fecha;
    }

    private function resolverFechaDesdeKey(string $key): array
    {
        if (str_starts_with($key, 'M-')) {
            [, $a, $m] = explode('-', $key);
            $c = Carbon::create((int) $a, (int) $m, 1);
            return [$c->toDateString(), $c->year, $c->month, $c->isoWeek, (int) ceil($c->day / 7)];
        }
        if (str_starts_with($key, 'W-')) {
            [, $y, $w] = explode('-', $key);
            $c = Carbon::now()->setISODate((int) $y, (int) $w)->startOfDay();
            return [$c->toDateString(), $c->year, $c->month, $c->isoWeek, (int) ceil($c->day / 7)];
        }
        // D-YYYY-MM-DD
        $c = Carbon::parse(substr($key, 2));
        return [$c->toDateString(), $c->year, $c->month, $c->isoWeek, (int) ceil($c->day / 7)];
    }

    /** @return array{0:string,1:string} */
    private function rangoFromKey(string $key): array
    {
        if (str_starts_with($key, 'M-')) {
            [, $a, $m] = explode('-', $key);
            $c = Carbon::create((int) $a, (int) $m, 1);
            return [$c->toDateString(), $c->copy()->endOfMonth()->toDateString()];
        }
        if (str_starts_with($key, 'W-')) {
            [, $y, $w] = explode('-', $key);
            $c = Carbon::now()->setISODate((int) $y, (int) $w);
            return [$c->copy()->startOfWeek()->toDateString(), $c->copy()->endOfWeek()->toDateString()];
        }
        $d = substr($key, 2);
        return [$d, $d];
    }

    /** @return array<int,string> */
    private function fechasParaPeriodicidad(int $anio, string $periodicidad, int $dia): array
    {
        $out = [];
        $meses = match ($periodicidad) {
            'MENSUAL' => range(1, 12),
            'BIMENSUAL' => [1, 3, 5, 7, 9, 11],
            'TRIMESTRAL' => [1, 4, 7, 10],
            'SEMESTRAL' => [1, 7],
            'ANUAL' => [1],
            default => [],
        };
        foreach ($meses as $m) {
            $maxDia = (int) Carbon::create($anio, $m, 1)->endOfMonth()->day;
            $diaSafe = min($dia, $maxDia);
            $out[] = Carbon::create($anio, $m, $diaSafe)->toDateString();
        }
        return $out;
    }
}

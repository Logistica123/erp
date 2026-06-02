<?php

namespace App\Erp\Http\Controllers\Reportes;

use App\Erp\Services\Reportes\SaldosConsolidadosService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * v1.37 — Endpoints del reporte de saldos consolidados.
 *
 *   GET /api/erp/reportes/saldos-consolidados
 *   GET /api/erp/reportes/saldos-consolidados/auxiliar/{id}
 *
 * Permisos:
 *   - reportes.saldos_consolidados.ver           → ver totales y aging (sin desglose efectivo)
 *   - reportes.saldos_consolidados.ver_efectivo  → además ver desglose "de los cuales en efectivo"
 *
 * Cache: 5 minutos por combinación de filtros + empresa. Botón "actualizar"
 * del frontend agrega ?nocache=1 para forzar recálculo.
 */
class SaldosConsolidadosController extends Controller
{
    public function __construct(
        private readonly SaldosConsolidadosService $svc,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'reportes.saldos_consolidados.ver');
        $verEfectivo = $this->tienePermiso($request, 'reportes.saldos_consolidados.ver_efectivo');

        $filtros = $this->extraerFiltros($request);
        // Cache key incluye los filtros relevantes para evitar mezclas.
        $key = $this->cacheKey('saldos_cons', $filtros);

        try {
            $data = $request->boolean('nocache')
                ? $this->svc->calcular($filtros)
                : Cache::remember($key, 300, fn () => $this->svc->calcular($filtros));
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'error' => [
                'code' => explode(':', $e->getMessage(), 2)[0] ?? 'DOMAIN',
                'message' => $e->getMessage(),
            ]], 422);
        }

        // Si el usuario NO tiene permiso ver_efectivo, ocultamos los desgloses.
        if (! $verEfectivo) {
            $data = $this->ocultarEfectivo($data);
        }
        $data['permisos'] = ['ver_efectivo' => $verEfectivo];

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function auxiliar(Request $request, int $id): JsonResponse
    {
        $this->mustHave($request, 'reportes.saldos_consolidados.ver');
        $verEfectivo = $this->tienePermiso($request, 'reportes.saldos_consolidados.ver_efectivo');

        $filtros = $this->extraerFiltros($request);
        $key = $this->cacheKey("saldos_cons_aux_{$id}", $filtros);

        $data = $request->boolean('nocache')
            ? $this->svc->detalleAuxiliar($id, $filtros)
            : Cache::remember($key, 300, fn () => $this->svc->detalleAuxiliar($id, $filtros));

        if (! $verEfectivo && isset($data['operaciones'])) {
            // Filtra las operaciones EFECTIVO y elimina del total.
            $data['operaciones'] = array_values(array_filter(
                $data['operaciones'],
                fn ($op) => ($op->categoria ?? '') !== 'EFECTIVO'
            ));
            if (isset($data['totales'])) {
                $data['totales']['efectivo'] = 0.0;
                $data['totales']['total'] = array_sum(array_map(
                    fn ($op) => (float) ($op->saldo ?? 0),
                    $data['operaciones']
                ));
            }
        }

        $data['permisos'] = ['ver_efectivo' => $verEfectivo];

        return response()->json(['ok' => true, 'data' => $data]);
    }

    // ------------------------------------------------------------------------

    private function extraerFiltros(Request $request): array
    {
        return array_filter([
            'empresa_id'       => (int) ($request->header('X-Empresa-Id') ?: 1),
            'fecha_corte'      => $request->query('fecha_corte'),
            'moneda_codigo'    => $request->query('moneda_codigo', 'ARS'),
            'incluir_efectivo' => $request->boolean('incluir_efectivo', true),
            'top_n'            => $request->query('top_n'),
        ], fn ($v) => $v !== null && $v !== '');
    }

    private function cacheKey(string $prefix, array $filtros): string
    {
        ksort($filtros);
        return "v1.37.{$prefix}." . md5(json_encode($filtros));
    }

    /**
     * Elimina los campos de desglose efectivo cuando el usuario no tiene
     * permiso. Conserva el total (todos pueden ver el total general).
     */
    private function ocultarEfectivo(array $data): array
    {
        foreach (['deudores_ventas', 'deuda_compras'] as $k) {
            if (isset($data['widgets'][$k])) {
                unset($data['widgets'][$k]['efectivo'], $data['widgets'][$k]['pct_efectivo']);
            }
        }
        foreach (['aging_deudores', 'aging_acreedores'] as $k) {
            if (! isset($data[$k])) continue;
            foreach ($data[$k] as $bucket => &$b) {
                unset($b['efectivo']);
            }
            unset($b);
        }
        foreach (['top_deudores', 'top_acreedores'] as $k) {
            if (! isset($data[$k])) continue;
            foreach ($data[$k] as &$row) {
                unset($row['saldo_efectivo']);
            }
            unset($row);
        }
        return $data;
    }

    private function mustHave(Request $request, string $codigo): void
    {
        if (! $this->tienePermiso($request, $codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }

    private function tienePermiso(Request $request, string $codigo): bool
    {
        $perfil = $request->user()?->erpPerfil;
        return $perfil && $perfil->tienePermiso($codigo);
    }
}

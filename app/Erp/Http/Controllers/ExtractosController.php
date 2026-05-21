<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Tesoreria\ExtractoImporterService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints de extractos bancarios (SPEC 02 §6.2).
 *
 *   POST   /api/erp/extractos/importar        multipart: cuenta_id + archivo
 *   GET    /api/erp/extractos                  ?cuenta_id=&desde=&hasta=
 *   GET    /api/erp/extractos/{id}/movimientos
 *   DELETE /api/erp/extractos/{id}             solo si ningún movimiento CONCILIADO
 */
class ExtractosController
{
    public function __construct(private readonly ExtractoImporterService $importer) {}

    public function importar(Request $request): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.cargar');
        $data = $request->validate([
            'cuenta_id' => ['required', 'integer', 'exists:erp_cuentas_bancarias,id'],
            'archivo' => ['required', 'file', 'max:20480'], // 20 MB
        ]);

        $cuenta = CuentaBancaria::with(['banco', 'moneda'])->findOrFail($data['cuenta_id']);
        $archivo = $request->file('archivo');

        try {
            $resumen = $this->importer->importar(
                pathTemporal: $archivo->getRealPath(),
                cuenta: $cuenta,
                usuario: $request->user(),
                nombreArchivo: $archivo->getClientOriginalName(),
            );
        } catch (DomainException $e) {
            $code = explode(':', $e->getMessage(), 2)[0];

            return response()->json([
                'ok' => false,
                'error' => ['code' => $code, 'message' => $e->getMessage()],
            ], 409);
        }

        return response()->json(['ok' => true, 'data' => $resumen], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'cuenta_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
        ]);

        $query = ExtractoBancario::query()
            ->with(['cuentaBancaria:id,codigo,nombre,banco_id', 'cuentaBancaria.banco:id,codigo,nombre'])
            ->when($data['cuenta_id'] ?? null, fn ($q, $v) => $q->where('cuenta_bancaria_id', $v))
            ->when($data['desde'] ?? null, fn ($q, $v) => $q->where('fecha_desde', '>=', $v))
            ->when($data['hasta'] ?? null, fn ($q, $v) => $q->where('fecha_hasta', '<=', $v))
            ->orderByDesc('importado_at');

        return response()->json(['ok' => true, 'data' => $query->paginate(50)]);
    }

    public function movimientos(int $id): JsonResponse
    {
        $extracto = ExtractoBancario::findOrFail($id);

        $movs = MovimientoBancario::where('extracto_id', $extracto->id)
            ->orderBy('fecha')
            ->orderBy('id')
            ->paginate(200);

        return response()->json(['ok' => true, 'data' => ['extracto' => $extracto, 'movimientos' => $movs]]);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->requierePermiso(request(), 'tesoreria.extractos.borrar');
        $extracto = ExtractoBancario::findOrFail($id);

        $conciliados = MovimientoBancario::where('extracto_id', $extracto->id)
            ->where('estado', 'CONCILIADO')
            ->count();

        if ($conciliados > 0) {
            return response()->json([
                'ok' => false,
                'error' => [
                    'code' => 'MOVIMIENTOS_CONCILIADOS',
                    'message' => "No se puede eliminar: {$conciliados} movimiento(s) ya conciliado(s). Desconciliá primero.",
                ],
            ], 409);
        }

        MovimientoBancario::where('extracto_id', $extracto->id)->delete();
        $extracto->delete();

        return response()->json(['ok' => true]);
    }

    private function requierePermiso(Request $request, string $codigo): void
    {
        $perfil = $request->user()?->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => [
                'code' => 'NO_AUTORIZADO',
                'message' => "Falta permiso {$codigo}",
            ]], 403));
        }
    }
}

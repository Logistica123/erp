<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\ExtractoBancario;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\Tesoreria\ExtractoImporterService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
    public function __construct(
        private readonly ExtractoImporterService $importer,
        private readonly AuditLogger $audit,
    ) {}

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

    /**
     * Borra un import de extracto bancario completo (super_admin).
     * Validación: rechaza con 409 si algún movimiento tiene asiento contable
     * generado (CONCILIADO/CONFIRMADO o asiento_id) o está vinculado a otra
     * operación (eCheq, cobro, transferencia interna, ajuste retroactivo).
     * Audit log inmutable con snapshot antes del DELETE (idem v1.20).
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $this->requierePermiso($request, 'tesoreria.extractos.borrar_import');
        $data = $request->validate(['motivo' => ['nullable', 'string', 'max:500']]);
        $extracto = ExtractoBancario::findOrFail($id);

        $movs = MovimientoBancario::where('extracto_id', $extracto->id)->get(['id', 'estado', 'asiento_id']);
        $movIds = $movs->pluck('id')->all();

        // 1. Bloqueo por asiento contable generado.
        $conAsiento = $movs->filter(fn ($m) => $m->asiento_id || in_array($m->estado, ['CONCILIADO', 'CONFIRMADO'], true))->count();
        if ($conAsiento > 0) {
            return response()->json(['ok' => false, 'error' => [
                'code' => 'MOVIMIENTOS_CON_ASIENTO',
                'message' => "No se puede borrar: {$conAsiento} movimiento(s) tienen asiento contable (conciliados/confirmados). Desconciliá o revertí primero.",
            ]], 409);
        }

        // 2. Bloqueo por vínculos de negocio (eCheq, cobros, transferencias, ajustes).
        if (! empty($movIds)) {
            $vinculos = [
                'eCheq' => DB::table('erp_echeq')->whereIn('movimiento_bancario_id', $movIds)->count(),
                'cobros' => DB::table('erp_cobro_medios')->whereIn('movimiento_bancario_id', $movIds)->count(),
                'transferencias internas' => DB::table('erp_transferencias_internas')
                    ->where(fn ($q) => $q->whereIn('movimiento_origen_id', $movIds)->orWhereIn('movimiento_destino_id', $movIds))->count(),
                'ajustes retroactivos' => DB::table('erp_ajustes_retroactivos')->whereIn('movimiento_origen_id', $movIds)->count(),
            ];
            $conVinculo = array_filter($vinculos);
            if (! empty($conVinculo)) {
                $detalle = collect($conVinculo)->map(fn ($n, $k) => "{$n} {$k}")->implode(', ');
                return response()->json(['ok' => false, 'error' => [
                    'code' => 'MOVIMIENTOS_VINCULADOS',
                    'message' => "No se puede borrar: hay movimientos vinculados a otras operaciones ({$detalle}). Desvinculá primero.",
                ]], 409);
            }
        }

        // 3. Snapshot al audit log ANTES del delete.
        $this->audit->logEvento(
            accion: 'EXTRACTO_IMPORT_BORRADO',
            modulo: 'tesoreria',
            descripcion: sprintf(
                'Borrado import extracto #%d (%s, cuenta #%d, %s a %s, %d movs). Motivo: %s',
                $extracto->id, $extracto->nombre_archivo, $extracto->cuenta_bancaria_id,
                (string) $extracto->fecha_desde, (string) $extracto->fecha_hasta,
                count($movIds), $data['motivo'] ?? '(sin motivo)',
            ),
            empresaId: (int) (DB::table('erp_cuentas_bancarias')->where('id', $extracto->cuenta_bancaria_id)->value('empresa_id') ?: 1),
        );

        // 4. Delete transaccional + limpieza de FKs blandas + archivo.
        DB::transaction(function () use ($extracto, $movIds) {
            DB::statement('SET @erp_current_user_id = ?', [request()->user()->id]);
            if (! empty($movIds)) {
                DB::table('erp_conciliaciones')->whereIn('movimiento_bancario_id', $movIds)->delete();
                DB::table('erp_extractos_imputaciones_audit')->whereIn('movimiento_id', $movIds)->delete();
            }
            MovimientoBancario::where('extracto_id', $extracto->id)->delete();
            $extracto->delete();
        });

        // 5. Borrar el archivo físico (best-effort, fuera de la transacción).
        if ($extracto->ruta_archivo) {
            try { \Illuminate\Support\Facades\Storage::disk('local')->delete($extracto->ruta_archivo); } catch (\Throwable $e) {}
        }

        return response()->json(['ok' => true, 'data' => ['movimientos_borrados' => count($movIds)]]);
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

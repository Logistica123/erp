<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\AuditLog;
use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoints del log inmutable de auditoría (SPEC_01 §5.1, RN-7).
 *
 * GET  /api/erp/auditoria                         Listado paginado filtrable
 * GET  /api/erp/auditoria/verificar-cadena        Recomputa la cadena de hashes
 * GET  /api/erp/auditoria/verificar-integridad-asientos  Detecta tampering en asientos contabilizados
 */
class AuditoriaController
{
    public function __construct(
        private readonly AuditLogger $logger,
        private readonly AsientoService $asientos,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filtros = $request->validate([
            'modulo' => ['nullable', 'string'],
            'entidad' => ['nullable', 'string'],
            'entidad_id' => ['nullable', 'integer'],
            'accion' => ['nullable', 'string'],
            'user_id' => ['nullable', 'integer'],
            'desde' => ['nullable', 'date'],
            'hasta' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $query = AuditLog::query()
            ->when($filtros['modulo'] ?? null, fn ($q, $v) => $q->where('modulo', $v))
            ->when($filtros['entidad'] ?? null, fn ($q, $v) => $q->where('entidad', $v))
            ->when($filtros['entidad_id'] ?? null, fn ($q, $v) => $q->where('entidad_id', $v))
            ->when($filtros['accion'] ?? null, fn ($q, $v) => $q->where('accion', $v))
            ->when($filtros['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filtros['desde'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filtros['hasta'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('id');

        $perPage = (int) ($filtros['per_page'] ?? 50);

        return response()->json($query->paginate($perPage));
    }

    public function show(int $id): JsonResponse
    {
        $row = AuditLog::findOrFail($id);
        return response()->json(['data' => $row]);
    }

    public function verificarCadena(Request $request): JsonResponse
    {
        $empresaId = $request->query('empresa_id')
            ? (int) $request->query('empresa_id')
            : null;

        $resultado = $this->logger->verificarCadena($empresaId);

        if ($resultado === null) {
            return response()->json(['ok' => true, 'message' => 'Cadena de auditoría íntegra.']);
        }

        return response()->json(['ok' => false, 'falla' => $resultado], 409);
    }

    public function verificarIntegridadAsientos(Request $request): JsonResponse
    {
        $empresaId = $request->query('empresa_id') ? (int) $request->query('empresa_id') : null;
        $periodoId = $request->query('periodo_id') ? (int) $request->query('periodo_id') : null;

        $fallas = $this->asientos->verificarIntegridadAsientos($empresaId, $periodoId);

        if (empty($fallas)) {
            return response()->json(['ok' => true, 'message' => 'Todos los asientos tienen hash válido.']);
        }

        return response()->json(['ok' => false, 'fallas' => $fallas], 409);
    }
}

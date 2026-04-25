<?php

namespace App\Erp\Http\Controllers\Eecc;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\EeccEmision;
use App\Erp\Services\Eecc\EecCPdfService;
use App\Erp\Services\Eecc\EecCService;
use App\Erp\Services\Eecc\NotasService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Estados Contables (SPEC 05 §6.9).
 *
 *   POST  /eecc/{ejercicio_id}/generar              — arma + emite PDF
 *   GET   /eecc/{ejercicio_id}/preview              — JSON (no persiste)
 *   GET   /eecc/{ejercicio_id}/descargar            — última emisión
 *   GET   /eecc/{ejercicio_id}/notas                — lista
 *   PATCH /eecc/{ejercicio_id}/notas/{numero}       — edita
 *   GET   /eecc/{ejercicio_id}/emisiones            — historial
 */
class EecCController extends Controller
{
    public function __construct(
        private readonly EecCService $eecc,
        private readonly EecCPdfService $pdf,
        private readonly NotasService $notas,
    ) {}

    public function preview(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $incluir = $this->parseIncluir($request->query('incluir', 'BG,ER,EPN,EFE,NOTAS'));
        $paquete = $this->eecc->armar($ejercicio, $incluir);
        return response()->json(['ok' => true, 'data' => $paquete]);
    }

    public function generar(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);

        $datos = $request->validate([
            'incluir'              => ['nullable', 'array'],
            'incluir.*'            => ['in:BG,ER,EPN,EFE,NOTAS'],
            'formato'              => ['nullable', 'in:PDF,DOCX,XLSX'],
            'profesional_firmante' => ['nullable', 'string', 'max:200'],
            'matricula_firmante'   => ['nullable', 'string', 'max:60'],
            'observaciones'        => ['nullable', 'string'],
        ]);

        $formato = $datos['formato'] ?? 'PDF';
        if ($formato !== 'PDF') {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'EECC_FORMATO_PENDIENTE',
                    'message' => "Formato {$formato} pendiente — sólo PDF activo en H8 V1"],
            ], Response::HTTP_NOT_IMPLEMENTED);
        }

        try {
            $res = $this->pdf->generar($ejercicio, $request->user(), [
                'incluir'              => $datos['incluir'] ?? ['BG', 'ER', 'EPN', 'EFE', 'NOTAS'],
                'profesional_firmante' => $datos['profesional_firmante'] ?? null,
                'matricula_firmante'   => $datos['matricula_firmante'] ?? null,
                'observaciones'        => $datos['observaciones'] ?? null,
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $res], Response::HTTP_CREATED);
    }

    public function descargar(int $ejercicioId, Request $request): BinaryFileResponse|JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $emision = EeccEmision::where('ejercicio_id', $ejercicio->id)
            ->where('formato', strtoupper((string) $request->query('formato', 'PDF')))
            ->orderByDesc('generado_at')
            ->first();

        if (! $emision || ! Storage::disk('local')->exists($emision->path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'EECC_NO_GENERADO']], 404);
        }

        $anio = (int) $ejercicio->fecha_cierre->format('Y');
        $nombre = "EECC_{$anio}.".strtolower($emision->formato);
        return Storage::disk('local')->download($emision->path, $nombre);
    }

    public function notas(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $rows = $this->notas->paraEjercicio($ejercicio);
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function editarNota(int $ejercicioId, int $numero, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $datos = $request->validate(['contenido' => ['required', 'string']]);

        try {
            $nota = $this->notas->actualizar($ejercicio, $numero, $datos['contenido'], $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $nota]);
    }

    public function emisiones(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $rows = EeccEmision::where('ejercicio_id', $ejercicio->id)
            ->orderByDesc('generado_at')->paginate(50);
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    private function ejercicio(int $id, Request $request): Ejercicio
    {
        return Ejercicio::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
    }

    private function parseIncluir(string $raw): array
    {
        $valid = ['BG', 'ER', 'EPN', 'EFE', 'NOTAS'];
        $items = array_values(array_unique(array_filter(
            array_map('trim', explode(',', strtoupper($raw))),
            fn ($v) => in_array($v, $valid, true)
        )));
        return $items ?: $valid;
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}

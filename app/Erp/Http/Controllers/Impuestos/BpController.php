<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Ejercicio;
use App\Erp\Models\Impuestos\BpParticipacion;
use App\Erp\Models\Impuestos\EmpresaSocio;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Services\Impuestos\BpCalculator;
use App\Erp\Services\Impuestos\BpF2000Service;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Bienes Personales F.2000 + CRUD socios (SPEC 05 §6.7).
 *
 *   GET  /impuestos/bp/{ejercicio_id}                        — detalle
 *   POST /impuestos/bp/{ejercicio_id}/calcular               — VPP por socio
 *   POST /impuestos/bp/{ejercicio_id}/generar-f2000          — TXT
 *   GET  /impuestos/bp/{ejercicio_id}/descargar              — download
 *
 *   GET  /impuestos/bp/socios                                — listado
 *   POST /impuestos/bp/socios                                — alta
 *   PATCH /impuestos/bp/socios/{id}                          — edita
 *   DELETE /impuestos/bp/socios/{id}                         — baja (soft via fecha_baja)
 */
class BpController extends Controller
{
    public function __construct(
        private readonly BpCalculator $calculator,
        private readonly BpF2000Service $f2000,
    ) {}

    public function show(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $bp = BpParticipacion::where('ejercicio_id', $ejercicio->id)->first();

        return response()->json(['ok' => true, 'data' => [
            'ejercicio' => $ejercicio, 'liquidacion' => $bp,
            'pn_contable' => $this->calculator->patrimonioNetoContable($ejercicio),
            'alicuota_vigente' => $this->safeAlicuota($ejercicio->fecha_cierre),
        ]]);
    }

    public function calcular(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $periodo = $this->periodoAnual($ejercicio);

        $datos = $request->validate([
            'pn_ajustado_override' => ['nullable', 'numeric', 'min:0'],
        ]);

        try {
            $bp = $this->calculator->calcular($periodo, $ejercicio, $request->user(), $datos);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $bp]);
    }

    public function generar(int $ejercicioId, Request $request): JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $bp = BpParticipacion::where('ejercicio_id', $ejercicio->id)->firstOrFail();
        $periodo = $bp->periodo;

        try {
            $res = $this->f2000->generar($periodo, $bp, $request->user());
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $res]);
    }

    public function descargar(int $ejercicioId, Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $ejercicio = $this->ejercicio($ejercicioId, $request);
        $bp = BpParticipacion::where('ejercicio_id', $ejercicio->id)->first();
        if (! $bp || ! $bp->archivo_f2000_path || ! Storage::disk('local')->exists($bp->archivo_f2000_path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'F2000_NO_GENERADO']], 404);
        }

        $anio = $bp->periodo->anio;
        return Storage::disk('local')->download($bp->archivo_f2000_path, "F2000_{$anio}.txt");
    }

    // ----- CRUD socios -----

    public function listSocios(Request $request): JsonResponse
    {
        $rows = EmpresaSocio::query()
            ->where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->when($request->boolean('solo_activos', true), fn ($q) => $q->where('activo', 1))
            ->orderBy('nombre')->get();

        $sumPct = (float) $rows->where('activo', 1)->whereNull('fecha_baja')->sum('porcentaje_participacion');

        return response()->json(['ok' => true, 'data' => $rows, 'meta' => [
            'cantidad' => $rows->count(),
            'suma_porcentaje' => $sumPct,
            'suma_correcta' => abs($sumPct - 100.0) <= 0.01,
        ]]);
    }

    public function storeSocio(Request $request): JsonResponse
    {
        $datos = $request->validate([
            'cuit'                     => ['required', 'string', 'size:11', 'regex:/^\d{11}$/'],
            'nombre'                   => ['required', 'string', 'max:200'],
            'tipo'                     => ['nullable', 'in:PERSONA_FISICA,PERSONA_JURIDICA'],
            'porcentaje_participacion' => ['required', 'numeric', 'min:0', 'max:100'],
            'fecha_alta'               => ['required', 'date'],
            'fecha_baja'               => ['nullable', 'date'],
            'observaciones'            => ['nullable', 'string'],
        ]);
        $datos['empresa_id'] = (int) ($request->header('X-Empresa-Id') ?: 1);
        $datos['tipo'] = $datos['tipo'] ?? 'PERSONA_FISICA';
        $datos['activo'] = 1;

        $socio = EmpresaSocio::create($datos);
        return response()->json(['ok' => true, 'data' => $socio], Response::HTTP_CREATED);
    }

    public function updateSocio(int $id, Request $request): JsonResponse
    {
        $socio = EmpresaSocio::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
        $datos = $request->validate([
            'nombre'                   => ['nullable', 'string', 'max:200'],
            'tipo'                     => ['nullable', 'in:PERSONA_FISICA,PERSONA_JURIDICA'],
            'porcentaje_participacion' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fecha_baja'               => ['nullable', 'date'],
            'activo'                   => ['nullable', 'boolean'],
            'observaciones'            => ['nullable', 'string'],
        ]);
        $socio->update($datos);
        return response()->json(['ok' => true, 'data' => $socio->fresh()]);
    }

    public function destroySocio(int $id, Request $request): JsonResponse
    {
        $socio = EmpresaSocio::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
        $socio->update([
            'activo' => 0,
            'fecha_baja' => now()->toDateString(),
        ]);
        return response()->json(['ok' => true, 'data' => $socio->fresh()]);
    }

    // ----- helpers -----

    private function ejercicio(int $id, Request $request): Ejercicio
    {
        return Ejercicio::where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->findOrFail($id);
    }

    private function periodoAnual(Ejercicio $ejercicio): PeriodoFiscal
    {
        $periodo = PeriodoFiscal::where('empresa_id', $ejercicio->empresa_id)
            ->where('impuesto', 'BP_PART')
            ->where('ejercicio_id', $ejercicio->id)
            ->whereNull('rectifica_a_id')
            ->first();

        if (! $periodo) {
            abort(404, 'Período BP_PART no existe — crear primero via /periodos');
        }
        return $periodo;
    }

    private function safeAlicuota($fecha): ?float
    {
        try {
            return $this->calculator->alicuotaVigente($fecha);
        } catch (DomainException) {
            return null;
        }
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false, 'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}

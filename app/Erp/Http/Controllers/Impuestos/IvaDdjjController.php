<?php

namespace App\Erp\Http\Controllers\Impuestos;

use App\Erp\Models\Impuestos\IvaDdjj;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Models\Tesoreria\OpItem;
use App\Erp\Services\Impuestos\IvaDdjjCalculator;
use App\Erp\Services\Impuestos\IvaDdjjF2002Service;
use App\Erp\Services\OrdenPagoService;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * DDJJ IVA F.2002 (SPEC 05 §6.3).
 *
 *   GET    /api/erp/impuestos/iva/{periodo_id}                — DDJJ calculada
 *   POST   /api/erp/impuestos/iva/{periodo_id}/calcular       — recalcula y persiste
 *   POST   /api/erp/impuestos/iva/{periodo_id}/generar-f2002  — genera TXT importable
 *   GET    /api/erp/impuestos/iva/{periodo_id}/descargar      — descarga el TXT
 *   POST   /api/erp/impuestos/iva/{periodo_id}/generar-op     — crea OP por importe a pagar
 */
class IvaDdjjController extends Controller
{
    public function __construct(
        private readonly IvaDdjjCalculator $calculator,
        private readonly IvaDdjjF2002Service $f2002,
        private readonly OrdenPagoService $opService,
    ) {}

    public function show(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $ddjj = IvaDdjj::where('periodo_id', $periodo->id)->first();

        return response()->json(['ok' => true, 'data' => [
            'periodo' => $periodo,
            'ddjj' => $ddjj,
        ]]);
    }

    public function calcular(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $datos = $request->validate(['pagos_a_cuenta' => ['nullable', 'numeric', 'min:0']]);

        try {
            $ddjj = $this->calculator->calcular($periodo, $request->user(), (float) ($datos['pagos_a_cuenta'] ?? 0));
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $ddjj]);
    }

    public function generar(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $datos = $request->validate(['pagos_a_cuenta' => ['nullable', 'numeric', 'min:0']]);

        try {
            $resultado = $this->f2002->generar($periodo, $request->user(), (float) ($datos['pagos_a_cuenta'] ?? 0));
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        return response()->json(['ok' => true, 'data' => $resultado]);
    }

    public function descargar(int $periodoId, Request $request): BinaryFileResponse|StreamedResponse|JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $ddjj = IvaDdjj::where('periodo_id', $periodo->id)->first();
        if (! $ddjj || ! $ddjj->archivo_f2002_path || ! Storage::disk('local')->exists($ddjj->archivo_f2002_path)) {
            return response()->json(['ok' => false, 'error' => ['code' => 'F2002_NO_GENERADO', 'message' => 'Generar F.2002 primero']], 404);
        }

        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        return Storage::disk('local')->download($ddjj->archivo_f2002_path, "F2002_{$periodo->anio}-{$mes}.txt");
    }

    /**
     * Crea una Orden de Pago por el `importe_a_pagar` de la DDJJ y la
     * vincula al período. Recibe `auxiliar_id` (AFIP), `moneda_id` y
     * `concepto` opcional. La OP queda en estado BORRADOR — el flujo
     * normal de Tesorería la completa.
     */
    public function generarOp(int $periodoId, Request $request): JsonResponse
    {
        $periodo = $this->periodo($periodoId, $request);
        $ddjj = IvaDdjj::where('periodo_id', $periodo->id)->first();

        if (! $ddjj) {
            return response()->json(['ok' => false, 'error' => ['code' => 'IVA_DDJJ_NO_CALCULADA', 'message' => 'Calcular DDJJ primero']], 409);
        }
        if ((float) $ddjj->importe_a_pagar <= 0) {
            return response()->json(['ok' => false, 'error' => ['code' => 'SIN_IMPORTE_A_PAGAR', 'message' => 'No hay importe a pagar para este período']], 409);
        }
        if ($ddjj->volante_pago_id) {
            return response()->json(['ok' => false, 'error' => ['code' => 'OP_YA_EXISTE', 'message' => 'Ya existe OP #'.$ddjj->volante_pago_id, 'op_id' => $ddjj->volante_pago_id]], 409);
        }

        $datos = $request->validate([
            'auxiliar_id' => ['required', 'integer'],
            'moneda_id'   => ['nullable', 'integer'],
            'fecha'       => ['nullable', 'date'],
            'concepto'    => ['nullable', 'string', 'max:500'],
        ]);

        $mes = str_pad((string) $periodo->mes, 2, '0', STR_PAD_LEFT);
        $importe = (float) $ddjj->importe_a_pagar;

        try {
            $op = $this->opService->crear([
                'empresa_id'  => $periodo->empresa_id,
                'usuario_id'  => $request->user()->id,
                'fecha'       => $datos['fecha'] ?? $periodo->fecha_vencimiento->toDateString(),
                'tipo'        => 'OTROS',
                'auxiliar_id' => $datos['auxiliar_id'],
                'moneda_id'   => $datos['moneda_id'] ?? 1,
                'cotizacion'  => 1,
                'importe'     => $importe,
                'importe_bruto' => $importe,
                'concepto'    => $datos['concepto'] ?? "DDJJ IVA F.2002 {$mes}/{$periodo->anio}",
                'items' => [[
                    'tipo_item' => OpItem::TIPO_OTRO,
                    'concepto'  => "DDJJ IVA F.2002 {$mes}/{$periodo->anio}",
                    'importe'   => $importe,
                ]],
                'medios' => [],
            ]);
        } catch (DomainException $e) {
            return $this->domainError($e);
        }

        DB::table('erp_iva_ddjj')->where('id', $ddjj->id)->update([
            'volante_pago_id' => $op->id,
            'updated_at'      => now(),
        ]);

        return response()->json(['ok' => true, 'data' => [
            'op_id' => $op->id, 'numero' => $op->numero, 'importe' => $importe,
        ]], Response::HTTP_CREATED);
    }

    private function periodo(int $id, Request $request): PeriodoFiscal
    {
        return PeriodoFiscal::with(['libroIvaVentas', 'libroIvaCompras'])
            ->where('empresa_id', (int) ($request->header('X-Empresa-Id') ?: 1))
            ->where('impuesto', 'IVA')
            ->findOrFail($id);
    }

    private function domainError(DomainException $e): JsonResponse
    {
        $code = explode(':', $e->getMessage(), 2)[0];
        return response()->json([
            'ok' => false,
            'error' => ['code' => $code, 'message' => $e->getMessage()],
        ], Response::HTTP_CONFLICT);
    }
}

<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\CentroCosto;
use App\Erp\Models\Diario;
use App\Erp\Models\Tesoreria\Banco;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MedioPago;
use App\Erp\Models\Ejercicio;
use App\Erp\Models\Empresa;
use App\Erp\Models\Moneda;
use App\Erp\Models\Periodo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catálogos de lectura para alimentar selects del frontend:
 * empresas, diarios, ejercicios, periodos, monedas.
 */
class CatalogosController
{
    public function empresaActual(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $empresa = Empresa::findOrFail($empresaId);

        return response()->json([
            'data' => [
                'id' => $empresa->id,
                'razon_social' => $empresa->razon_social,
                'cuit' => $empresa->cuit,
                'condicion_iva' => $empresa->condicion_iva,
                'moneda_base' => $empresa->moneda_base,
                'aplica_rt6' => (bool) $empresa->aplica_rt6,
            ],
        ]);
    }

    public function diarios(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $diarios = Diario::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre', 'tipo', 'numerador_actual']);

        return response()->json(['data' => $diarios]);
    }

    public function ejercicios(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $ejercicios = Ejercicio::where('empresa_id', $empresaId)
            ->orderByDesc('fecha_inicio')
            ->get();

        return response()->json(['data' => $ejercicios]);
    }

    public function periodos(Request $request): JsonResponse
    {
        $request->validate([
            'ejercicio_id' => ['nullable', 'integer'],
        ]);

        $empresaId = $this->empresaIdFromRequest($request);
        $ejercicioId = $request->integer('ejercicio_id') ?: null;

        $query = Periodo::query()
            ->whereHas('ejercicio', fn ($q) => $q->where('empresa_id', $empresaId))
            ->orderBy('anio')
            ->orderBy('mes');

        if ($ejercicioId) {
            $query->where('ejercicio_id', $ejercicioId);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function periodoAbierto(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $periodo = Periodo::query()
            ->whereHas('ejercicio', fn ($q) => $q->where('empresa_id', $empresaId))
            ->where('estado', 'ABIERTO')
            ->orderByDesc('anio')
            ->orderByDesc('mes')
            ->first();

        if (! $periodo) {
            return response()->json(['data' => null, 'message' => 'No hay período abierto.'], 404);
        }

        return response()->json(['data' => $periodo]);
    }

    public function monedas(): JsonResponse
    {
        return response()->json([
            'data' => Moneda::where('activa', true)->orderByDesc('es_base')->orderBy('codigo')->get(),
        ]);
    }

    public function centrosCosto(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        return response()->json([
            'data' => CentroCosto::where('empresa_id', $empresaId)
                ->where('activo', true)
                ->orderBy('codigo')
                ->get(['id', 'codigo', 'nombre', 'tipo', 'padre_id']),
        ]);
    }

    public function bancos(): JsonResponse
    {
        return response()->json([
            'data' => Banco::where('activo', true)->orderBy('codigo')->get(),
        ]);
    }

    public function cuentasBancarias(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        // v1.55 Bloque B — parser_soportado le permite a la UI ofrecer solo
        // cuentas importables en "Subir extracto" (Galicia quedó fuera del
        // factory; Cheques/Compensación nunca tuvieron parser).
        $soportados = (new \App\Erp\Services\Tesoreria\Parsers\ParserFactory())->codigosSoportados();

        $cuentas = CuentaBancaria::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->with(['banco:id,codigo,nombre,codigo_parser', 'moneda:id,codigo'])
            ->orderBy('codigo')
            ->get()
            ->each(fn ($c) => $c->setAttribute(
                'parser_soportado',
                in_array($c->banco?->codigo_parser, $soportados, true),
            ));

        return response()->json(['data' => $cuentas]);
    }

    /**
     * GET /api/erp/cuentas-bancarias/{id}/monedas-aceptadas
     * v1.18 Sprint T — bimoneda. Devuelve la moneda principal + lista de
     * monedas aceptadas (si la cuenta es multimoneda). Si `monedas_aceptadas`
     * es NULL, devuelve solo la principal y marca `es_monomoneda=true`.
     */
    public function monedasAceptadas(Request $request, int $id): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);
        $cuenta = CuentaBancaria::where('empresa_id', $empresaId)
            ->where('id', $id)
            ->with('moneda:id,codigo')
            ->first();
        if (! $cuenta) {
            return response()->json(['ok' => false, 'error' => ['code' => 'NO_ENCONTRADA']], 404);
        }

        $principal = $cuenta->moneda?->codigo ?? 'ARS';
        $aceptadas = $cuenta->monedas_aceptadas;
        $esMono = empty($aceptadas);

        // Resolver IDs de monedas para que el frontend los mapee al dropdown.
        $codigos = $esMono ? [$principal] : (array) $aceptadas;
        $monedaRows = \App\Erp\Models\Moneda::whereIn('codigo', $codigos)
            ->where('activa', true)
            ->get(['id', 'codigo'])
            ->keyBy('codigo');

        $monedaPrincipalId = $monedaRows[$principal]->id ?? $cuenta->moneda_id;
        $aceptadasIds = $codigos
            ? array_values(array_filter(array_map(fn ($c) => $monedaRows[$c]->id ?? null, $codigos)))
            : [$monedaPrincipalId];

        return response()->json([
            'ok' => true,
            'data' => [
                'cuenta_bancaria_id' => $cuenta->id,
                'principal' => $principal,
                'principal_id' => $monedaPrincipalId,
                'aceptadas' => $codigos,
                'aceptadas_ids' => $aceptadasIds,
                'es_monomoneda' => $esMono,
            ],
        ]);
    }

    public function cajas(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        return response()->json([
            'data' => Caja::where('empresa_id', $empresaId)
                ->where('activo', true)
                ->with('moneda:id,codigo')
                ->orderBy('codigo')
                ->get(),
        ]);
    }

    public function mediosPago(): JsonResponse
    {
        return response()->json([
            'data' => MedioPago::where('activo', true)->orderBy('codigo')->get(),
        ]);
    }

    public function auxiliares(Request $request): JsonResponse
    {
        $empresaId = $this->empresaIdFromRequest($request);

        $query = Auxiliar::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->orderBy('nombre');

        if ($tipo = $request->string('tipo')->toString()) {
            $query->where('tipo', $tipo);
        }

        if ($q = trim($request->string('q')->toString())) {
            $query->where(function ($sub) use ($q) {
                $sub->where('nombre', 'like', "%{$q}%")
                    ->orWhere('codigo', 'like', "{$q}%")
                    ->orWhere('cuit', 'like', "{$q}%");
            });
        }

        return response()->json([
            'data' => $query->limit(50)->get(['id', 'tipo', 'codigo', 'nombre', 'cuit', 'cuenta_contable_default_id']),
        ]);
    }

    /**
     * Catálogo de tipos de comprobante AFIP (activos).
     *
     * v1.24.1 — endpoint que el frontend de Carga manual de factura de compra
     * estaba llamando desde el v1.17 pero nunca se había registrado en routes.
     * Aparecía como 404 al abrir la página y volcaba la consola con un
     * `.map is not a function` aguas abajo.
     *
     * Acepta filtro opcional `?clase=FACTURA|NOTA_CREDITO|NOTA_DEBITO|RECIBO|TICKET|OTRO`.
     * Valores inválidos (ej: el legacy `?clase=COMPRA`) se ignoran sin error —
     * el mismo tipo de comprobante AFIP sirve para venta y compra (FA, FB, FC...).
     */
    public function tiposComprobante(Request $request): JsonResponse
    {
        $clasesValidas = ['FACTURA', 'NOTA_CREDITO', 'NOTA_DEBITO', 'RECIBO', 'TICKET', 'OTRO'];
        $q = DB::table('erp_tipos_comprobante')->where('activo', 1);

        $clase = $request->query('clase');
        if ($clase && in_array($clase, $clasesValidas, true)) {
            $q->where('clase', $clase);
        }

        return response()->json([
            'data' => $q->orderBy('id')->get([
                'id', 'codigo_interno', 'letra', 'nombre', 'clase', 'signo',
                'discrimina_iva', 'es_fce',
            ]),
        ]);
    }

    private function empresaIdFromRequest(Request $request): int
    {
        $perfil = $request->user()->erpPerfil ?? null;

        return $perfil?->empresa_id ?? 1;
    }
}

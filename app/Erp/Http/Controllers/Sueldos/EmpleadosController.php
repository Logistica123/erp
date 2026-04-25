<?php

namespace App\Erp\Http\Controllers\Sueldos;

use App\Erp\Models\Sueldos\BasicoHistorial;
use App\Erp\Models\Sueldos\ComisionEsquema;
use App\Erp\Models\Sueldos\ComposicionSueldo;
use App\Erp\Models\Sueldos\Empleado;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Padrón de empleados + endpoints anidados:
 *   GET    /sueldos/empleados                       — listado
 *   POST   /sueldos/empleados                       — alta (incluye básico inicial + composición)
 *   GET    /sueldos/empleados/{id}                  — detalle (con vigentes)
 *   PUT    /sueldos/empleados/{id}                  — editar cabecera
 *   GET    /sueldos/empleados/{id}/basicos          — historial básicos
 *   POST   /sueldos/empleados/{id}/basicos          — nuevo básico (cierra el vigente, RN-103)
 *   GET    /sueldos/empleados/{id}/composiciones    — historial composiciones
 *   POST   /sueldos/empleados/{id}/composiciones    — nueva composición (cierra la vigente, suma=100 RN-102)
 *   GET    /sueldos/empleados/{id}/comisiones       — historial esquemas comisión
 *   POST   /sueldos/empleados/{id}/comisiones       — nuevo esquema (cierra el vigente)
 */
class EmpleadosController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.ver');

        $q = Empleado::with(['categoria', 'convenio'])
            ->when($request->query('estado'), function ($q, $v) {
                $v === 'ACTIVO' ? $q->where('activo', 1) : $q->where('activo', 0);
            })
            ->when($request->query('regimen'), fn ($q, $v) => $q->where('regimen', $v))
            ->when($request->query('convenio_id'), fn ($q, $v) => $q->where('convenio_id', (int) $v))
            ->when($request->query('q'), function ($q, $v) {
                $like = '%'.$v.'%';
                $q->where(function ($q2) use ($like) {
                    $q2->where('legajo', 'like', $like)
                       ->orWhere('apellido', 'like', $like)
                       ->orWhere('nombre', 'like', $like)
                       ->orWhere('cuil', 'like', $like)
                       ->orWhere('dni', 'like', $like);
                });
            })
            ->orderBy('apellido')->orderBy('nombre');

        return response()->json(['ok' => true, 'data' => $q->paginate((int) $request->query('per_page', 50))]);
    }

    public function show(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.ver');

        $emp = Empleado::with(['categoria.convenio', 'convenio'])->findOrFail($id);

        $verBasicos = $request->user()->erpPerfil?->tienePermiso('sueldos.basicos.ver') ?? false;
        $verEfectivos = $request->user()->erpPerfil?->tienePermiso('sueldos.efectivos.ver') ?? false;

        $data = $emp->toArray();
        if ($verBasicos) {
            $vigente = $emp->basicoVigente();
            $data['basico_vigente'] = $vigente;
        }

        $compVigente = $emp->composicionVigente();
        if ($compVigente) {
            // Si no tiene permiso para ver efectivos, oculta el porcentaje EFECTIVO.
            if (! $verEfectivos) {
                $compVigente = $compVigente->replicate();
                $compVigente->porc_efectivo = null;
            }
            $data['composicion_vigente'] = $compVigente;
        }

        return response()->json(['ok' => true, 'data' => $data]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.editar');

        $datos = $request->validate([
            'legajo'             => ['required', 'string', 'max:20', 'unique:erp_emp_empleados,legajo'],
            'cuil'               => ['nullable', 'string', 'max:13'],
            'cuit'               => ['nullable', 'string', 'max:13'],
            'apellido'           => ['required', 'string', 'max:80'],
            'nombre'             => ['required', 'string', 'max:80'],
            'dni'                => ['nullable', 'string', 'max:15'],
            'fecha_nacimiento'   => ['nullable', 'date'],
            'fecha_ingreso'      => ['required', 'date'],
            'categoria_id'       => ['nullable', 'integer', 'exists:erp_emp_categorias,id'],
            'convenio_id'        => ['nullable', 'integer', 'exists:erp_emp_convenios,id'],
            'regimen'            => ['required', 'in:FORMAL_PURO,MIXTO,EFECTIVO_PURO,MONOTRIBUTISTA'],
            'jornada_formal_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'es_vendedor'        => ['nullable', 'boolean'],
            'paga_sac'           => ['nullable', 'boolean'],
            'cbu'                => ['nullable', 'string', 'max:22'],
            'banco'              => ['nullable', 'string', 'max:60'],
            'alias_cbu'          => ['nullable', 'string', 'max:40'],
            'email'              => ['nullable', 'email', 'max:120'],
            'telefono'           => ['nullable', 'string', 'max:30'],
            'domicilio'          => ['nullable', 'string', 'max:200'],
            'observaciones'      => ['nullable', 'string'],

            // Básico inicial (obligatorio para crear el primer registro de historial).
            'basico_inicial'     => ['required', 'numeric', 'min:0.01'],

            // Composición inicial (los 3 deben sumar 100).
            'porc_formal'        => ['required', 'numeric', 'min:0', 'max:100'],
            'porc_efectivo'      => ['required', 'numeric', 'min:0', 'max:100'],
            'porc_mt'            => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        if (round($datos['porc_formal'] + $datos['porc_efectivo'] + $datos['porc_mt'], 2) !== 100.0) {
            throw new DomainException('COMPOSICION_INVALIDA: porc_formal + porc_efectivo + porc_mt debe sumar 100.');
        }

        $empleado = DB::transaction(function () use ($datos, $request) {
            $emp = Empleado::create([
                'legajo' => $datos['legajo'], 'cuil' => $datos['cuil'] ?? null, 'cuit' => $datos['cuit'] ?? null,
                'apellido' => $datos['apellido'], 'nombre' => $datos['nombre'], 'dni' => $datos['dni'] ?? null,
                'fecha_nacimiento' => $datos['fecha_nacimiento'] ?? null,
                'fecha_ingreso'    => $datos['fecha_ingreso'],
                'categoria_id'     => $datos['categoria_id'] ?? null,
                'convenio_id'      => $datos['convenio_id'] ?? null,
                'regimen'          => $datos['regimen'],
                'jornada_formal_pct' => $datos['jornada_formal_pct'] ?? 0,
                'es_vendedor'      => $datos['es_vendedor'] ?? false,
                'paga_sac'         => $datos['paga_sac'] ?? true,
                'cbu' => $datos['cbu'] ?? null, 'banco' => $datos['banco'] ?? null, 'alias_cbu' => $datos['alias_cbu'] ?? null,
                'email' => $datos['email'] ?? null, 'telefono' => $datos['telefono'] ?? null, 'domicilio' => $datos['domicilio'] ?? null,
                'observaciones' => $datos['observaciones'] ?? null,
                'activo' => 1,
            ]);

            BasicoHistorial::create([
                'empleado_id'      => $emp->id,
                'basico_total'     => $datos['basico_inicial'],
                'vigencia_desde'   => $datos['fecha_ingreso'],
                'vigencia_hasta'   => null,
                'motivo'           => BasicoHistorial::MOTIVO_INGRESO,
                'aprobado_por_id'  => $request->user()->id,
                'fecha_aprobacion' => now(),
            ]);

            ComposicionSueldo::create([
                'empleado_id'    => $emp->id,
                'porc_formal'    => $datos['porc_formal'],
                'porc_efectivo'  => $datos['porc_efectivo'],
                'porc_mt'        => $datos['porc_mt'],
                'vigencia_desde' => $datos['fecha_ingreso'],
                'vigencia_hasta' => null,
            ]);

            return $emp;
        });

        return response()->json(['ok' => true, 'data' => $empleado->load(['categoria', 'convenio'])], 201);
    }

    public function update(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.editar');
        $emp = Empleado::findOrFail($id);

        $datos = $request->validate([
            'cuil'               => ['nullable', 'string', 'max:13'],
            'cuit'               => ['nullable', 'string', 'max:13'],
            'apellido'           => ['nullable', 'string', 'max:80'],
            'nombre'             => ['nullable', 'string', 'max:80'],
            'dni'                => ['nullable', 'string', 'max:15'],
            'fecha_nacimiento'   => ['nullable', 'date'],
            'fecha_egreso'       => ['nullable', 'date'],
            'categoria_id'       => ['nullable', 'integer', 'exists:erp_emp_categorias,id'],
            'convenio_id'        => ['nullable', 'integer', 'exists:erp_emp_convenios,id'],
            'regimen'            => ['nullable', 'in:FORMAL_PURO,MIXTO,EFECTIVO_PURO,MONOTRIBUTISTA'],
            'jornada_formal_pct' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'es_vendedor'        => ['nullable', 'boolean'],
            'paga_sac'           => ['nullable', 'boolean'],
            'cbu'                => ['nullable', 'string', 'max:22'],
            'banco'              => ['nullable', 'string', 'max:60'],
            'alias_cbu'          => ['nullable', 'string', 'max:40'],
            'email'              => ['nullable', 'email', 'max:120'],
            'telefono'           => ['nullable', 'string', 'max:30'],
            'domicilio'          => ['nullable', 'string', 'max:200'],
            'observaciones'      => ['nullable', 'string'],
            'activo'             => ['nullable', 'boolean'],
        ]);

        $emp->update($datos);
        return response()->json(['ok' => true, 'data' => $emp->fresh(['categoria', 'convenio'])]);
    }

    // ---------- Básicos ----------

    public function basicosListar(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.basicos.ver');
        Empleado::findOrFail($id);
        $rows = BasicoHistorial::with('aprobador:id,name')
            ->where('empleado_id', $id)
            ->orderByDesc('vigencia_desde')->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function basicoStore(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.basicos.aprobar');
        $emp = Empleado::findOrFail($id);

        $datos = $request->validate([
            'basico_total'   => ['required', 'numeric', 'min:0.01'],
            'vigencia_desde' => ['required', 'date'],
            'motivo'         => ['required', 'in:INGRESO,AUMENTO_PARITARIA,AUMENTO_GERENCIAL,CORRECCION,RECATEGORIZACION'],
            'observaciones'  => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($emp, $datos, $request) {
            // Cerrar vigente: vigencia_hasta = vigencia_desde - 1 día (RN-103 sin overlap).
            $vigente = $emp->basicoVigente();
            if ($vigente) {
                $hasta = (new \DateTime($datos['vigencia_desde']))->modify('-1 day')->format('Y-m-d');
                $vigente->update(['vigencia_hasta' => $hasta]);
            }

            $nuevo = BasicoHistorial::create([
                'empleado_id'      => $emp->id,
                'basico_total'     => $datos['basico_total'],
                'vigencia_desde'   => $datos['vigencia_desde'],
                'vigencia_hasta'   => null,
                'motivo'           => $datos['motivo'],
                'aprobado_por_id'  => $request->user()->id,
                'fecha_aprobacion' => now(),
                'observaciones'    => $datos['observaciones'] ?? null,
            ]);

            return response()->json(['ok' => true, 'data' => $nuevo->load('aprobador:id,name')], 201);
        });
    }

    // ---------- Composición ----------

    public function composicionesListar(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.ver');
        Empleado::findOrFail($id);
        $rows = ComposicionSueldo::where('empleado_id', $id)
            ->orderByDesc('vigencia_desde')->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function composicionStore(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.editar');
        $emp = Empleado::findOrFail($id);

        $datos = $request->validate([
            'porc_formal'    => ['required', 'numeric', 'min:0', 'max:100'],
            'porc_efectivo'  => ['required', 'numeric', 'min:0', 'max:100'],
            'porc_mt'        => ['required', 'numeric', 'min:0', 'max:100'],
            'vigencia_desde' => ['required', 'date'],
            'observaciones'  => ['nullable', 'string'],
        ]);

        if (round($datos['porc_formal'] + $datos['porc_efectivo'] + $datos['porc_mt'], 2) !== 100.0) {
            throw new DomainException('COMPOSICION_INVALIDA: los 3 porcentajes deben sumar 100.');
        }

        return DB::transaction(function () use ($emp, $datos) {
            $vigente = $emp->composicionVigente();
            if ($vigente) {
                $hasta = (new \DateTime($datos['vigencia_desde']))->modify('-1 day')->format('Y-m-d');
                $vigente->update(['vigencia_hasta' => $hasta]);
            }
            $nueva = ComposicionSueldo::create([
                'empleado_id'    => $emp->id,
                'porc_formal'    => $datos['porc_formal'],
                'porc_efectivo'  => $datos['porc_efectivo'],
                'porc_mt'        => $datos['porc_mt'],
                'vigencia_desde' => $datos['vigencia_desde'],
                'vigencia_hasta' => null,
                'observaciones'  => $datos['observaciones'] ?? null,
            ]);
            return response()->json(['ok' => true, 'data' => $nueva], 201);
        });
    }

    // ---------- Comisiones ----------

    public function comisionesListar(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.ver');
        Empleado::findOrFail($id);
        $rows = ComisionEsquema::where('empleado_id', $id)
            ->orderByDesc('vigencia_desde')->get();
        return response()->json(['ok' => true, 'data' => $rows]);
    }

    public function comisionStore(int $id, Request $request): JsonResponse
    {
        $this->mustHave($request, 'sueldos.empleados.editar');
        $emp = Empleado::findOrFail($id);

        $datos = $request->validate([
            'base'             => ['required', 'in:VENTAS_NETAS,COBRANZAS,MARGEN,UNIDADES,FIJO_MENSUAL'],
            'porcentaje'       => ['nullable', 'numeric', 'min:0', 'max:100'],
            'importe_unitario' => ['nullable', 'numeric', 'min:0'],
            'importe_fijo'     => ['nullable', 'numeric', 'min:0'],
            'tope_mensual'     => ['nullable', 'numeric', 'min:0'],
            'vigencia_desde'   => ['required', 'date'],
            'observaciones'    => ['nullable', 'string'],
        ]);

        return DB::transaction(function () use ($emp, $datos) {
            $vigente = ComisionEsquema::where('empleado_id', $emp->id)
                ->whereNull('vigencia_hasta')
                ->orderByDesc('vigencia_desde')->first();
            if ($vigente) {
                $hasta = (new \DateTime($datos['vigencia_desde']))->modify('-1 day')->format('Y-m-d');
                $vigente->update(['vigencia_hasta' => $hasta]);
            }
            $nuevo = ComisionEsquema::create(array_merge($datos, ['empleado_id' => $emp->id]));
            return response()->json(['ok' => true, 'data' => $nuevo], 201);
        });
    }

    private function mustHave(Request $request, string $codigo): void
    {
        $perfil = $request->user()->erpPerfil;
        if (! $perfil || ! $perfil->tienePermiso($codigo)) {
            abort(response()->json(['ok' => false, 'error' => ['code' => 'NO_AUTORIZADO', 'message' => "Falta permiso {$codigo}"]], 403));
        }
    }
}

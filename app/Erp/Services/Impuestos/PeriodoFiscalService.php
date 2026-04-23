<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use Carbon\Carbon;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Gestión del ciclo de vida de un período fiscal (SPEC 05 §5).
 *
 * Estados y transiciones permitidas:
 *
 *   ABIERTO ─► EN_REVISION ─► APROBADO ─► PRESENTADO ─► CERRADO
 *      ▲           │             │
 *      └───────────┘             └─► (sólo retroceso por revisor cuando observa)
 *
 * Reglas duras:
 *  - RN-44: PRESENTADO/CERRADO no admiten edición; rectificar genera nuevo
 *           período con `rectifica_a_id` apuntando al original.
 *  - RN-51: al crear un período de IVA, se precarga
 *           `saldo_libre_disp_anterior` desde el último período cerrado.
 *
 * No se modifica `erp_iva_ddjj` desde este service — eso vive en H2.
 */
class PeriodoFiscalService
{
    private const TRANSICIONES = [
        'ABIERTO'      => ['EN_REVISION'],
        'EN_REVISION'  => ['APROBADO', 'ABIERTO'],   // ABIERTO = "rechazado por revisor"
        'APROBADO'     => ['PRESENTADO', 'EN_REVISION'],
        'PRESENTADO'   => ['CERRADO'],
        'CERRADO'      => [],
        'RECTIFICATIVA'=> ['EN_REVISION', 'APROBADO', 'PRESENTADO', 'CERRADO'],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea un período fiscal nuevo.
     *
     * @param array{
     *   empresa_id:int, impuesto:string, anio:int, mes?:int|null,
     *   ejercicio_id?:int|null, fecha_vencimiento?:string|null,
     *   observaciones?:string|null
     * } $datos
     */
    public function crear(array $datos, User $usuario): PeriodoFiscal
    {
        $this->validarDatosCreacion($datos);

        return DB::transaction(function () use ($datos, $usuario) {
            // RN-44: si ya existe un período abierto/no-cerrado para la misma
            // (empresa, impuesto, anio, mes, ejercicio_id) y NO es rectificativa,
            // rechazamos. La unique key del DDL incluye rectifica_a_id, así que
            // múltiples rectificativas conviven con el original; lo que
            // bloqueamos acá es el duplicado puro.
            $duplicado = PeriodoFiscal::query()
                ->where('empresa_id', $datos['empresa_id'])
                ->where('impuesto', $datos['impuesto'])
                ->where('anio', $datos['anio'])
                ->where('mes', $datos['mes'] ?? null)
                ->where('ejercicio_id', $datos['ejercicio_id'] ?? null)
                ->whereNull('rectifica_a_id')
                ->exists();

            if ($duplicado) {
                throw new DomainException('PERIODO_DUPLICADO: ya existe período para esa combinación');
            }

            $vencimiento = $datos['fecha_vencimiento'] ?? $this->vencimientoDefault(
                $datos['impuesto'], $datos['anio'], $datos['mes'] ?? null
            );

            $periodo = PeriodoFiscal::create([
                'empresa_id'        => $datos['empresa_id'],
                'impuesto'          => $datos['impuesto'],
                'anio'              => $datos['anio'],
                'mes'               => $datos['mes'] ?? null,
                'ejercicio_id'      => $datos['ejercicio_id'] ?? null,
                'estado'            => 'ABIERTO',
                'fecha_vencimiento' => $vencimiento,
                'observaciones'     => $datos['observaciones'] ?? null,
            ]);

            $this->audit->log('crear', $periodo, null, $periodo->toArray(),
                "Crear período fiscal {$periodo->impuesto} {$periodo->anio}/{$periodo->mes}");

            return $periodo->fresh();
        });
    }

    /**
     * Transiciona un período entre estados con validación.
     *
     * @param array{nro_tramite?:string|null, fecha_presentacion?:string|null,
     *              acuse_path?:string|null, observaciones?:string|null} $extra
     */
    public function transicionar(
        PeriodoFiscal $periodo,
        string $nuevoEstado,
        User $usuario,
        array $extra = [],
    ): PeriodoFiscal {
        $actual = $periodo->estado;
        $permitidos = self::TRANSICIONES[$actual] ?? [];

        if (! in_array($nuevoEstado, $permitidos, true)) {
            throw new DomainException(
                "PERIODO_TRANSICION_INVALIDA: {$actual} → {$nuevoEstado} no permitida"
            );
        }

        if ($nuevoEstado === 'PRESENTADO' && empty($extra['nro_tramite'])) {
            throw new DomainException('PERIODO_PRESENTADO_REQUIERE_TRAMITE');
        }

        return DB::transaction(function () use ($periodo, $nuevoEstado, $usuario, $extra) {
            $antes = $periodo->toArray();

            $cambios = ['estado' => $nuevoEstado];

            if ($nuevoEstado === 'EN_REVISION') {
                $cambios['revisor_user_id'] = $usuario->id;
            }

            if ($nuevoEstado === 'APROBADO') {
                $cambios['aprobado_user_id'] = $usuario->id;
                $cambios['aprobado_at']      = now();
            }

            if ($nuevoEstado === 'PRESENTADO') {
                $cambios['presentado_user_id']  = $usuario->id;
                $cambios['presentado_at']       = now();
                $cambios['nro_tramite']         = $extra['nro_tramite'];
                $cambios['fecha_presentacion']  = $extra['fecha_presentacion'] ?? now()->toDateString();
                if (! empty($extra['acuse_path'])) {
                    $cambios['acuse_path'] = $extra['acuse_path'];
                }
            }

            if (! empty($extra['observaciones'])) {
                $cambios['observaciones'] = trim(
                    ($periodo->observaciones ? $periodo->observaciones."\n---\n" : '')
                    .'['.now()->toDateTimeString().' '.$usuario->name."]\n"
                    .$extra['observaciones']
                );
            }

            $periodo->update($cambios);

            $this->audit->log("transicion_{$nuevoEstado}", $periodo, $antes, $periodo->fresh()->toArray(),
                "Período {$periodo->impuesto} {$periodo->anio}/{$periodo->mes}: {$periodo->getOriginal('estado')} → {$nuevoEstado}");

            return $periodo->fresh();
        });
    }

    /**
     * RN-44: genera una rectificativa apuntando al período original.
     */
    public function rectificar(PeriodoFiscal $original, string $motivo, User $usuario): PeriodoFiscal
    {
        if (! $original->esCerrado()) {
            throw new DomainException(
                "PERIODO_NO_RECTIFICABLE: solo PRESENTADO/CERRADO admiten rectificativa (actual: {$original->estado})"
            );
        }

        if (trim($motivo) === '') {
            throw new DomainException('PERIODO_RECTIFICATIVA_REQUIERE_MOTIVO');
        }

        return DB::transaction(function () use ($original, $motivo, $usuario) {
            $rect = PeriodoFiscal::create([
                'empresa_id'        => $original->empresa_id,
                'impuesto'          => $original->impuesto,
                'anio'              => $original->anio,
                'mes'               => $original->mes,
                'ejercicio_id'      => $original->ejercicio_id,
                'estado'            => 'RECTIFICATIVA',
                'fecha_vencimiento' => $original->fecha_vencimiento,
                'rectifica_a_id'    => $original->id,
                'observaciones'     => '['.now()->toDateTimeString().' '.$usuario->name."]\nRectificativa motivo: {$motivo}",
            ]);

            $this->audit->log('rectificativa', $rect, null, $rect->toArray(),
                "Rectificativa de período #{$original->id} ({$original->impuesto} {$original->anio}/{$original->mes}): {$motivo}");

            return $rect->fresh();
        });
    }

    /**
     * RN-51: saldo libre disponibilidad anterior — busca el último período IVA
     * cerrado de la misma empresa, ejercicio o anio anterior, y devuelve
     * el saldo final si lo hay. Se llama desde H2 al inicializar la DDJJ IVA;
     * lo dejamos público acá porque es lógica de período.
     */
    public function saldoLibreDispAnterior(int $empresaId, int $anio, int $mes): float
    {
        $row = DB::table('erp_periodos_fiscales as p')
            ->join('erp_iva_ddjj as d', 'd.periodo_id', '=', 'p.id')
            ->where('p.empresa_id', $empresaId)
            ->where('p.impuesto', 'IVA')
            ->whereIn('p.estado', ['PRESENTADO', 'CERRADO'])
            ->whereRaw('(p.anio*100 + p.mes) < ?', [$anio * 100 + $mes])
            ->orderByDesc('p.anio')->orderByDesc('p.mes')
            ->select('d.saldo_libre_disp_final')
            ->first();

        return (float) ($row->saldo_libre_disp_final ?? 0);
    }

    private function validarDatosCreacion(array $datos): void
    {
        foreach (['empresa_id', 'impuesto', 'anio'] as $req) {
            if (empty($datos[$req])) {
                throw new DomainException("PERIODO_DATOS_INVALIDOS: falta {$req}");
            }
        }

        $impuestosMensuales = ['IVA', 'SICORE', 'SIRE', 'IIBB_CM', 'IIBB_CABA', 'IIBB_PBA'];
        $impuestosAnuales   = ['GAN_ANUAL', 'BP_PART'];

        if (in_array($datos['impuesto'], $impuestosMensuales, true)) {
            if (empty($datos['mes']) || $datos['mes'] < 1 || $datos['mes'] > 12) {
                throw new DomainException("PERIODO_MES_REQUERIDO: {$datos['impuesto']} es mensual");
            }
        }

        if (in_array($datos['impuesto'], $impuestosAnuales, true)) {
            if (empty($datos['ejercicio_id'])) {
                throw new DomainException("PERIODO_EJERCICIO_REQUERIDO: {$datos['impuesto']} es anual");
            }
        }
    }

    private function vencimientoDefault(string $impuesto, int $anio, ?int $mes): string
    {
        // 1) Buscar en calendario seedeado.
        $row = DB::table('erp_calendario_vencimientos')
            ->where('anio', $anio)
            ->where('impuesto', $impuesto)
            ->where('periodo_identificador', $mes !== null ? str_pad((string) $mes, 2, '0', STR_PAD_LEFT) : (string) $anio)
            ->whereNull('terminacion_cuit')
            ->value('fecha_vencimiento');

        if ($row) {
            return Carbon::parse($row)->toDateString();
        }

        // 2) Fallback: día 20 del mes siguiente para mensuales, 31/05 año + 1 para anuales.
        if ($mes !== null) {
            return Carbon::create($anio, $mes, 1)->addMonth()->day(20)->toDateString();
        }

        return Carbon::create($anio + 1, 5, 31)->toDateString();
    }
}

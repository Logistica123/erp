<?php

namespace App\Erp\Services;

use App\Erp\Models\VentasCompras\FacturaVenta;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Factura Crédito Electrónica MiPyME (SPEC 03 RN-36).
 *
 * Ciclo FCE (paralelo a los estados de cobro):
 *   EMITIDA_FCE (al emitir con es_fce=1)
 *     → ACEPTADA_FCE       (cliente acepta explícitamente)
 *     → RECHAZADA_FCE      (cliente rechaza explícitamente)
 *     → ACEPTADA_TACITAMENTE (cron: vence plazo sin respuesta)
 *     → NEGOCIADA_SIRCREB  (SPEC 05 Fase 5)
 *
 * Plazo por defecto: 30 días desde la emisión. Configurable via
 * erp_config key 'ventas.fce.plazo_aceptacion_dias'.
 */
class FceService
{
    public const ESTADO_EMITIDA_FCE = 'EMITIDA_FCE';
    public const ESTADO_ACEPTADA_FCE = 'ACEPTADA_FCE';
    public const ESTADO_RECHAZADA_FCE = 'RECHAZADA_FCE';
    public const ESTADO_ACEPTADA_TACITAMENTE = 'ACEPTADA_TACITAMENTE';
    public const ESTADO_NEGOCIADA_SIRCREB = 'NEGOCIADA_SIRCREB';

    public function __construct(private readonly AuditLogger $audit) {}

    public function aceptar(FacturaVenta $factura, User $usuario): FacturaVenta
    {
        $this->validarEsFce($factura);
        if ($factura->estado_fce === self::ESTADO_ACEPTADA_FCE) {
            throw new DomainException('FCE_YA_ACEPTADA');
        }
        if (in_array($factura->estado_fce, [self::ESTADO_RECHAZADA_FCE, self::ESTADO_NEGOCIADA_SIRCREB], true)) {
            throw new DomainException('FCE_ESTADO_INVALIDO: actual '.$factura->estado_fce);
        }

        $factura->update(['estado_fce' => self::ESTADO_ACEPTADA_FCE]);

        $this->audit->logEvento(
            accion: 'FCE_ACEPTADA',
            modulo: 'ventas',
            descripcion: sprintf('FCE #%d aceptada por cliente (via %s)', $factura->id, $usuario->name),
            empresaId: $factura->empresa_id,
        );

        return $factura->fresh();
    }

    public function rechazar(FacturaVenta $factura, string $motivo, User $usuario): FacturaVenta
    {
        $this->validarEsFce($factura);
        if ($factura->estado_fce === self::ESTADO_ACEPTADA_FCE) {
            throw new DomainException('FCE_YA_ACEPTADA: emití NC antes de cambiar estado');
        }
        if ($factura->estado_fce === self::ESTADO_RECHAZADA_FCE) {
            throw new DomainException('FCE_YA_RECHAZADA');
        }

        $factura->update([
            'estado_fce' => self::ESTADO_RECHAZADA_FCE,
            'observaciones' => trim(($factura->observaciones ?? '').' · FCE RECHAZADA: '.$motivo),
        ]);

        $this->audit->logEvento(
            accion: 'FCE_RECHAZADA',
            modulo: 'ventas',
            descripcion: sprintf('FCE #%d rechazada por cliente: %s', $factura->id, $motivo),
            empresaId: $factura->empresa_id,
        );

        return $factura->fresh();
    }

    /**
     * Cron RN-36: marca FCE como ACEPTADA_TACITAMENTE si pasaron N días desde
     * la emisión sin respuesta explícita (aceptada/rechazada).
     *
     * @return array{procesadas:int, ids:array<int,int>}
     */
    public function procesarAceptacionesTacitas(int $empresaId = 1): array
    {
        $plazoDias = (int) (DB::table('erp_config')
            ->where('empresa_id', $empresaId)
            ->where('clave', 'ventas.fce.plazo_aceptacion_dias')
            ->value('valor') ?? 30);

        $limite = Carbon::now()->subDays($plazoDias)->toDateString();

        $candidatos = FacturaVenta::where('empresa_id', $empresaId)
            ->where('es_fce', true)
            ->where('estado_fce', self::ESTADO_EMITIDA_FCE)
            ->where('fecha_emision', '<=', $limite)
            ->get();

        $ids = [];
        foreach ($candidatos as $f) {
            $f->update(['estado_fce' => self::ESTADO_ACEPTADA_TACITAMENTE]);
            $ids[] = $f->id;

            $this->audit->logEvento(
                accion: 'FCE_ACEPTADA_TACITAMENTE',
                modulo: 'ventas',
                descripcion: sprintf(
                    'FCE #%d aceptada tácitamente: %d días sin respuesta desde %s',
                    $f->id, $plazoDias, $f->fecha_emision?->format('Y-m-d')
                ),
                empresaId: $empresaId,
            );
        }

        return ['procesadas' => count($ids), 'ids' => $ids];
    }

    private function validarEsFce(FacturaVenta $factura): void
    {
        if (! $factura->es_fce) {
            throw new DomainException('FACTURA_NO_ES_FCE: flag es_fce=0');
        }
    }
}

<?php

namespace App\Erp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * v1.56 — llena jurisdiccion_codigo en facturas de compra sincronizadas
 * desde DistriApp que quedaron sin jurisdicción (pedido 2026-07-09 noche).
 *
 * Resuelve cross-DB (mismo MySQL): distriapp_factura_id → basepersonal
 * facturas → persona → sucursal → mapping liq_jurisdicciones_sucursal
 * (cliente_id + nombre de sucursal) con fallback a sucursals.jurisdiccion_id.
 *
 * Re-ejecutable: correr de nuevo cuando Matías complete el mapping de
 * sucursales-ciudad desde su UI de jurisdicciones. Solo toca facturas sin
 * asiento (las autorizadas no se pisan).
 */
class LlenarJurisdiccionRetroactivo extends Command
{
    protected $signature = 'facturas-compra:llenar-jurisdiccion-retroactivo {--confirm : Aplica los cambios (sin esto es dry-run)}';

    protected $description = 'Llena jurisdiccion_codigo en facturas DISTRIAPP sin jurisdicción, vía mapping sucursal→jurisdicción de basepersonal';

    private const FROM_JOIN = "
        FROM erp_facturas_compra fc
        JOIN basepersonal.facturas f ON f.id = CAST(SUBSTRING(fc.distriapp_factura_id, 7) AS UNSIGNED)
        JOIN basepersonal.personas p ON p.id = f.persona_id
        JOIN basepersonal.sucursals s ON s.id = p.sucursal_id
        LEFT JOIN basepersonal.liq_jurisdicciones_sucursal ljs
          ON ljs.cliente_id = s.cliente_id AND ljs.sucursal = s.nombre
        WHERE fc.origen = 'DISTRIAPP'
          AND fc.jurisdiccion_codigo IS NULL
          AND fc.asiento_id IS NULL
          AND fc.distriapp_factura_id LIKE 'DA-FC-%'
    ";

    public function handle(): int
    {
        $conMapping = DB::selectOne('
            SELECT COUNT(*) AS total,
                   SUM(COALESCE(s.jurisdiccion_id, ljs.jurisdiccion_id) IS NOT NULL) AS resolubles
            '.self::FROM_JOIN);

        $this->info("Facturas DISTRIAPP sin jurisdicción (sin asiento): {$conMapping->total}");
        $this->info('Resolubles con el mapping actual: '.(int) $conMapping->resolubles);

        $sinMapping = DB::select('
            SELECT s.cliente_id, s.nombre AS sucursal, COUNT(*) AS facturas
            '.self::FROM_JOIN."
              AND COALESCE(s.jurisdiccion_id, ljs.jurisdiccion_id) IS NULL
            GROUP BY s.cliente_id, s.nombre ORDER BY facturas DESC");
        if ($sinMapping) {
            $this->warn('Sucursales sin mapping (cargarlas en la UI de jurisdicciones y volver a correr):');
            foreach ($sinMapping as $row) {
                $this->line("  - cliente {$row->cliente_id} · {$row->sucursal} ({$row->facturas} factura/s)");
            }
        }

        if (! $this->option('confirm')) {
            $this->comment('Dry-run: nada modificado. Correr con --confirm para aplicar.');

            return self::SUCCESS;
        }

        $afectadas = DB::update('
            UPDATE erp_facturas_compra fc
            JOIN basepersonal.facturas f ON f.id = CAST(SUBSTRING(fc.distriapp_factura_id, 7) AS UNSIGNED)
            JOIN basepersonal.personas p ON p.id = f.persona_id
            JOIN basepersonal.sucursals s ON s.id = p.sucursal_id
            LEFT JOIN basepersonal.liq_jurisdicciones_sucursal ljs
              ON ljs.cliente_id = s.cliente_id AND ljs.sucursal = s.nombre
            SET fc.jurisdiccion_codigo = LPAD(COALESCE(s.jurisdiccion_id, ljs.jurisdiccion_id), 3, \'0\')
            WHERE fc.origen = \'DISTRIAPP\'
              AND fc.jurisdiccion_codigo IS NULL
              AND fc.asiento_id IS NULL
              AND fc.distriapp_factura_id LIKE \'DA-FC-%\'
              AND COALESCE(s.jurisdiccion_id, ljs.jurisdiccion_id) IS NOT NULL');

        $this->info("Jurisdicción llenada en {$afectadas} factura/s.");

        return self::SUCCESS;
    }
}

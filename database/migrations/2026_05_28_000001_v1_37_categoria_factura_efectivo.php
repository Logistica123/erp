<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * v1.37 — Sumar categoría ENUM('FACTURA','EFECTIVO') en facturas, recibos,
 * cobros y órdenes de pago. Diferencia operaciones con factura (default,
 * comportamiento actual) de operaciones de gestión/efectivo (sin factura,
 * fuera del libro IVA y reportes fiscales).
 *
 * Convención (D-37-4): FACTURA es el default y nunca se rotula en UI; solo
 * EFECTIVO se muestra con badge. La migración rellena TODO lo existente como
 * FACTURA → cero cambio de comportamiento para los reportes actuales.
 *
 * Idempotente: usa hasColumn() para no fallar si ya está aplicada.
 */
return new class extends Migration
{
    /** Tablas afectadas. Todas reciben la misma columna y el mismo default. */
    private const TABLAS = [
        'erp_facturas_venta',
        'erp_facturas_compra',
        'erp_recibos',
        'erp_cobros',
        'erp_ordenes_pago',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) continue;
            if (Schema::hasColumn($tabla, 'categoria')) continue;

            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                $t->enum('categoria', ['FACTURA', 'EFECTIVO'])
                    ->default('FACTURA')
                    ->after('id'); // posicion estable (despues del PK)
                $t->index('categoria', "idx_{$this->shortIdx($tabla)}_cat");
            });

            // Compuesto solo para facturas (filtro habitual: categoria + estado).
            if (in_array($tabla, ['erp_facturas_venta', 'erp_facturas_compra'], true)) {
                Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                    $t->index(['categoria', 'estado'], "idx_{$this->shortIdx($tabla)}_cat_est");
                });
            }
        }

        // Verificacion post-migracion: 100% de las filas existentes deben ser
        // FACTURA (default aplicado). Si falla, abortar (la migracion no esta
        // sirviendo a su proposito).
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) continue;
            $nulls = DB::table($tabla)->whereNull('categoria')->count();
            $efectivos = DB::table($tabla)->where('categoria', 'EFECTIVO')->count();
            if ($nulls > 0) {
                throw new RuntimeException("v1.37 migracion: {$tabla} tiene {$nulls} filas con categoria NULL.");
            }
            if ($efectivos > 0) {
                // Esto NO deberia pasar en una migracion fresh.
                throw new RuntimeException("v1.37 migracion: {$tabla} tiene {$efectivos} filas EFECTIVO pre-migracion (inesperado).");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLAS as $tabla) {
            if (! Schema::hasTable($tabla)) continue;
            if (! Schema::hasColumn($tabla, 'categoria')) continue;

            Schema::table($tabla, function (Blueprint $t) use ($tabla) {
                $short = $this->shortIdx($tabla);
                // Drop indexes con try/catch para tolerar variaciones de naming.
                try { $t->dropIndex("idx_{$short}_cat"); } catch (\Throwable $e) {}
                try { $t->dropIndex("idx_{$short}_cat_est"); } catch (\Throwable $e) {}
                $t->dropColumn('categoria');
            });
        }
    }

    /** Sigla corta de tabla para nombres de indice (max 64 chars en MySQL). */
    private function shortIdx(string $tabla): string
    {
        return match ($tabla) {
            'erp_facturas_venta' => 'fv',
            'erp_facturas_compra' => 'fc',
            'erp_recibos' => 'rec',
            'erp_cobros' => 'cob',
            'erp_ordenes_pago' => 'op',
            default => substr(str_replace('erp_', '', $tabla), 0, 10),
        };
    }
};

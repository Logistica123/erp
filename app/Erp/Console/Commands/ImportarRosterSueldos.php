<?php

namespace App\Erp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Workstream Sueldos Bloque 2 — G-15: importa el roster del Excel de
 * Matías (fuente: ANEXO_SISTEMA_SUELDOS_Excel_Actual.md §6/§7, estado al
 * 2026-07-02): 30 empleados E001-E030 + 10 préstamos PR-001..PR-010,
 * conservando los IDs como legajo / codigo.
 *
 * Idempotente por legajo y por código de préstamo. --limpiar-demo borra
 * los datos DEMO-* sembrados para la prueba visual de dev.
 *
 * Mapeo condición → régimen/composición (decisiones P1/P2):
 *   Completa → FORMAL_PURO 100/0/0 · Factura → MONOTRIBUTISTA 0/0/100 ·
 *   Media → MIXTO 0/100/0 (⚠️ default provisorio: el reparto real por
 *   empleado lo define Matías por composición o por override mensual).
 */
class ImportarRosterSueldos extends Command
{
    protected $signature = 'sueldos:importar-roster {--limpiar-demo : Borra los empleados/préstamos DEMO-* de dev} {--dry-run : Solo listar}';

    protected $description = 'Importa los 30 empleados + 10 préstamos del Excel de sueldos (ANEXO 2026-07-02) conservando IDs';

    /** legajo, nombre completo, categoría, condición, sueldo (null=pendiente), ingreso, nota */
    private const EMPLEADOS = [
        ['E001', 'Maximiliano Avalos Mendez', 'Agente/Junior I', 'Media', 700000.00, '2025-04-13', null],
        ['E002', 'Veronica Schumacher', 'Agente/Junior I', 'Media', 700000.00, '2025-04-13', null],
        ['E003', 'Maximiliano Museka', 'Asesor/Junior I', 'Media', 800000.00, '2025-04-10', null],
        ['E004', 'Nelson Benitez', 'Agente/Junior II', 'Media', 1035651.29, '2024-01-01', 'Sube a Junior II'],
        ['E005', 'Florencia Kindewerl', 'Agente/Junior II', 'Media', 1119035.10, '2024-01-01', 'Sube a Junior II'],
        ['E006', 'Ezequiel Bordon', 'Agente/Junior II', 'Media', 1119035.10, '2024-01-01', null],
        ['E007', 'Enzo Espindola', 'Agente/Semi Sr I', 'Media', 1189127.33, '2024-01-01', 'Sube a Semi Sr I'],
        ['E008', 'Marcelo Vargas', 'Asesor/Junior I', 'Media', 1128775.15, '2024-01-01', null],
        ['E009', 'Brenda Streuli', 'Administrativa/Junior', 'Media', 1139142.82, '2024-01-01', null],
        ['E010', 'Ariel Lopez', 'Asesor/Semi Sr I', 'Media', 1301177.31, '2024-01-01', null],
        ['E011', 'Monica Fernandez', 'Administrativa/Semi Sr', 'Media', 1308040.06, '2024-01-01', null],
        ['E012', 'Gerardo Aguirre', 'Agente/Semi Sr II', 'Media', 1471430.74, '2024-01-01', null],
        ['E013', 'Juan Pablo Miranda', 'Líder Expansión', 'Media', 1497327.17, '2024-01-01', null],
        ['E014', 'Dario Gonzalez', 'Supervisor General', 'Media', 1497327.17, '2024-01-01', null],
        ['E015', 'Leandro Martinez', 'Líder Fidelización II', 'Media', 1497327.17, '2024-01-01', null],
        ['E016', 'Zaira Castañare', 'Community Manager', 'Factura', 1531600.00, '2024-01-01', 'Factura los servicios'],
        ['E017', 'Luciano Baez', 'Líder Post-Venta', 'Media', 1632086.61, '2024-01-01', null],
        ['E018', 'Ximena Maldonado', 'Líder Liquidaciones', 'Media', 1647059.88, '2024-01-01', null],
        ['E019', 'Joel Romero', 'Encargado Expansión', 'Media', 1765716.00, '2024-01-01', null],
        ['E020', 'David Gimenez', 'Director RRHH', 'Media', 1861261.58, '2024-01-01', null],
        ['E021', 'Francisco Morell', 'Encargado Sistemas', 'Factura', 2245990.75, '2024-01-01', 'Factura los servicios'],
        ['E022', 'Sebastian Alejandro', 'Contador', 'Media', 1962746.36, '2024-01-01', 'Ajustes especiales a mano'],
        ['E023', 'Matias Sanchez', 'Tesorero', 'Media', 2012586.85, '2024-01-01', 'Ajustes especiales a mano'],
        ['E024', 'Luis Josias Barrios', 'CEO', 'Completa', 1600000.00, '2024-01-01', 'Todo por recibo'],
        ['E025', 'Valentina Perez', 'Agente/Junior I', 'Media', 700000.00, '2025-04-20', null],
        ['E026', 'Carlos Gomez', 'Agente/Junior I', 'Media', 700000.00, '2025-04-20', null],
        ['E027', 'Pablo Ponce', 'Agente/Junior I', 'Media', 700000.00, '2025-04-20', null],
        ['E028', 'Eric Monges', 'Agente/Junior I', 'Media', 700000.00, '2025-04-20', null],
        ['E029', 'Cecilia Frowein', 'Agente/Junior I', 'Media', null, '2026-06-01', 'Nueva'],
        ['E030', 'Sofia Bogado', 'Agente/Junior I', 'Media', null, '2026-06-01', 'Nueva'],
    ];

    /** codigo, legajo empleado, monto_total, cuotas_totales, cuotas_pagadas, cuota_mensual */
    private const PRESTAMOS = [
        ['PR-001', 'E029', 620000.00, 6, 4, 103333.33],
        ['PR-002', 'E020', 696000.00, 4, 2, 174000.00],
        ['PR-003', 'E010', 901549.98, 6, 3, 150258.33],
        ['PR-004', 'E019', 3000000.00, 5, 2, 600000.00],
        ['PR-005', 'E019', 252000.00, 3, 1, 84000.00],
        ['PR-006', 'E006', 991999.98, 6, 3, 165333.33],
        ['PR-007', 'E018', 1240000.02, 6, 3, 206666.67],
        ['PR-008', 'E021', 558000.00, 6, 4, 93000.00],
        ['PR-009', 'E021', 2720000.00, 9, 2, 302222.22],
        ['PR-010', 'E007', 2800000.00, 10, 0, 280000.00],
    ];

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');

        if ($this->option('limpiar-demo')) {
            $this->limpiarDemo($dry);
        }

        $creadosEmp = 0;
        $creadosPre = 0;

        DB::transaction(function () use ($dry, &$creadosEmp, &$creadosPre) {
            foreach (self::EMPLEADOS as [$legajo, $nombreCompleto, $categoria, $condicion, $sueldo, $ingreso, $nota]) {
                if (DB::table('erp_emp_empleados')->where('legajo', $legajo)->exists()) {
                    continue;
                }
                if ($dry) {
                    $this->line("[dry-run] empleado {$legajo} {$nombreCompleto}");
                    $creadosEmp++;

                    continue;
                }

                $partes = explode(' ', $nombreCompleto, 2);
                [$regimen, $comp] = match ($condicion) {
                    'Completa' => ['FORMAL_PURO', [100, 0, 0]],
                    'Factura' => ['MONOTRIBUTISTA', [0, 0, 100]],
                    default => ['MIXTO', [0, 100, 0]], // ⚠️ reparto real lo define Matías
                };

                $obs = trim('Importado del Excel (ANEXO 2026-07-02). '.($nota ?? ''));
                if ($sueldo === null) {
                    $obs .= ' — REQUIERE COMPLETAR sueldo básico.';
                }

                $empId = DB::table('erp_emp_empleados')->insertGetId([
                    'legajo' => $legajo,
                    'nombre' => $partes[0],
                    'apellido' => $partes[1] ?? $partes[0],
                    'fecha_ingreso' => $ingreso,
                    'categoria_id' => $this->categoriaId($categoria),
                    'regimen' => $regimen,
                    'jornada_formal_pct' => $comp[0],
                    'es_vendedor' => 0, 'paga_sac' => 1, 'activo' => 1,
                    'observaciones' => $obs,
                    'created_at' => now(), 'updated_at' => now(),
                ]);

                if ($sueldo !== null) {
                    DB::table('erp_emp_basicos_historial')->insert([
                        'empleado_id' => $empId, 'basico_total' => $sueldo,
                        'vigencia_desde' => '2026-07-01', 'vigencia_hasta' => null,
                        'motivo' => 'INGRESO', 'fecha_aprobacion' => now(),
                        'observaciones' => 'Básico del Excel al 2026-07-02',
                        'created_at' => now(),
                    ]);
                }
                DB::table('erp_emp_composicion_sueldo')->insert([
                    'empleado_id' => $empId,
                    'porc_formal' => $comp[0], 'porc_efectivo' => $comp[1], 'porc_mt' => $comp[2],
                    'vigencia_desde' => '2026-07-01', 'vigencia_hasta' => null,
                    'created_at' => now(),
                ]);
                $creadosEmp++;
            }

            foreach (self::PRESTAMOS as [$codigo, $legajo, $capital, $cuotasTot, $cuotasPag, $cuota]) {
                if (DB::table('erp_emp_prestamos')->where('codigo', $codigo)->exists()) {
                    continue;
                }
                if ($dry) {
                    $this->line("[dry-run] préstamo {$codigo} → {$legajo}");
                    $creadosPre++;

                    continue;
                }
                $empId = DB::table('erp_emp_empleados')->where('legajo', $legajo)->value('id');
                if (! $empId) {
                    $this->error("préstamo {$codigo}: empleado {$legajo} inexistente");

                    continue;
                }
                DB::table('erp_emp_prestamos')->insert([
                    'codigo' => $codigo,
                    'empleado_id' => $empId,
                    'fecha_otorgamiento' => '2026-01-01',
                    'capital' => $capital,
                    'cuotas_total' => $cuotasTot,
                    'cuotas_pagadas' => $cuotasPag,
                    'cuota_mensual' => $cuota,
                    'saldo_capital' => round($capital - $cuotasPag * $cuota, 2),
                    'primera_cuota_periodo' => '2026-01',
                    'estado' => 'VIGENTE',
                    'observaciones' => 'Importado del Excel (ANEXO 2026-07-02)',
                ]);
                $creadosPre++;
            }
        });

        $this->info(($dry ? '[dry-run] ' : '')."Empleados nuevos: {$creadosEmp} · Préstamos nuevos: {$creadosPre}");
        $this->comment('⚠️ Los MEDIA quedaron 0/100/0 (efectivo) — el reparto real por empleado lo define Matías (composición o override mensual).');

        return self::SUCCESS;
    }

    private function categoriaId(string $nombre): ?int
    {
        $id = DB::table('erp_emp_categorias')->where('nombre', $nombre)->value('id');
        if ($id) {
            return (int) $id;
        }

        return (int) DB::table('erp_emp_categorias')->insertGetId([
            'convenio_id' => DB::table('erp_emp_convenios')->value('id'),
            'codigo' => strtoupper(substr(preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: $nombre), 0, 18)),
            'nombre' => $nombre, 'activa' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function limpiarDemo(bool $dry): void
    {
        $demoIds = DB::table('erp_emp_empleados')->where('legajo', 'like', 'DEMO-%')->pluck('id');
        if ($demoIds->isEmpty()) {
            return;
        }
        if ($dry) {
            $this->line('[dry-run] borraría '.$demoIds->count().' empleados DEMO-* con sus datos');

            return;
        }

        DB::transaction(function () use ($demoIds) {
            $liqIds = DB::table('erp_emp_liquidaciones_items')->whereIn('empleado_id', $demoIds)
                ->distinct()->pluck('liquidacion_id');
            DB::table('erp_emp_liquidaciones_items')->whereIn('liquidacion_id', $liqIds)->delete();
            DB::table('erp_emp_liquidacion_reparto_override')->whereIn('liquidacion_id', $liqIds)->delete();
            DB::table('erp_emp_export_liber')->whereIn('liquidacion_id', $liqIds)->delete();
            DB::table('erp_emp_pagos')->whereIn('liquidacion_id', $liqIds)->delete();
            DB::table('erp_emp_liquidaciones')->whereIn('id', $liqIds)->delete();
            DB::table('erp_emp_novedades')->whereIn('empleado_id', $demoIds)->delete();
            DB::table('erp_emp_prestamos')->whereIn('empleado_id', $demoIds)->delete();
            DB::table('erp_emp_basicos_historial')->whereIn('empleado_id', $demoIds)->delete();
            DB::table('erp_emp_composicion_sueldo')->whereIn('empleado_id', $demoIds)->delete();
            DB::table('erp_emp_empleados')->whereIn('id', $demoIds)->delete();
        });
        $this->info('Datos DEMO-* eliminados ('.$demoIds->count().' empleados y sus liquidaciones/préstamos).');
    }
}

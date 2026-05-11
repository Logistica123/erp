<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.14 ampliación 2026-05-10 — ABM de Centros de Costo.
 *
 *   1. ALTER erp_centros_costo: agregar columnas de soft delete/reactivación
 *      (espejo del v1.15 Plan de Cuentas).
 *      NOTA: `activo` ya existe desde el seed (campo TINYINT). Sumamos audit.
 *
 *   2. Renombrar códigos existentes `CLI-XXXX` (ID padded) → `CLI-{slug}` para
 *      reflejar la decisión CC-07. Manejo de colisión con sufijo numérico.
 *
 *   3. INSERT 3 permisos: contabilidad.centros_costo.crear/editar/eliminar
 *      asignados a super_admin + contador.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ----- 1. ALTER erp_centros_costo -----------------------------------
        $this->addCol('erp_centros_costo', 'eliminada_at',
            "DATETIME NULL COMMENT 'v1.14 ampliación — soft delete audit.'");
        $this->addCol('erp_centros_costo', 'eliminada_por',
            "BIGINT UNSIGNED NULL");
        $this->addCol('erp_centros_costo', 'reactivada_at',
            "DATETIME NULL");
        $this->addCol('erp_centros_costo', 'reactivada_por',
            "BIGINT UNSIGNED NULL");
        $this->addCol('erp_centros_costo', 'observaciones',
            "TEXT NULL COMMENT 'Texto libre del operador al crear/editar CC.'");

        if (! $this->fkExists('erp_centros_costo', 'fk_cc_elim_por')) {
            DB::statement('ALTER TABLE erp_centros_costo ADD CONSTRAINT fk_cc_elim_por FOREIGN KEY (eliminada_por) REFERENCES users(id)');
        }
        if (! $this->fkExists('erp_centros_costo', 'fk_cc_react_por')) {
            DB::statement('ALTER TABLE erp_centros_costo ADD CONSTRAINT fk_cc_react_por FOREIGN KEY (reactivada_por) REFERENCES users(id)');
        }

        // ----- 2. Renombrar códigos `CLI-XXXX` → `CLI-{slug}` --------------
        $ccsCliente = DB::table('erp_centros_costo as cc')
            ->join('erp_auxiliares as a', 'a.id', '=', 'cc.auxiliar_id')
            ->where('cc.tipo', 'CLIENTE')
            ->where('cc.codigo', 'like', 'CLI-____') // 4 dígitos (formato ID padded del v1.14 base)
            ->select('cc.id', 'cc.codigo as codigo_actual', 'cc.empresa_id', 'a.nombre')
            ->get();

        $usadosPorEmpresa = []; // empresa_id => [codigo => true]
        foreach ($ccsCliente as $cc) {
            $slug = $this->slugificar($cc->nombre);
            $codigoNuevo = 'CLI-'.$slug;
            $sufijo = 0;
            $codigoFinal = $codigoNuevo;
            // Sufijos -2, -3, … si ya está tomado (en la DB y en lo que ya renombramos).
            while (true) {
                $tomado = DB::table('erp_centros_costo')
                    ->where('empresa_id', $cc->empresa_id)
                    ->where('codigo', $codigoFinal)
                    ->where('id', '!=', $cc->id)
                    ->exists()
                || isset($usadosPorEmpresa[$cc->empresa_id][$codigoFinal]);
                if (! $tomado) break;
                $sufijo++;
                $codigoFinal = $codigoNuevo.'-'.$sufijo;
            }
            DB::table('erp_centros_costo')
                ->where('id', $cc->id)
                ->update(['codigo' => $codigoFinal]);
            $usadosPorEmpresa[$cc->empresa_id][$codigoFinal] = true;
        }

        // ----- 3. Permisos --------------------------------------------------
        foreach (['crear', 'editar', 'eliminar'] as $accion) {
            $codigo = "contabilidad.centros_costo.{$accion}";
            if (! DB::table('erp_permisos')->where('codigo', $codigo)->exists()) {
                DB::table('erp_permisos')->insert([
                    'codigo' => $codigo,
                    'modulo' => 'contabilidad',
                    'entidad' => 'centros_costo',
                    'accion' => $accion,
                    'descripcion' => "Permite {$accion} centros de costos.",
                    'sensible' => 0,
                ]);
            }
            $permId = DB::table('erp_permisos')->where('codigo', $codigo)->value('id');
            $roles = DB::table('erp_roles')->whereIn('codigo', ['super_admin', 'contador'])->pluck('id');
            foreach ($roles as $rolId) {
                DB::table('erp_rol_permiso')->updateOrInsert(
                    ['rol_id' => $rolId, 'permiso_id' => $permId],
                    ['rol_id' => $rolId, 'permiso_id' => $permId]
                );
            }
        }
    }

    public function down(): void
    {
        foreach (['crear', 'editar', 'eliminar'] as $accion) {
            $codigo = "contabilidad.centros_costo.{$accion}";
            DB::statement("DELETE FROM erp_rol_permiso WHERE permiso_id IN (SELECT id FROM erp_permisos WHERE codigo = ?)", [$codigo]);
            DB::statement("DELETE FROM erp_permisos WHERE codigo = ?", [$codigo]);
        }
        try { DB::statement('ALTER TABLE erp_centros_costo DROP FOREIGN KEY fk_cc_react_por'); } catch (\Throwable) {}
        try { DB::statement('ALTER TABLE erp_centros_costo DROP FOREIGN KEY fk_cc_elim_por'); } catch (\Throwable) {}
        foreach (['observaciones', 'reactivada_por', 'reactivada_at', 'eliminada_por', 'eliminada_at'] as $col) {
            try { DB::statement("ALTER TABLE erp_centros_costo DROP COLUMN {$col}"); } catch (\Throwable) {}
        }
    }

    /**
     * v1.14 CC-07: slug de un nombre. UPPER + sin acentos + [A-Z0-9] + recortado a 12.
     */
    private function slugificar(string $nombre): string
    {
        $sinAcentos = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
        if ($sinAcentos === false) $sinAcentos = $nombre;
        $upper = strtoupper((string) $sinAcentos);
        // Tomar primera palabra "significativa" — saca SA, SRL, SOC, & CIA, etc.
        $tokens = preg_split('/[^A-Z0-9]+/', $upper) ?: [];
        $significativos = array_filter($tokens, fn ($t) => $t !== '' && ! in_array($t, ['SA', 'SRL', 'SAS', 'SOC', 'CIA', 'CO', 'INC', 'LTD', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y'], true));
        $primera = array_values($significativos)[0] ?? 'SC'; // 'SC' = Sin Clasificar
        return substr($primera, 0, 12);
    }

    private function addCol(string $table, string $column, string $definition): void
    {
        $exists = DB::selectOne(
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?',
            [$table, $column]
        );
        if (! $exists) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private function fkExists(string $table, string $fk): bool
    {
        return (bool) DB::selectOne(
            'SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME=? AND CONSTRAINT_TYPE="FOREIGN KEY"',
            [$table, $fk]
        );
    }
};

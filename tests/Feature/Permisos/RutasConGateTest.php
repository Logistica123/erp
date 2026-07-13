<?php

namespace Tests\Feature\Permisos;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Item 8 (auditoría 2026-07-12, hallazgo 2) — cobertura de gates.
 *
 * Toda ruta del grupo erp.auth debe declarar `erp.permiso:{codigo}` o
 * `erp.superadmin`, salvo que esté en la whitelist temporal
 * (tests/Fixtures/rutas_sin_gate_whitelist.php) o en la whitelist
 * PERMANENTE de abajo (D-10: catálogos y sesión).
 *
 * Este test es el mecanismo anti-regresión: un controller nuevo sin gate
 * rompe la suite — no puede repetirse el hueco de los 51 controllers.
 * La Fase 2B vacía la whitelist temporal módulo a módulo.
 */
class RutasConGateTest extends TestCase
{
    /**
     * D-10 (decisión Matías 2026-07-12): whitelist PERMANENTE — sesión y
     * catálogos estáticos de dropdowns. Cada entrada con justificación.
     */
    private const WHITELIST_PERMANENTE = [
        'GET|HEAD api/erp/auth/me',          // identidad de la sesión
        'POST api/erp/auth/logout',          // cerrar sesión propia
        'POST api/erp/auth/mfa/verificar',   // flujo MFA propio
        'GET|HEAD api/erp/mi-permisos',      // el frontend necesita leerlos siempre
    ];

    public function test_toda_ruta_del_grupo_erp_auth_declara_gate(): void
    {
        $whitelistTemporal = require base_path('tests/Fixtures/rutas_sin_gate_whitelist.php');
        $permitidas = array_merge($whitelistTemporal, self::WHITELIST_PERMANENTE);

        $sinGate = [];
        foreach (Route::getRoutes() as $r) {
            $mw = $r->middleware();
            if (! in_array('erp.auth', $mw, true)) {
                continue; // fuera del grupo autenticado (webhooks HMAC, login…)
            }
            $tieneGate = collect($mw)->contains(
                fn ($m) => (is_string($m) && str_starts_with($m, 'erp.permiso:')) || $m === 'erp.superadmin'
            );
            $firma = implode('|', $r->methods()).' '.$r->uri();
            if (! $tieneGate && ! in_array($firma, $permitidas, true)) {
                $sinGate[] = $firma;
            }
        }

        $this->assertSame([], $sinGate,
            count($sinGate)." ruta(s) nueva(s) sin gate de permiso (agregar erp.permiso:... o justificar en whitelist):\n - "
            .implode("\n - ", array_slice($sinGate, 0, 20)));
    }

    public function test_los_permisos_declarados_en_rutas_existen_en_el_catalogo(): void
    {
        $codigos = [];
        foreach (Route::getRoutes() as $r) {
            foreach ($r->middleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'erp.permiso:')) {
                    $codigos[] = substr($m, strlen('erp.permiso:'));
                }
            }
        }
        $codigos = array_values(array_unique($codigos));

        $existentes = \Illuminate\Support\Facades\DB::table('erp_permisos')
            ->whereIn('codigo', $codigos)->pluck('codigo')->all();
        $faltantes = array_values(array_diff($codigos, $existentes));

        $this->assertSame([], $faltantes,
            'Permisos usados en rutas que NO existen en erp_permisos (typo o falta seeder): '.implode(', ', $faltantes));
    }

    public function test_matriz_super_admin_sin_baches(): void
    {
        // B.7 — test de robustez: con el bypass apagado, super_admin tiene
        // que poder pasar TODOS los gates declarados usando solo sus
        // permisos asignados. Cada faltante es un bache de matriz.
        $codigos = [];
        foreach (Route::getRoutes() as $r) {
            foreach ($r->middleware() as $m) {
                if (is_string($m) && str_starts_with($m, 'erp.permiso:')) {
                    $codigos[] = substr($m, strlen('erp.permiso:'));
                }
            }
        }
        $codigos = array_values(array_unique($codigos));

        $asignados = \Illuminate\Support\Facades\DB::table('erp_rol_permiso as rp')
            ->join('erp_roles as r', 'r.id', '=', 'rp.rol_id')
            ->join('erp_permisos as p', 'p.id', '=', 'rp.permiso_id')
            ->where('r.codigo', 'super_admin')
            ->pluck('p.codigo')->all();

        $baches = array_values(array_diff($codigos, $asignados));

        $this->assertSame([], $baches,
            'Baches de matriz: permisos usados en rutas que super_admin NO tiene asignados '
            .'(con ERP_SUPERADMIN_BYPASS=false quedaría bloqueado): '.implode(', ', $baches));
    }
}

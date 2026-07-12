<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Auditoría 2026-07-12 bug #4 — claves de config ARCA que no existían:
 * - services.arca_gateway.cuit_representado: el código la leía pero no
 *   estaba declarada → caía SIEMPRE al CUIT hardcodeado en el fuente.
 * - ArcaController leía services.arca.gateway_url (namespace inexistente)
 *   → devolvía null en el healthcheck.
 */
class ConfigArcaGatewayTest extends TestCase
{
    public function test_cuit_representado_declarado_en_config(): void
    {
        $this->assertNotNull(
            config('services.arca_gateway.cuit_representado'),
            'services.arca_gateway.cuit_representado debe estar declarada (env ARCA_CUIT_REPRESENTADO)'
        );
        $this->assertMatchesRegularExpression('/^\d{11}$/', (string) config('services.arca_gateway.cuit_representado'));
    }

    public function test_no_queda_ninguna_referencia_al_namespace_inexistente_services_arca(): void
    {
        $hits = [];
        foreach (glob(app_path('Erp/**/*.php')) ?: [] as $f) {
            $hits = array_merge($hits, $this->grep($f));
        }
        foreach (glob(app_path('Erp/*/*/*.php')) ?: [] as $f) {
            $hits = array_merge($hits, $this->grep($f));
        }

        $this->assertSame([], $hits,
            "Referencias a config('services.arca.…') (namespace inexistente): ".implode(', ', $hits));
    }

    /** @return list<string> */
    private function grep(string $file): array
    {
        $src = @file_get_contents($file) ?: '';

        return preg_match("/config\\(\\s*['\"]services\\.arca\\./", $src)
            ? [str_replace(base_path().'/', '', $file)]
            : [];
    }
}

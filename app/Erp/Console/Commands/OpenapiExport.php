<?php

namespace App\Erp\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Yaml\Yaml;

/**
 * Genera un OpenAPI 3.0 YAML reflejando las rutas reales de api/erp/* del
 * router de Laravel. Lee el docblock del controlador para extraer summary
 * (primera línea no vacía del comentario). Útil para mantener la spec
 * `arquitecturas/05_API/openapi_erp.yaml` sincronizada con la implementación.
 *
 * Uso:
 *   php artisan erp:openapi-export > /path/to/openapi_erp.yaml
 *   php artisan erp:openapi-export --out=arquitecturas/05_API/openapi_erp.yaml
 */
class OpenapiExport extends Command
{
    protected $signature = 'erp:openapi-export {--out= : Ruta del archivo de salida (default stdout)}';

    protected $description = 'Genera openapi_erp.yaml reflejando las rutas api/erp/* actuales del router.';

    public function handle(Router $router): int
    {
        $paths = [];

        /** @var Route $route */
        foreach ($router->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/erp/')) {
                continue;
            }
            $path = '/' . substr($uri, strlen('api/erp/'));
            // Laravel usa {id} pero también whereNumber → mantenemos {id}.
            $methods = array_diff($route->methods(), ['HEAD']);
            foreach ($methods as $method) {
                $verb = strtolower($method);
                $action = $route->getActionName();
                [$summary, $tag] = $this->describeAction($action);
                $paths[$path] ??= [];
                $paths[$path][$verb] = array_filter([
                    'summary' => $summary ?: $route->getName(),
                    'operationId' => $route->getName() ?: $this->slugify($verb.' '.$path),
                    'tags' => [$tag],
                    'parameters' => $this->extractPathParams($path),
                    'responses' => [
                        '200' => ['description' => 'OK'],
                        '4XX' => ['description' => 'Error de cliente'],
                        '5XX' => ['description' => 'Error de servidor'],
                    ],
                    'security' => str_contains((string) $route->getName(), 'auth.login') ? null : [['bearerAuth' => []]],
                ]);
            }
        }

        ksort($paths);

        $doc = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'ERP Logística Argentina — API',
                'version' => '2026.04.26',
                'description' => 'Auto-generado desde routes/erp.php por `php artisan erp:openapi-export`.',
            ],
            'servers' => [
                ['url' => 'https://erp.distriapp.com.ar', 'description' => 'Prod'],
                ['url' => 'http://localhost:8000', 'description' => 'Local'],
            ],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Sanctum personal access token',
                    ],
                ],
            ],
            'paths' => $paths,
        ];

        $yaml = Yaml::dump($doc, 6, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);

        if ($out = $this->option('out')) {
            file_put_contents($out, $yaml);
            $this->info("OpenAPI escrito en {$out} (".count($paths)." paths).");
        } else {
            $this->line($yaml);
        }
        return self::SUCCESS;
    }

    private function describeAction(string $action): array
    {
        if (! str_contains($action, '@')) {
            return [null, 'general'];
        }
        [$class, $method] = explode('@', $action);
        $tag = $this->tagFromClass($class);
        try {
            $rc = new ReflectionClass($class);
            $rm = $rc->getMethod($method);
            $summary = $this->firstDocLine($rm);
            return [$summary, $tag];
        } catch (\Throwable) {
            return [null, $tag];
        }
    }

    private function firstDocLine(ReflectionMethod $rm): ?string
    {
        $doc = $rm->getDocComment();
        if (! $doc) return null;
        $lines = preg_split('/\r?\n/', $doc) ?: [];
        foreach ($lines as $l) {
            $l = trim(preg_replace('#^\s*/?\*+/?#', '', $l) ?? '');
            if ($l === '' || str_starts_with($l, '@')) continue;
            return mb_substr($l, 0, 200);
        }
        return null;
    }

    private function tagFromClass(string $class): string
    {
        $base = preg_replace('/Controller$/', '', class_basename($class));
        return strtolower(preg_replace('/(?<!^)([A-Z])/', '-$1', $base) ?? $base);
    }

    private function extractPathParams(string $path): array
    {
        if (! preg_match_all('/\{([a-zA-Z_]+)\}/', $path, $m)) return [];
        $params = [];
        foreach ($m[1] as $name) {
            $params[] = [
                'name' => $name,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => str_contains($name, 'id') ? 'integer' : 'string'],
            ];
        }
        return $params;
    }

    private function slugify(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? $s;
    }
}

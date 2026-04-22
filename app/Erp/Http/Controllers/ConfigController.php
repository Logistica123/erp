<?php

namespace App\Erp\Http\Controllers;

use App\Erp\Models\Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Configuración tipada por empresa (SPEC_01 §5.1, §6.1).
 *
 *   GET   /api/erp/config                Todas las claves de la empresa activa (filtrables)
 *   PATCH /api/erp/config/{clave}         Actualiza el valor (respeta tipo y editable=1)
 */
class ConfigController
{
    public function index(Request $request): JsonResponse
    {
        $items = Config::query()
            ->when($request->query('empresa_id'), fn ($q, $v) => $q->where('empresa_id', $v))
            ->when($request->query('categoria'), fn ($q, $v) => $q->where('categoria', $v))
            ->orderBy('categoria')->orderBy('clave')
            ->get()
            ->map(fn (Config $c) => [
                'clave' => $c->clave,
                'categoria' => $c->categoria,
                'tipo' => $c->tipo,
                'valor' => $c->valor_tipado,
                'valor_raw' => $c->valor,
                'editable' => $c->editable,
                'descripcion' => $c->descripcion,
            ]);

        return response()->json(['ok' => true, 'data' => $items]);
    }

    public function update(Request $request, string $clave): JsonResponse
    {
        $config = Config::where('clave', $clave)
            ->when($request->query('empresa_id'), fn ($q, $v) => $q->where('empresa_id', $v))
            ->firstOrFail();

        if (! $config->editable) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'CLAVE_NO_EDITABLE', 'message' => "La clave {$clave} es de solo lectura."],
            ], 422);
        }

        $data = $request->validate(['valor' => ['required']]);
        $raw = $this->serializarSegunTipo($config->tipo, $data['valor']);

        if ($raw === null) {
            return response()->json([
                'ok' => false,
                'error' => ['code' => 'VALOR_INVALIDO', 'message' => "Valor inválido para tipo {$config->tipo}."],
            ], 422);
        }

        $config->update(['valor' => $raw]);

        return response()->json([
            'ok' => true,
            'data' => ['clave' => $config->clave, 'valor' => $config->fresh()->valor_tipado],
        ]);
    }

    private function serializarSegunTipo(string $tipo, mixed $valor): ?string
    {
        return match ($tipo) {
            'STRING' => is_scalar($valor) ? (string) $valor : null,
            'INT' => is_numeric($valor) ? (string) (int) $valor : null,
            'DECIMAL' => is_numeric($valor) ? (string) (float) $valor : null,
            'BOOLEAN' => is_bool($valor) || in_array($valor, ['0', '1', 0, 1, 'true', 'false'], true)
                ? (filter_var($valor, FILTER_VALIDATE_BOOLEAN) ? '1' : '0')
                : null,
            'JSON' => is_array($valor) || is_object($valor) ? json_encode($valor) : null,
            'DATE' => is_string($valor) && strtotime($valor) !== false ? date('Y-m-d', strtotime($valor)) : null,
            default => null,
        };
    }
}

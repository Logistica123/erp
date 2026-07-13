<?php

namespace App\Erp\Support;

use Illuminate\Support\Facades\DB;

/**
 * Mini-tanda 2026-07-13, bug 3 — garantía única de "todo Cliente tiene su
 * Centro de Costos" (contrato v1.14).
 *
 * Lógica extraída de AuxiliarClienteObserver: el observer solo dispara por
 * Eloquent, y los caminos de integración (imports, DistriAppBridge, sync
 * de OP, apertura) crean auxiliares con insert crudo — así nacieron los 9
 * clientes sin CC de prod. Esos caminos ahora llaman asegurar() explícito.
 */
class CcCliente
{
    /** Crea el CC del auxiliar Cliente si no existe. No-op para otros tipos. */
    public static function asegurar(int $auxiliarId): void
    {
        $aux = DB::table('erp_auxiliares')->where('id', $auxiliarId)->first();
        if (! $aux || $aux->tipo !== 'Cliente') {
            return;
        }
        if (DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->exists()) {
            return;
        }

        // v1.14 CC-07: código slug `CLI-OCASA` con sufijo ante colisión.
        $slug = self::slugificar($aux->nombre);
        $codigo = 'CLI-'.$slug;
        $sufijo = 0;
        $codigoFinal = $codigo;
        while (DB::table('erp_centros_costo')
            ->where('empresa_id', $aux->empresa_id)
            ->where('codigo', $codigoFinal)
            ->exists()
        ) {
            $sufijo++;
            $codigoFinal = $codigo.'-'.$sufijo;
        }

        DB::table('erp_centros_costo')->insert([
            'empresa_id' => $aux->empresa_id,
            'codigo' => $codigoFinal,
            'nombre' => $aux->nombre,
            'tipo' => 'CLIENTE',
            'auxiliar_id' => $aux->id,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** v1.14 CC-07: slug de un nombre. UPPER + sin acentos + [A-Z0-9] + 12 chars. */
    public static function slugificar(string $nombre): string
    {
        $sinAcentos = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
        if ($sinAcentos === false) {
            $sinAcentos = $nombre;
        }
        $upper = strtoupper((string) $sinAcentos);
        $tokens = preg_split('/[^A-Z0-9]+/', $upper) ?: [];
        $stop = ['SA', 'SRL', 'SAS', 'SOC', 'CIA', 'CO', 'INC', 'LTD', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y'];
        $significativos = array_filter($tokens, fn ($t) => $t !== '' && ! in_array($t, $stop, true));
        $primera = array_values($significativos)[0] ?? 'SC';

        return substr($primera, 0, 12);
    }
}

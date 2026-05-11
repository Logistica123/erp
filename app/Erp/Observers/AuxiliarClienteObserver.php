<?php

namespace App\Erp\Observers;

use App\Erp\Models\Auxiliar;
use Illuminate\Support\Facades\DB;

/**
 * ADDENDUM v1.14 — Observer que crea automáticamente un Centro de Costos
 * (`erp_centros_costo` con tipo='CLIENTE') por cada auxiliar tipo='Cliente'
 * recién dado de alta.
 *
 * El centro_costo_id luego se referencia desde `erp_facturas_venta` y
 * `erp_facturas_compra` para reportes de margen por cliente, etc.
 *
 * Idempotente: si ya existe un CC con `auxiliar_id = X`, no hace nada.
 */
class AuxiliarClienteObserver
{
    public function created(Auxiliar $aux): void
    {
        if ($aux->tipo !== 'Cliente') {
            return;
        }
        $this->ensureCentroCosto($aux);
    }

    public function updated(Auxiliar $aux): void
    {
        if ($aux->tipo !== 'Cliente') {
            return;
        }
        // Si el auxiliar pasó a tipo Cliente desde otro tipo, garantizamos
        // el CC; si solo cambió el nombre, sincronizamos el nombre del CC.
        $cc = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->first();
        if (! $cc) {
            $this->ensureCentroCosto($aux);
            return;
        }
        if ($cc->nombre !== $aux->nombre) {
            DB::table('erp_centros_costo')
                ->where('id', $cc->id)
                ->update(['nombre' => $aux->nombre, 'updated_at' => now()]);
        }
    }

    private function ensureCentroCosto(Auxiliar $aux): void
    {
        $existe = DB::table('erp_centros_costo')->where('auxiliar_id', $aux->id)->exists();
        if ($existe) return;

        // v1.14 ampliación 2026-05-10 (CC-07): código slug `CLI-OCASA` en
        // lugar de `CLI-0042` (ID padded). Manejo de colisión con sufijo.
        $slug = $this->slugificar($aux->nombre);
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

    /**
     * v1.14 CC-07: slug de un nombre. UPPER + sin acentos + [A-Z0-9] + 12 chars.
     */
    private function slugificar(string $nombre): string
    {
        $sinAcentos = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre);
        if ($sinAcentos === false) $sinAcentos = $nombre;
        $upper = strtoupper((string) $sinAcentos);
        $tokens = preg_split('/[^A-Z0-9]+/', $upper) ?: [];
        $stop = ['SA', 'SRL', 'SAS', 'SOC', 'CIA', 'CO', 'INC', 'LTD', 'DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y'];
        $significativos = array_filter($tokens, fn ($t) => $t !== '' && ! in_array($t, $stop, true));
        $primera = array_values($significativos)[0] ?? 'SC';
        return substr($primera, 0, 12);
    }
}

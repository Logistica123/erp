<?php

namespace App\Erp\Services\Integracion;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Puente ERP ↔ DistriApp (basepersonal).
 *
 * Lee las vistas cross-DB `erp_v_clientes_distriapp` y `erp_v_distribuidores`
 * que viven en `erp_logistica_prod` y hacen JOIN contra `basepersonal.*`.
 *
 * Política v1 (ver SPEC 07 §0 D-07-02):
 * - DistriApp manda en operaciones.
 * - El ERP sincroniza leyendo (nunca escribe en basepersonal).
 */
class DistriAppBridge
{
    /**
     * Clientes activos en DistriApp disponibles para facturar.
     *
     * @return Collection<int, object>
     */
    public function clientes(): Collection
    {
        return collect(DB::select(
            'SELECT * FROM erp_v_clientes_distriapp WHERE activo = 1 ORDER BY razon_social'
        ));
    }

    /**
     * Distribuidores (personas con CUIL).
     *
     * @return Collection<int, object>
     */
    public function distribuidores(): Collection
    {
        return collect(DB::select(
            'SELECT * FROM erp_v_distribuidores ORDER BY apellidos, nombres'
        ));
    }

    /**
     * Sincroniza clientes DistriApp → erp_auxiliares (tipo=Cliente).
     *
     * Idempotente: usa (empresa_id, tipo, codigo) como natural key.
     * Devuelve {creados, actualizados, total}.
     */
    public function syncClientes(int $empresaId = 1): array
    {
        $creados = 0;
        $actualizados = 0;
        $clientes = $this->clientes();

        foreach ($clientes as $c) {
            $codigo = 'DA-CLI-' . str_pad((string) $c->distriapp_id, 5, '0', STR_PAD_LEFT);
            $cuitLimpio = $c->cuit ? preg_replace('/[^0-9]/', '', $c->cuit) : null;
            if ($cuitLimpio && strlen($cuitLimpio) !== 11) {
                $cuitLimpio = null;  // rechaza CUITs inválidos, no rompe el sync
            }

            $existing = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('tipo', 'Cliente')
                ->where('codigo', $codigo)
                ->first();

            $attrs = [
                'nombre' => $c->razon_social,
                'cuit' => $cuitLimpio,
                'tabla_ref' => 'basepersonal.clientes',
                'id_ref' => $c->distriapp_id,
                'activo' => 1,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('erp_auxiliares')->where('id', $existing->id)->update($attrs);
                $actualizados++;
            } else {
                DB::table('erp_auxiliares')->insert([
                    'empresa_id' => $empresaId,
                    'tipo' => 'Cliente',
                    'codigo' => $codigo,
                    ...$attrs,
                    'created_at' => now(),
                ]);
                $creados++;
            }
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'total' => $clientes->count(),
        ];
    }

    /**
     * Facturas emitidas en DistriApp con CAE (vista erp_v_facturas_distriapp).
     *
     * @return Collection<int, object>
     */
    public function facturasDistriapp(): Collection
    {
        return collect(DB::select(
            'SELECT * FROM erp_v_facturas_distriapp ORDER BY fecha_emision DESC'
        ));
    }

    /**
     * Liquidaciones a distribuidores (vista erp_v_liquidaciones_distrib).
     *
     * @return Collection<int, object>
     */
    public function liquidacionesDistrib(): Collection
    {
        return collect(DB::select(
            'SELECT * FROM erp_v_liquidaciones_distrib ORDER BY periodo_hasta DESC, distriapp_id DESC'
        ));
    }

    /**
     * Sincroniza facturas emitidas DistriApp → erp_facturas_venta.
     *
     * Natural key: (empresa_id, tipo_comprobante_id, punto_venta_id, numero).
     * Infiere condicion_iva del tipo_cbte (FA→RI, FB→CF, FC→MT).
     * No importa items ni IVA discriminado (factura_cabecera no los tiene granulares).
     */
    public function syncFacturas(int $empresaId = 1): array
    {
        // Inferencia condicion_iva AFIP por tipo_cbte
        $condicionPorTipo = [
            1 => 1, 2 => 1, 3 => 1,            // FA/NDA/NCA → RI
            6 => 5, 7 => 5, 8 => 5,            // FB/NDB/NCB → CF
            11 => 6, 12 => 6, 13 => 6,         // FC/NDC/NCC → Monotributo
        ];

        $creados = 0;
        $actualizados = 0;
        $skipped = 0;
        $facturas = $this->facturasDistriapp();

        foreach ($facturas as $f) {
            // Resolver referencias
            $puntoVenta = DB::table('erp_puntos_venta')
                ->where('empresa_id', $empresaId)
                ->where('numero', $f->pto_vta)
                ->first();
            if (!$puntoVenta) {
                $skipped++;
                continue;
            }

            $auxiliar = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('tabla_ref', 'basepersonal.clientes')
                ->where('id_ref', $f->cliente_distriapp_id)
                ->first();
            if (!$auxiliar) {
                $skipped++;
                continue;  // cliente no sincronizado aún, correr syncClientes primero
            }

            $monedaId = DB::table('erp_monedas')
                ->where('codigo', $f->moneda_codigo ?: 'ARS')
                ->value('id') ?? 1;

            $condicionIvaId = $condicionPorTipo[$f->cbte_tipo] ?? 5;  // default CF

            $key = [
                'empresa_id' => $empresaId,
                'tipo_comprobante_id' => $f->cbte_tipo,
                'punto_venta_id' => $puntoVenta->id,
                'numero' => $f->numero,
            ];

            $attrs = [
                'cae' => $f->cae,
                'fecha_vto_cae' => $f->cae_vto,
                'fecha_emision' => $f->fecha_emision,
                'fecha_vencimiento' => $f->fecha_vencimiento,
                'auxiliar_id' => $auxiliar->id,
                'condicion_iva_id' => $condicionIvaId,
                'doc_tipo_afip' => $f->doc_tipo,
                'doc_nro' => (string) $f->doc_nro,
                'moneda_id' => $monedaId,
                'cotizacion' => $f->cotizacion,
                'concepto_afip' => $f->concepto_afip,
                'imp_neto_gravado' => $f->imp_neto_gravado,
                'imp_no_gravado' => $f->imp_no_gravado,
                'imp_exento' => $f->imp_exento,
                'imp_iva' => $f->imp_iva,
                'imp_tributos' => $f->imp_tributos,
                'imp_total' => $f->imp_total,
                'origen' => 'DISTRIAPP',
                'estado' => 'EMITIDA',
                'updated_at' => now(),
            ];

            $existing = DB::table('erp_facturas_venta')->where($key)->first();
            if ($existing) {
                DB::table('erp_facturas_venta')->where('id', $existing->id)->update($attrs);
                $actualizados++;
            } else {
                DB::table('erp_facturas_venta')->insert([
                    ...$key,
                    ...$attrs,
                    'created_at' => now(),
                ]);
                $creados++;
            }
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'skipped' => $skipped,
            'total' => $facturas->count(),
        ];
    }

    /**
     * Sincroniza distribuidores DistriApp → erp_auxiliares (tipo=Distribuidor).
     */
    public function syncDistribuidores(int $empresaId = 1): array
    {
        $creados = 0;
        $actualizados = 0;
        $dists = $this->distribuidores();

        foreach ($dists as $d) {
            $codigo = 'DA-DIST-' . str_pad((string) $d->distriapp_id, 5, '0', STR_PAD_LEFT);
            $cuilLimpio = $d->cuil ? preg_replace('/[^0-9]/', '', $d->cuil) : null;
            if ($cuilLimpio && strlen($cuilLimpio) !== 11) {
                $cuilLimpio = null;
            }

            $existing = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)
                ->where('tipo', 'Distribuidor')
                ->where('codigo', $codigo)
                ->first();

            $attrs = [
                'nombre' => trim($d->nombre_completo ?? ''),
                'cuit' => $cuilLimpio,
                'tabla_ref' => 'basepersonal.personas',
                'id_ref' => $d->distriapp_id,
                'activo' => 1,
                'updated_at' => now(),
            ];

            if ($existing) {
                DB::table('erp_auxiliares')->where('id', $existing->id)->update($attrs);
                $actualizados++;
            } else {
                DB::table('erp_auxiliares')->insert([
                    'empresa_id' => $empresaId,
                    'tipo' => 'Distribuidor',
                    'codigo' => $codigo,
                    ...$attrs,
                    'created_at' => now(),
                ]);
                $creados++;
            }
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'total' => $dists->count(),
        ];
    }
}

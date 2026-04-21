<?php

namespace App\Erp\Services;

use App\Erp\Services\Integracion\ContabilizadorFacturas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Emite una factura de venta end-to-end:
 *   1. Resuelve cliente, PV, tipo, condición IVA.
 *   2. Calcula neto/IVA/total a partir de (cantidad, precio_unit, alícuota).
 *   3. Llama al ArcaGateway (/wsfe/emitir) con idempotency_key determinística.
 *   4. Persiste en erp_facturas_venta con origen=WSFE_ERP, estado=EMITIDA.
 *   5. Dispara la contabilización automática (ContabilizadorFacturas).
 *
 * Errores del gateway se propagan como RuntimeException con el detalle.
 */
class EmisorFacturaService
{
    public function __construct(
        private ContabilizadorFacturas $contabilizador,
    ) {}

    /**
     * @param array{
     *   cliente_id:int,
     *   tipo_comprobante_id:int,
     *   punto_venta_id:int,
     *   concepto_afip:int,
     *   fecha_emision:string,
     *   descripcion:string,
     *   cantidad:float|string,
     *   precio_unit:float|string,
     *   alicuota_iva_id:int,
     *   moneda_id?:int,
     *   cotizacion?:float|string,
     * }  $input
     */
    public function emitir(array $input, int $empresaId = 1, int $usuarioId = 1): array
    {
        // 1. Resolver referencias
        $cliente = DB::table('erp_auxiliares')->where('empresa_id', $empresaId)
            ->where('id', $input['cliente_id'])->first();
        if (!$cliente) throw new RuntimeException('Cliente no existe');

        $pv = DB::table('erp_puntos_venta')->where('empresa_id', $empresaId)
            ->where('id', $input['punto_venta_id'])->first();
        if (!$pv) throw new RuntimeException('Punto de venta no existe');

        $tipoCbte = DB::table('erp_tipos_comprobante')->where('id', $input['tipo_comprobante_id'])->first();
        if (!$tipoCbte) throw new RuntimeException('Tipo de comprobante no existe');

        $alicuota = DB::table('erp_alicuotas_iva')->where('id', $input['alicuota_iva_id'])->first();
        if (!$alicuota) throw new RuntimeException('Alícuota IVA no existe');

        $monedaId = $input['moneda_id'] ?? 1;
        $monedaCodigo = DB::table('erp_monedas')->where('id', $monedaId)->value('codigo') ?? 'ARS';

        // 2. Cálculos
        // AFIP requiere imp_iva e iva[] siempre, excepto letra C (monotributista).
        // `discrimina_iva=0` en tipos B significa "no se muestra al cliente",
        // pero en el payload a WSFE igual hay que declararlo.
        $cantidad = (float) $input['cantidad'];
        $precio = (float) $input['precio_unit'];
        $tasa = (float) $alicuota->tasa;
        $esLetraC = ($tipoCbte->letra === 'C');
        $imp_neto = round($cantidad * $precio, 2);
        $imp_iva = $esLetraC ? 0 : round($imp_neto * $tasa, 2);
        $imp_total = round($imp_neto + $imp_iva, 2);

        // 3. Resolver doc_tipo/doc_nro y condición IVA receptor desde cliente
        [$docTipo, $docNro] = $this->resolverDoc($cliente);
        $condicionIvaId = $this->condicionIvaPorTipo((int) $tipoCbte->id);

        // idempotency_key determinística: hash(cliente,tipo,pv,fecha,total,usuario,timestamp)
        $idem = Str::substr(hash('sha256', implode('|', [
            $empresaId, $input['cliente_id'], $tipoCbte->id, $pv->id,
            $input['fecha_emision'], $imp_total, $usuarioId, now()->timestamp,
        ])), 0, 32);

        // 4. Llamar gateway
        $cfg = config('services.arca_gateway');
        if (empty($cfg['api_key']) || empty($cfg['client_id'])) {
            throw new RuntimeException('ArcaGateway no configurado (ARCA_CLIENT_ID / ARCA_API_KEY)');
        }

        $concepto = (int) $input['concepto_afip'];
        $fecha = $input['fecha_emision'];

        $payload = [
            'idempotency_key' => $idem,
            'tipo_cbte' => (int) $tipoCbte->id,
            'pto_vta' => (int) $pv->numero,
            'concepto' => $concepto,
            'doc_tipo' => $docTipo,
            'doc_nro' => $docNro,
            'condicion_iva_receptor_id' => $condicionIvaId,
            'fecha_cbte' => $fecha,
            'mon_id' => $monedaCodigo === 'ARS' ? 'PES' : $monedaCodigo,
            'mon_cotiz' => (string) ($input['cotizacion'] ?? 1),
            'imp_total' => (string) $imp_total,
            'imp_neto' => (string) $imp_neto,
            'imp_iva' => (string) $imp_iva,
            'imp_tot_conc' => '0',
            'imp_op_ex' => '0',
            'imp_trib' => '0',
        ];
        // Concepto 2 (Servicios) o 3 (Ambos): AFIP requiere fecha_serv_desde/hasta + vto_pago.
        // Autofill con la fecha de emisión si el form no las trajo.
        if (in_array($concepto, [2, 3], true)) {
            $payload['fecha_serv_desde'] = $input['fecha_serv_desde'] ?? $fecha;
            $payload['fecha_serv_hasta'] = $input['fecha_serv_hasta'] ?? $fecha;
            $payload['fecha_venc_pago'] = $input['fecha_venc_pago'] ?? $fecha;
        }
        if ($imp_iva > 0) {
            $payload['iva'] = [[
                'id' => (int) $alicuota->id,  // código AFIP de la alícuota (3,4,5,6,8,9)
                'base_imp' => (string) $imp_neto,
                'importe' => (string) $imp_iva,
            ]];
        }

        $resp = Http::withHeaders([
            'X-Client-Id' => $cfg['client_id'],
            'X-API-Key' => $cfg['api_key'],
            'Accept' => 'application/json',
        ])
        ->asJson()
        ->timeout((int) ($cfg['timeout'] ?? 60))
        ->post(rtrim($cfg['url'], '/').'/wsfe/emitir', $payload);

        if (!$resp->successful()) {
            $body = $resp->json();
            throw new RuntimeException('Gateway error '.$resp->status().': '.json_encode($body));
        }

        $data = $resp->json();
        if (($data['resultado'] ?? null) !== 'A' || empty($data['cae'])) {
            $mensajes = array_merge($data['errores'] ?? [], $data['observaciones'] ?? []);
            $texto = empty($mensajes)
                ? json_encode($data)
                : implode(' | ', array_map(
                    fn ($m) => '['.($m['code'] ?? '?').'] '.($m['msg'] ?? ''),
                    $mensajes
                ));
            throw new RuntimeException('Emisión no aprobada: '.$texto);
        }

        // 5. Persistir
        $facturaId = DB::table('erp_facturas_venta')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => $tipoCbte->id,
            'punto_venta_id' => $pv->id,
            'numero' => $data['cbte_desde'],
            'cae' => $data['cae'],
            'fecha_vto_cae' => $data['cae_vto'] ?? null,
            'fecha_emision' => $input['fecha_emision'],
            'auxiliar_id' => $cliente->id,
            'condicion_iva_id' => $condicionIvaId,
            'doc_tipo_afip' => $docTipo,
            'doc_nro' => (string) $docNro,
            'moneda_id' => $monedaId,
            'cotizacion' => $input['cotizacion'] ?? 1,
            'concepto_afip' => $input['concepto_afip'],
            'imp_neto_gravado' => $imp_neto,
            'imp_no_gravado' => 0,
            'imp_exento' => 0,
            'imp_iva' => $imp_iva,
            'imp_tributos' => 0,
            'imp_total' => $imp_total,
            'origen' => 'WSFE_ERP',
            'estado' => 'EMITIDA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Item (schema erp_factura_venta_items: concepto, imp_neto, alícuota, sin imp_total)
        DB::table('erp_factura_venta_items')->insert([
            'factura_id' => $facturaId,
            'nro_linea' => 1,
            'concepto' => $input['descripcion'],
            'cantidad' => $cantidad,
            'precio_unitario' => $precio,
            'alicuota_iva_id' => $alicuota->id,
            'imp_neto' => $imp_neto,
            'imp_iva' => $imp_iva,
        ]);

        // IVA desglose (si hay)
        if ($imp_iva > 0) {
            DB::table('erp_factura_venta_iva')->insert([
                'factura_id' => $facturaId,
                'alicuota_iva_id' => $alicuota->id,
                'base_imponible' => $imp_neto,
                'importe_iva' => $imp_iva,
            ]);
        }

        // 6. Contabilizar (puede devolver error si período cerrado — no bloquea)
        try {
            $this->contabilizador->contabilizarPendientes($empresaId, $usuarioId);
        } catch (\Throwable $e) {
            // La factura ya tiene CAE; si falla la contabilización, se ve después.
        }

        return [
            'factura_id' => $facturaId,
            'cae' => $data['cae'],
            'cae_vto' => $data['cae_vto'] ?? null,
            'numero' => $data['cbte_desde'],
            'pto_vta' => $pv->numero,
            'tipo_codigo' => $tipoCbte->codigo_interno,
            'letra' => $tipoCbte->letra,
            'imp_neto' => $imp_neto,
            'imp_iva' => $imp_iva,
            'imp_total' => $imp_total,
            'observaciones' => $data['observaciones'] ?? [],
            'fecha_proceso' => $data['fecha_proceso'] ?? null,
        ];
    }

    /** @return array{0:int,1:string} [doc_tipo, doc_nro] */
    private function resolverDoc(object $cliente): array
    {
        if (!empty($cliente->cuit) && strlen($cliente->cuit) === 11) {
            return [80, $cliente->cuit];  // 80 = CUIT
        }
        return [99, '0'];  // 99 = Consumidor Final
    }

    private function condicionIvaPorTipo(int $tipoId): int
    {
        // Match condicion_iva AFIP por tipo de comprobante:
        //   FA/NCA (1,3) → RI (1); FB/NCB (6,8) → CF (5); FC/NCC (11,13) → MT (6)
        return match (true) {
            in_array($tipoId, [1, 2, 3], true) => 1,
            in_array($tipoId, [6, 7, 8], true) => 5,
            in_array($tipoId, [11, 12, 13], true) => 6,
            default => 5,
        };
    }
}

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
        private ArcaGatewayClient $gateway,
    ) {}

    /**
     * Acepta dos formatos de entrada:
     *
     * A) Multi-item (preferido): input['items'] = [
     *       {descripcion, cantidad, precio_unit, alicuota_iva_id}, ...
     *    ]
     *
     * B) Single-item (back-compat): input.descripcion / cantidad / precio_unit / alicuota_iva_id
     *
     * El resto (cliente_id, tipo_comprobante_id, punto_venta_id, concepto_afip, fecha_emision,
     *  moneda_id?, cotizacion?) es compartido.
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

        $monedaId = $input['moneda_id'] ?? 1;
        $monedaCodigo = DB::table('erp_monedas')->where('id', $monedaId)->value('codigo') ?? 'ARS';

        // Normalizar input a array de items
        $rawItems = $input['items'] ?? [[
            'descripcion' => $input['descripcion'] ?? 'Ítem',
            'cantidad' => $input['cantidad'] ?? 1,
            'precio_unit' => $input['precio_unit'] ?? 0,
            'alicuota_iva_id' => $input['alicuota_iva_id'] ?? null,
        ]];
        if (empty($rawItems)) throw new RuntimeException('Al menos un ítem es requerido');

        $esLetraC = ($tipoCbte->letra === 'C');

        // Procesar items: lookup alícuota, calcular neto/iva por línea
        $items = [];
        $alicuotasCache = [];
        foreach ($rawItems as $idx => $it) {
            $alicId = (int) ($it['alicuota_iva_id'] ?? 0);
            if (!$alicId) throw new RuntimeException("Ítem #".($idx+1).": alícuota requerida");
            $alic = $alicuotasCache[$alicId] ??= DB::table('erp_alicuotas_iva')->where('id', $alicId)->first();
            if (!$alic) throw new RuntimeException("Alícuota IVA $alicId no existe");

            $cantidad = (float) ($it['cantidad'] ?? 0);
            $precio = (float) ($it['precio_unit'] ?? 0);
            $lineNeto = round($cantidad * $precio, 2);
            $lineIva = $esLetraC ? 0 : round($lineNeto * (float) $alic->tasa, 2);

            $items[] = [
                'descripcion' => (string) ($it['descripcion'] ?? 'Ítem'),
                'cantidad' => $cantidad,
                'precio_unit' => $precio,
                'alicuota' => $alic,
                'imp_neto' => $lineNeto,
                'imp_iva' => $lineIva,
            ];
        }

        // Totales
        $imp_neto = round(array_sum(array_column($items, 'imp_neto')), 2);
        $imp_iva = round(array_sum(array_column($items, 'imp_iva')), 2);
        $imp_total = round($imp_neto + $imp_iva, 2);

        // Agrupar IVA por alícuota para el gateway
        $ivaAgrupado = [];
        foreach ($items as $it) {
            $aid = $it['alicuota']->id;
            $ivaAgrupado[$aid] ??= [
                'alicuota' => $it['alicuota'], 'base_imp' => 0, 'importe' => 0,
            ];
            $ivaAgrupado[$aid]['base_imp'] += $it['imp_neto'];
            $ivaAgrupado[$aid]['importe'] += $it['imp_iva'];
        }

        // 3. Resolver doc_tipo/doc_nro y condición IVA receptor desde cliente
        [$docTipo, $docNro] = $this->resolverDoc($cliente);
        $condicionIvaId = $this->condicionIvaPorTipo((int) $tipoCbte->id);

        // 4. Preparar emisión.
        $cfg = config('services.arca_gateway');
        if (empty($cfg['api_key']) || empty($cfg['client_id'])) {
            throw new RuntimeException('ArcaGateway no configurado (ARCA_CLIENT_ID / ARCA_API_KEY)');
        }

        $concepto = (int) $input['concepto_afip'];
        $fecha = $input['fecha_emision'];

        // Addendum v1.14: período trabajado, jurisdicción y CC derivado del cliente.
        $periodoTrab = ! empty($input['periodo_trabajado_texto']) ? trim((string) $input['periodo_trabajado_texto']) : null;
        $juris = ! empty($input['jurisdiccion_codigo']) ? strtoupper(trim((string) $input['jurisdiccion_codigo'])) : null;
        $ccId = DB::table('erp_centros_costo')->where('auxiliar_id', $cliente->id)->value('id');

        // Snapshot con todo lo necesario para persistir la factura aunque el
        // CAE llegue después por reconciliación (auditoría 2026-07-12 #2).
        $snapshot = [
            'empresa_id' => $empresaId,
            'usuario_id' => $usuarioId,
            'cliente_id' => (int) $cliente->id,
            'tipo' => ['id' => (int) $tipoCbte->id, 'codigo_interno' => $tipoCbte->codigo_interno, 'letra' => $tipoCbte->letra],
            'pv' => ['id' => (int) $pv->id, 'numero' => (int) $pv->numero],
            'fecha_emision' => $fecha,
            'concepto_afip' => $concepto,
            'doc_tipo' => $docTipo,
            'doc_nro' => (string) $docNro,
            'condicion_iva_id' => $condicionIvaId,
            'moneda_id' => $monedaId,
            'cotizacion' => $input['cotizacion'] ?? 1,
            'imp_neto' => $imp_neto,
            'imp_iva' => $imp_iva,
            'imp_total' => $imp_total,
            'periodo_trabajado_texto' => $periodoTrab,
            'jurisdiccion_codigo' => $juris,
            'centro_costo_id' => $ccId,
            'items' => array_map(fn ($it) => [
                'descripcion' => $it['descripcion'], 'cantidad' => $it['cantidad'],
                'precio_unit' => $it['precio_unit'], 'alicuota_id' => (int) $it['alicuota']->id,
                'imp_neto' => $it['imp_neto'], 'imp_iva' => $it['imp_iva'],
            ], $items),
            'iva_agrupado' => array_values(array_map(fn ($g) => [
                'alicuota_id' => (int) $g['alicuota']->id,
                'base_imp' => round($g['base_imp'], 2), 'importe' => round($g['importe'], 2),
            ], $ivaAgrupado)),
        ];

        // Identidad de contenido: distingue el reintento del mismo comprobante
        // de una emisión legítimamente nueva.
        $fingerprint = hash('sha256', json_encode([
            $empresaId, (int) $cliente->id, (int) $tipoCbte->id, (int) $pv->id,
            $fecha, $imp_total, $snapshot['items'],
        ]));

        // Antes de emitir: resolver intents previos con resultado desconocido
        // de este (tipo, pv). Si AFIP ya autorizó este mismo comprobante en un
        // intento cortado, se ADOPTA ese CAE y NO se reemite (doble CAE).
        $adoptadas = $this->reconciliarIntentsPendientes($empresaId, (int) $tipoCbte->id, (int) $pv->numero);
        if (isset($adoptadas[$fingerprint])) {
            return $adoptadas[$fingerprint];
        }

        // Registro de intención ANTES de llamar: clave estable fv-sync-{id}.
        $intentId = DB::table('erp_emisiones_sincronicas')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo_comprobante_id' => (int) $tipoCbte->id,
            'pto_vta_numero' => (int) $pv->numero,
            'idempotency_key' => 'pendiente-'.Str::random(8),
            'fingerprint' => $fingerprint,
            'snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'estado' => 'EN_VUELO',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $idem = 'fv-sync-'.$intentId;
        DB::table('erp_emisiones_sincronicas')->where('id', $intentId)->update(['idempotency_key' => $idem]);

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
            $payload['iva'] = array_values(array_map(function ($grp) {
                return [
                    'id' => (int) $grp['alicuota']->id,
                    'base_imp' => (string) round($grp['base_imp'], 2),
                    'importe' => (string) round($grp['importe'], 2),
                ];
            }, $ivaAgrupado));
        }

        try {
            $resp = $this->gateway->emitir($payload);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            // Resultado DESCONOCIDO: AFIP pudo haber autorizado. El intent
            // queda en VERIFICAR y la próxima emisión de este (tipo, pv)
            // reconcilia contra AFIP antes de reemitir.
            $this->marcarIntent($intentId, 'VERIFICAR', 'Conexión: '.$e->getMessage());
            throw new RuntimeException(
                'EMISION_RESULTADO_DESCONOCIDO: se cortó la conexión con el gateway. '
                .'NO reintentar a ciegas — el próximo intento verifica contra AFIP automáticamente.'
            );
        }

        if ($resp->serverError()) {
            $this->marcarIntent($intentId, 'VERIFICAR', 'Gateway 5xx: '.substr($resp->body(), 0, 300));
            throw new RuntimeException(
                'EMISION_RESULTADO_DESCONOCIDO: el gateway respondió '.$resp->status().'. '
                .'El próximo intento verifica contra AFIP automáticamente.'
            );
        }

        if (! $resp->successful()) {
            $body = $resp->json();
            $this->marcarIntent($intentId, 'ERROR', 'Gateway '.$resp->status().': '.json_encode($body));
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
            $this->marcarIntent($intentId, 'ERROR', 'Emisión no aprobada: '.substr($texto, 0, 400));
            throw new RuntimeException('Emisión no aprobada: '.$texto);
        }

        // 5. Persistir + contabilizar (extraído para reuso por la reconciliación).
        $out = $this->persistirFactura($snapshot, (int) $data['cbte_desde'], (string) $data['cae'], $data['cae_vto'] ?? null);
        $out['observaciones'] = $data['observaciones'] ?? [];
        $out['fecha_proceso'] = $data['fecha_proceso'] ?? null;

        $this->marcarIntent($intentId, 'OK', null, $out['factura_id'], (string) $data['cae']);

        return $out;
    }

    /**
     * Auditoría 2026-07-12 #2 — resuelve intents de emisión con resultado
     * desconocido para un (empresa, tipo, pv): consulta a AFIP el último
     * comprobante autorizado y, si coincide con el snapshot del intent,
     * ADOPTA ese CAE (persiste la factura sin reemitir). Si no coincide,
     * marca DESCARTADA (probado que no se emitió: es seguro reemitir).
     *
     * Si el gateway no responde, LANZA: nunca se debe emitir con intents
     * dudosos sin resolver (ahí vive el doble CAE).
     *
     * @return array<string, array> facturas adoptadas indexadas por fingerprint
     */
    public function reconciliarIntentsPendientes(int $empresaId, int $tipoCbteId, int $pvNumero): array
    {
        $pendientes = DB::table('erp_emisiones_sincronicas')
            ->where('empresa_id', $empresaId)
            ->where('tipo_comprobante_id', $tipoCbteId)
            ->where('pto_vta_numero', $pvNumero)
            ->where('estado', 'VERIFICAR')
            ->orderByDesc('id')
            ->get();

        if ($pendientes->isEmpty()) {
            return [];
        }

        try {
            $ultResp = $this->gateway->ultimoAutorizado($tipoCbteId, $pvNumero);
            if (! $ultResp->successful()) {
                throw new RuntimeException('ultimo-autorizado devolvió '.$ultResp->status());
            }
            $ultimoNro = (int) ($ultResp->json('cbte_nro') ?? 0);

            $consulta = null;
            if ($ultimoNro > 0) {
                $consResp = $this->gateway->consultar($tipoCbteId, $pvNumero, $ultimoNro);
                $consulta = $consResp->successful() ? $consResp->json() : null;
            }
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'EMISION_BLOQUEADA: hay '.$pendientes->count().' intento(s) previo(s) con resultado desconocido '
                .'y no se pudo verificar contra AFIP ('.$e->getMessage().'). Reintentar cuando el gateway responda.'
            );
        }

        $adoptadas = [];
        $ultimoAdoptado = false;

        foreach ($pendientes as $intent) {
            $snap = json_decode($intent->snapshot, true);
            $coincide = ! $ultimoAdoptado
                && $consulta !== null
                && abs(((float) ($consulta['imp_total'] ?? -1)) - (float) $snap['imp_total']) <= 0.01
                && (($consulta['fecha_cbte'] ?? null) === $snap['fecha_emision']);

            if ($coincide) {
                // AFIP autorizó este comprobante en el intento cortado: adoptar.
                $out = $this->persistirFactura(
                    $snap, $ultimoNro,
                    (string) ($consulta['cae'] ?? ''), $consulta['cae_vto'] ?? null,
                );
                $this->marcarIntent($intent->id, 'OK', 'CAE adoptado por reconciliación', $out['factura_id'], (string) ($consulta['cae'] ?? ''));
                \Illuminate\Support\Facades\Log::warning('emision-cae.huerfano-adoptado', [
                    'intent_id' => $intent->id, 'factura_id' => $out['factura_id'],
                    'cae' => $out['cae'], 'nro' => $ultimoNro,
                    'tipo' => $tipoCbteId, 'pv' => $pvNumero,
                ]);
                $adoptadas[$intent->fingerprint] = $out;
                $ultimoAdoptado = true;
            } else {
                $this->marcarIntent($intent->id, 'DESCARTADA', 'Verificado contra AFIP: el último autorizado no coincide — no se emitió.');
                \Illuminate\Support\Facades\Log::info('emision-cae.intent-descartado', [
                    'intent_id' => $intent->id, 'tipo' => $tipoCbteId, 'pv' => $pvNumero,
                ]);
            }
        }

        return $adoptadas;
    }

    /** Persiste factura + items + IVA + contabilización best-effort. */
    private function persistirFactura(array $snap, int $numero, string $cae, ?string $caeVto): array
    {
        $facturaId = DB::transaction(function () use ($snap, $numero, $cae, $caeVto) {
            $facturaId = DB::table('erp_facturas_venta')->insertGetId([
                'empresa_id' => $snap['empresa_id'],
                'tipo_comprobante_id' => $snap['tipo']['id'],
                'punto_venta_id' => $snap['pv']['id'],
                'numero' => $numero,
                'cae' => $cae,
                'fecha_vto_cae' => $caeVto,
                'fecha_emision' => $snap['fecha_emision'],
                'auxiliar_id' => $snap['cliente_id'],
                'condicion_iva_id' => $snap['condicion_iva_id'],
                'doc_tipo_afip' => $snap['doc_tipo'],
                'doc_nro' => (string) $snap['doc_nro'],
                'moneda_id' => $snap['moneda_id'],
                'cotizacion' => $snap['cotizacion'],
                'concepto_afip' => $snap['concepto_afip'],
                'imp_neto_gravado' => $snap['imp_neto'],
                'imp_no_gravado' => 0,
                'imp_exento' => 0,
                'imp_iva' => $snap['imp_iva'],
                'imp_tributos' => 0,
                'imp_total' => $snap['imp_total'],
                'origen' => 'WSFE_ERP',
                'estado' => 'EMITIDA',
                'periodo_trabajado_texto' => $snap['periodo_trabajado_texto'],
                'jurisdiccion_codigo' => $snap['jurisdiccion_codigo'],
                'centro_costo_id' => $snap['centro_costo_id'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($snap['items'] as $idx => $it) {
                DB::table('erp_factura_venta_items')->insert([
                    'factura_id' => $facturaId,
                    'nro_linea' => $idx + 1,
                    'concepto' => $it['descripcion'],
                    'cantidad' => $it['cantidad'],
                    'precio_unitario' => $it['precio_unit'],
                    'alicuota_iva_id' => $it['alicuota_id'],
                    'imp_neto' => $it['imp_neto'],
                    'imp_iva' => $it['imp_iva'],
                ]);
            }

            foreach ($snap['iva_agrupado'] as $grp) {
                if ($grp['importe'] <= 0) continue;
                DB::table('erp_factura_venta_iva')->insert([
                    'factura_id' => $facturaId,
                    'alicuota_iva_id' => $grp['alicuota_id'],
                    'base_imponible' => $grp['base_imp'],
                    'importe_iva' => $grp['importe'],
                ]);
            }

            return $facturaId;
        });

        // Contabilizar (puede fallar por período cerrado — no bloquea)
        try {
            $this->contabilizador->contabilizarPendientes($snap['empresa_id'], $snap['usuario_id']);
        } catch (\Throwable $e) {
            // La factura ya tiene CAE; si falla la contabilización, se ve después.
        }

        return [
            'factura_id' => $facturaId,
            'cae' => $cae,
            'cae_vto' => $caeVto,
            'numero' => $numero,
            'pto_vta' => $snap['pv']['numero'],
            'tipo_codigo' => $snap['tipo']['codigo_interno'],
            'letra' => $snap['tipo']['letra'],
            'imp_neto' => $snap['imp_neto'],
            'imp_iva' => $snap['imp_iva'],
            'imp_total' => $snap['imp_total'],
            'observaciones' => [],
            'fecha_proceso' => null,
        ];
    }

    private function marcarIntent(int $id, string $estado, ?string $nota, ?int $facturaId = null, ?string $cae = null): void
    {
        DB::table('erp_emisiones_sincronicas')->where('id', $id)->update(array_filter([
            'estado' => $estado,
            'ultimo_error' => $nota,
            'factura_venta_id' => $facturaId,
            'cae' => $cae,
            'updated_at' => now(),
        ], fn ($v) => $v !== null));
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

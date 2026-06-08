<?php

namespace App\Erp\Services;

use App\Erp\Models\VentasCompras\ComprobanteConstatacion;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Constatación de CAE recibidos de proveedores (SPEC 03 RN-42).
 *
 * El ERP llama al WS COMP_CONSULT de AFIP via arca-gateway. Guarda el
 * resultado en erp_comprobante_constatacion y actualiza
 * erp_facturas_compra.constatacion_estado.
 *
 * RN-42 enforcement: al registrar manualmente una FacturaCompra con CAE, el
 * controller puede invocar constatarFactura() y rechazar si AFIP dice
 * INVALIDO/NO_ENCONTRADO (salvo override con permiso
 * compras.facturas.registrar_sin_constatar). Para facturas que vienen por
 * Mis Comprobantes, la constatación es implícita (AFIP las reconoce como
 * suyas).
 */
class ConstatacionService
{
    public const RESULTADO_VALIDO = 'VALIDO';
    public const RESULTADO_INVALIDO = 'INVALIDO';
    public const RESULTADO_NO_ENCONTRADO = 'NO_ENCONTRADO';

    public function __construct(
        private readonly ArcaGatewayClient $gateway,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Constata un comprobante sin pasar por una factura persistida.
     * Útil para el endpoint público /api/erp/comprobantes/constatar.
     *
     * @return array{resultado:string, datos_afip:?array, raw:?array}
     */
    public function constatar(array $payload): array
    {
        foreach (['tipo', 'pto_vta', 'numero', 'cuit_emisor', 'cae'] as $k) {
            if (empty($payload[$k])) {
                throw new DomainException("CAMPO_REQUERIDO: {$k}");
            }
        }

        $cuit = preg_replace('/[^0-9]/', '', (string) $payload['cuit_emisor']);

        $response = $this->gateway->constatar([
            'tipo_cbte' => (int) $payload['tipo'],
            'pto_vta' => (int) $payload['pto_vta'],
            'cbte_nro' => (int) $payload['numero'],
            'cuit_emisor' => $cuit,
            'cae' => (string) $payload['cae'],
            'fecha_cbte' => $payload['fecha_cbte'] ?? null,
            'imp_total' => $payload['imp_total'] ?? null,
            // El receptor lo pasa el caller (PDF, factura, etc.). Si no viene,
            // el gateway defaultea al CUIT representado — válido sólo para
            // facturas emitidas por la propia empresa.
            'cuit_receptor' => isset($payload['cuit_receptor']) && $payload['cuit_receptor'] !== ''
                ? preg_replace('/[^0-9]/', '', (string) $payload['cuit_receptor'])
                : null,
            'doc_tipo_receptor' => isset($payload['doc_tipo_receptor']) ? (int) $payload['doc_tipo_receptor'] : null,
        ]);

        if (! $response->ok()) {
            throw new DomainException(sprintf(
                'CONSTATACION_GATEWAY_ERROR: status %d · %s',
                $response->status(),
                substr((string) $response->body(), 0, 200)
            ));
        }

        $data = $response->json();
        // Gateway devuelve 'A' (aprobado) / 'R' (rechazado). Mapeamos al enum
        // legacy del service para no romper consumidores.
        $resGateway = mb_strtoupper((string) ($data['resultado'] ?? ''));
        $resultado = match ($resGateway) {
            'A' => self::RESULTADO_VALIDO,
            'R' => self::RESULTADO_INVALIDO,
            'VALIDO', 'INVALIDO', 'NO_ENCONTRADO' => $resGateway, // ya viene mapeado
            default => self::RESULTADO_NO_ENCONTRADO,
        };

        return [
            'resultado' => $resultado,
            'datos_afip' => $data['datos_afip'] ?? null,
            'raw' => $data,
        ];
    }

    /**
     * Constata el CAE de una FacturaCompra ya persistida y actualiza
     * erp_comprobante_constatacion + factura.constatacion_estado.
     *
     * @param  bool  $bloqueanteSiInvalido  si true, lanza DomainException
     *   cuando AFIP devuelve INVALIDO/NO_ENCONTRADO (gate RN-42).
     */
    public function constatarFactura(FacturaCompra $factura, bool $bloqueanteSiInvalido = true): FacturaCompra
    {
        if (! $factura->cae) {
            throw new DomainException('FACTURA_SIN_CAE: no corresponde constatar');
        }

        $tipoCbte = (int) DB::table('erp_tipos_comprobante')
            ->where('id', $factura->tipo_comprobante_id)
            ->value('codigo_afip');

        $res = $this->constatar([
            'tipo' => $tipoCbte,
            'pto_vta' => $factura->punto_venta,
            'numero' => $factura->numero,
            'cuit_emisor' => $factura->cuit_emisor,
            'cae' => $factura->cae,
            'fecha_cbte' => $factura->fecha_emision instanceof \DateTime
                ? $factura->fecha_emision->format('Y-m-d')
                : (string) $factura->fecha_emision,
            'imp_total' => (string) $factura->imp_total,
        ]);

        DB::transaction(function () use ($factura, $res) {
            ComprobanteConstatacion::updateOrCreate(
                ['factura_compra_id' => $factura->id],
                [
                    'resultado' => $res['resultado'],
                    'fecha_consulta' => now(),
                    'datos_afip' => $res['datos_afip'],
                ]
            );
            $factura->update(['constatacion_estado' => $res['resultado']]);
        });

        $this->audit->logEvento(
            accion: 'CAE_CONSTATADO',
            modulo: 'arca',
            descripcion: sprintf(
                'Constatación CAE %s · Factura compra #%d · proveedor %s · resultado=%s',
                $factura->cae, $factura->id, $factura->cuit_emisor, $res['resultado']
            ),
            empresaId: $factura->empresa_id,
        );

        if ($bloqueanteSiInvalido && $res['resultado'] !== self::RESULTADO_VALIDO) {
            throw new DomainException(sprintf(
                'RN-42 CONSTATACION_FALLIDA: AFIP devolvió %s para CAE %s',
                $res['resultado'],
                $factura->cae
            ));
        }

        return $factura->fresh();
    }
}

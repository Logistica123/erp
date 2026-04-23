<?php

namespace App\Erp\Services;

use App\Erp\Models\Arca\PadronCache;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Consulta y cache del padrón AFIP (SPEC 03 RN-40, RN-41).
 *
 * Flujo:
 *  · consultar(cuit): devuelve cache si está vigente (TTL 30 días), si no
 *    llama al gateway (WS_A5/A13) y refresca.
 *  · refrescar(cuit): fuerza refresh sin importar TTL.
 *  · RN-40 enforcement al dar de alta cliente FCE se hace desde el endpoint
 *    de auxiliares (bloqueante); acá solo exponemos la consulta.
 */
class PadronService
{
    public const TTL_DIAS_DEFAULT = 30;

    public function __construct(
        private readonly ArcaGatewayClient $gateway,
        private readonly AuditLogger $audit,
    ) {}

    public function consultar(string $cuit, int $empresaId = 1): PadronCache
    {
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        if (strlen($cuit) !== 11) {
            throw new DomainException('CUIT_INVALIDO: debe tener 11 dígitos');
        }

        $cache = PadronCache::where('cuit', $cuit)->first();
        if ($cache && $this->esVigente($cache)) {
            return $cache;
        }

        return $this->refrescar($cuit, $empresaId);
    }

    public function refrescar(string $cuit, int $empresaId = 1): PadronCache
    {
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        if (strlen($cuit) !== 11) {
            throw new DomainException('CUIT_INVALIDO');
        }

        $response = $this->gateway->padron($cuit);
        if (! $response->ok()) {
            throw new DomainException(sprintf(
                'PADRON_GATEWAY_ERROR: status %d · %s',
                $response->status(),
                substr((string) $response->body(), 0, 200)
            ));
        }

        $data = $response->json();

        $cache = PadronCache::updateOrCreate(
            ['cuit' => $cuit],
            [
                'razon_social' => $data['razon_social'] ?? null,
                'nombre' => $data['nombre'] ?? null,
                'apellido' => $data['apellido'] ?? null,
                'tipo_persona' => $data['tipo_persona'] ?? null,
                'estado_cuit' => $data['estado_cuit'] ?? null,
                'condicion_iva_afip' => $data['condicion_iva_afip'] ?? null,
                'condicion_iva_id' => $this->resolverCondicionIvaId($data['condicion_iva_afip'] ?? null),
                'domicilio_fiscal' => $data['domicilio_fiscal'] ?? null,
                'actividades' => $data['actividades'] ?? null,
                'datos_afip_raw' => $data,
                'consultado_at' => now(),
                'ttl_dias' => self::TTL_DIAS_DEFAULT,
            ]
        );

        $this->audit->logEvento(
            accion: 'PADRON_CONSULTADO',
            modulo: 'arca',
            descripcion: sprintf(
                'CUIT %s · %s · %s · estado %s',
                $cuit,
                $cache->razon_social ?? '?',
                $cache->condicion_iva_afip ?? '?',
                $cache->estado_cuit ?? '?'
            ),
            empresaId: $empresaId,
        );

        return $cache->fresh();
    }

    /**
     * RN-40: valida que un CUIT sea apto para ser cliente FCE.
     * Rechaza si el CUIT está INACTIVO o la condición IVA no es compatible.
     */
    public function validarParaFce(string $cuit, int $empresaId = 1): PadronCache
    {
        $cache = $this->consultar($cuit, $empresaId);

        if ($cache->estado_cuit && $cache->estado_cuit !== 'ACTIVO') {
            throw new DomainException(sprintf(
                'RN-40 CUIT_INACTIVO: %s está %s en AFIP',
                $cuit,
                $cache->estado_cuit
            ));
        }

        // FCE aplica a RI y Monotributo. Consumidor Final / Exento no son destinatarios válidos.
        $condicionAfip = mb_strtoupper((string) $cache->condicion_iva_afip);
        $aptos = ['RESPONSABLE INSCRIPTO', 'IVA RESPONSABLE INSCRIPTO', 'MONOTRIBUTO', 'MONOTRIBUTISTA SOCIAL'];
        if (! collect($aptos)->some(fn ($apto) => str_contains($condicionAfip, $apto))) {
            throw new DomainException(sprintf(
                'RN-40 CONDICION_IVA_NO_APTA: %s es "%s" — FCE requiere RI o MT',
                $cuit,
                $cache->condicion_iva_afip ?? '?'
            ));
        }

        return $cache;
    }

    private function esVigente(PadronCache $cache): bool
    {
        if (! $cache->consultado_at) {
            return false;
        }

        return Carbon::parse($cache->consultado_at)
            ->addDays($cache->ttl_dias ?? self::TTL_DIAS_DEFAULT)
            ->isFuture();
    }

    private function resolverCondicionIvaId(?string $condicionAfip): ?int
    {
        if (! $condicionAfip) {
            return null;
        }

        $upper = mb_strtoupper($condicionAfip);
        $codigo = match (true) {
            str_contains($upper, 'RESPONSABLE INSCRIPTO') => 'RI',
            str_contains($upper, 'MONOTRIBUTO') => 'MT',
            str_contains($upper, 'EXENTO') => 'EX',
            str_contains($upper, 'CONSUMIDOR') => 'CF',
            default => null,
        };

        if (! $codigo) {
            return null;
        }

        return (int) DB::table('erp_condiciones_iva')->where('codigo_interno', $codigo)->value('id') ?: null;
    }
}

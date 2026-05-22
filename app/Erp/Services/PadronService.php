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

    /**
     * v1.28 — Consulta APOC en bulk para el wizard del Libro IVA Compras.
     * Devuelve los estados de todos los CUITs únicos pasados, agrupados por
     * estado. La consulta es secuencial pero apoyada en el cache de 30 días
     * (PadronCache), así que en la práctica solo los CUITs nuevos hacen
     * llamada efectiva al gateway.
     *
     * Si el WS APOC falla para un CUIT (gateway down), el cuit cae en
     * `errores` y se reporta al operador. NO bloquea el batch.
     *
     * @param  list<string>  $cuits
     * @return array{
     *   total: int,
     *   consultados_at: string,
     *   activos: list<string>,
     *   inactivos: list<array{cuit:string,estado:string,razon_social:?string}>,
     *   errores: list<array{cuit:string,motivo:string}>,
     *   ws_caido: bool,
     * }
     */
    public function consultarBulk(array $cuits, int $empresaId = 1): array
    {
        $activos = [];
        $inactivos = [];
        $errores = [];
        $procesados = [];
        $wsCaido = false;
        $erroresGatewayConsecutivos = 0;

        foreach ($cuits as $cuit) {
            $norm = preg_replace('/[^0-9]/', '', (string) $cuit);
            if (strlen($norm) !== 11) {
                $errores[] = ['cuit' => $cuit, 'motivo' => 'CUIT_INVALIDO'];
                continue;
            }
            if (isset($procesados[$norm])) continue;
            $procesados[$norm] = true;

            try {
                $cache = $this->consultar($norm, $empresaId);
                $erroresGatewayConsecutivos = 0; // resetear al éxito.
                $estado = (string) ($cache->estado_cuit ?? '');
                if ($estado === 'ACTIVO') {
                    $activos[] = $norm;
                } else {
                    $inactivos[] = [
                        'cuit' => $norm,
                        'estado' => $estado ?: 'DESCONOCIDO',
                        'razon_social' => $cache->razon_social ?? null,
                    ];
                }
            } catch (DomainException $e) {
                $msg = $e->getMessage();
                $errores[] = ['cuit' => $norm, 'motivo' => $msg];
                if (str_contains($msg, 'PADRON_GATEWAY_ERROR')) {
                    $erroresGatewayConsecutivos++;
                    if ($erroresGatewayConsecutivos >= 3) {
                        // D-28-9: si el WS está caído (3 errores seguidos),
                        // dejamos de intentar y reportamos.
                        $wsCaido = true;
                        break;
                    }
                }
            }
        }

        return [
            'total' => count($procesados),
            'consultados_at' => now()->toIso8601String(),
            'activos' => $activos,
            'inactivos' => $inactivos,
            'errores' => $errores,
            'ws_caido' => $wsCaido,
        ];
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

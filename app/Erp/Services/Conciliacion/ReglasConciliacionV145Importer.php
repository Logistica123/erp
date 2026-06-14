<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v1.45 — Importer del seeder de reglas de conciliación.
 *
 * 4 pasos (idempotentes):
 *  1. Asigna cuenta + modo a las 37 reglas existentes.
 *  2. Desactiva la "regla #6" mezclada (^Rendimientos|^Pago de servicio Aguas).
 *  3. Crea 31 reglas nuevas (incluye RENDIMIENTOS_GEN + SERV-AGUA del fix).
 *  4. Crea 2 reglas con extractor CUIT (matching auto).
 *
 * Adaptaciones a la realidad del ERP:
 *  - Tabla real: erp_conciliacion_reglas (no erp_reglas_conciliacion).
 *  - patron_concepto se guarda SIN delimitadores (el matcher hace /.../iu).
 *  - signo del JSON (+/-) → CREDITO/DEBITO; banco_id se deja NULL (convención
 *    de las reglas existentes: filtran por regex, no por banco).
 *  - 'INTERESES GANADOS' (JSON, espacio) → 'INTERESES_GANADOS' (real).
 */
class ReglasConciliacionV145Importer
{
    private const EMPRESA_ID = 1;
    private const TIPO_REGEX = 'CONCEPTO_REGEX';

    /** Auxiliares fijos para 2 reglas (lookup por prefijo, opcional). */
    private const AUX_FIJO = [
        'ICBC-PAGO-TARJ' => 'TC-ICBC',
        'ICBC-PAGO-PRESTAMO' => 'PRESTAMO-ICBC%',
        'BR-PAGO-TARJ' => 'TC-ICBC', // tarjeta corporativa también ICBC
    ];

    private array $stats = [
        'existentes_actualizadas' => 0,
        'existentes_no_encontradas' => [],
        'desactivadas' => 0,
        'nuevas_creadas' => 0,
        'nuevas_actualizadas' => 0,
        'extractor_creadas' => 0,
        'extractor_actualizadas' => 0,
        'cuentas_no_encontradas' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function run(string $jsonPath, int $userId, bool $dryRun = false): array
    {
        if (! file_exists($jsonPath)) {
            throw new RuntimeException("JSON no encontrado: {$jsonPath}");
        }
        $data = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

        try {
            DB::beginTransaction();
            DB::statement('SET @erp_current_user_id = ?', [$userId]);

            $this->actualizarExistentes($data['reglas_existentes_asignar_cuenta'] ?? []);
            $this->desactivarReglaSeis($data['reglas_a_desactivar'] ?? []);
            $this->crearReglasNuevas($data['reglas_nuevas'] ?? []);
            $this->crearReglasExtractorCuit($data['reglas_extractor_cuit'] ?? []);
            $this->validar();

            if ($dryRun) {
                DB::rollBack();
                return ['ok' => true, 'dry_run' => true, 'stats' => $this->stats];
            }

            DB::commit();
            $this->audit->logEvento(
                accion: 'SEED_REGLAS_CONCILIACION_V145',
                modulo: 'tesoreria',
                descripcion: sprintf(
                    'v1.45 reglas: %d existentes act, %d desactivadas, %d nuevas, %d extractor CUIT',
                    $this->stats['existentes_actualizadas'], $this->stats['desactivadas'],
                    $this->stats['nuevas_creadas'] + $this->stats['nuevas_actualizadas'],
                    $this->stats['extractor_creadas'] + $this->stats['extractor_actualizadas'],
                ),
                empresaId: self::EMPRESA_ID,
            );
            return ['ok' => true, 'dry_run' => false, 'stats' => $this->stats];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function actualizarExistentes(array $rows): void
    {
        foreach ($rows as $r) {
            $codigo = $this->normalizarCodigo($r['codigo']);
            $cuentaId = $this->cuentaId($r['cuenta_codigo'] ?? null);
            $afectadas = DB::table('erp_conciliacion_reglas')
                ->where('empresa_id', self::EMPRESA_ID)
                ->where('codigo', $codigo)
                ->update([
                    'cuenta_contable_id' => $cuentaId,
                    'cuenta_contable_modo' => $r['modo'] ?? 'FIJO',
                    'updated_at' => now(),
                ]);
            if ($afectadas > 0) {
                $this->stats['existentes_actualizadas']++;
            } else {
                $this->stats['existentes_no_encontradas'][] = $codigo;
            }
        }
    }

    private function desactivarReglaSeis(array $rows): void
    {
        foreach ($rows as $r) {
            // Buscar la regla mezclada por su patrón (contiene "Pago de servicio Aguas").
            $q = DB::table('erp_conciliacion_reglas')
                ->where('empresa_id', self::EMPRESA_ID)
                ->where('activa', 1)
                ->where(function ($w) {
                    $w->where('patron_concepto', 'like', '%Pago de servicio Aguas%')
                      ->orWhere('patron_concepto', 'like', '%^Rendimientos|^Pago%');
                });
            $regla = $q->first();
            if (! $regla) continue;
            DB::table('erp_conciliacion_reglas')->where('id', $regla->id)->update([
                'activa' => 0,
                'observacion' => trim(($regla->observacion ?? '') . ' [v1.45] Reemplazada por RENDIMIENTOS_GEN + SERV-AGUA'),
                'updated_at' => now(),
            ]);
            $this->stats['desactivadas']++;
        }
    }

    private function crearReglasNuevas(array $rows): void
    {
        foreach ($rows as $r) {
            $codigo = $this->normalizarCodigo($r['codigo']);
            $payload = [
                'empresa_id' => self::EMPRESA_ID,
                'descripcion' => $r['descripcion'] ?? $codigo,
                'tipo' => self::TIPO_REGEX,
                'patron_concepto' => $this->stripDelim($r['patron_concepto_regex'] ?? ''),
                'cuenta_contable_id' => $this->cuentaId($r['cuenta_codigo'] ?? null),
                'cuenta_contable_modo' => $r['modo'] ?? 'FIJO',
                'auxiliar_id' => $this->auxiliarFijoId($codigo),
                'banco_id' => null, // convención existente: filtra por regex, no banco.
                'signo' => $this->mapSigno($r['signo'] ?? null),
                'orden_prioridad' => (int) ($r['prioridad'] ?? 100),
                'confianza' => (int) ($r['confianza'] ?? 90),
                'activa' => (bool) ($r['activa'] ?? true),
                'matching_auto_factura' => false,
                'updated_at' => now(),
            ];
            $this->upsertRegla($codigo, $payload, 'nuevas');
        }
    }

    private function crearReglasExtractorCuit(array $rows): void
    {
        foreach ($rows as $r) {
            $codigo = $this->normalizarCodigo($r['codigo']);
            $patron = $this->stripDelim($r['patron_concepto_regex'] ?? '');
            $payload = [
                'empresa_id' => self::EMPRESA_ID,
                'descripcion' => $r['descripcion'] ?? $codigo,
                'tipo' => self::TIPO_REGEX,
                'patron_concepto' => $patron,
                'cuenta_contable_id' => $this->cuentaId($r['cuenta_codigo'] ?? null),
                'cuenta_contable_modo' => $r['modo'] ?? 'DINAMICO_POR_AUXILIAR',
                'cuit_extractor_regex' => $patron, // mismo patrón con grupo de captura.
                'matching_auto_factura' => (bool) ($r['matching_auto_factura'] ?? true),
                'tipo_auxiliar' => $r['tipo_auxiliar'] ?? null,
                'banco_id' => null,
                'signo' => $this->mapSigno($r['signo'] ?? null),
                'orden_prioridad' => (int) ($r['prioridad'] ?? 3),
                'confianza' => (int) ($r['confianza_base'] ?? 80),
                'activa' => (bool) ($r['activa'] ?? true),
                'updated_at' => now(),
            ];
            $this->upsertRegla($codigo, $payload, 'extractor');
        }
    }

    private function upsertRegla(string $codigo, array $payload, string $bucket): void
    {
        $existe = DB::table('erp_conciliacion_reglas')
            ->where('empresa_id', self::EMPRESA_ID)->where('codigo', $codigo)->first();
        if ($existe) {
            DB::table('erp_conciliacion_reglas')->where('id', $existe->id)->update($payload);
            $this->stats["{$bucket}_actualizadas"]++;
        } else {
            $payload['codigo'] = $codigo;
            $payload['created_at'] = now();
            DB::table('erp_conciliacion_reglas')->insert($payload);
            $this->stats["{$bucket}_creadas"]++;
        }
    }

    private function validar(): void
    {
        // Las FIJO deben tener cuenta (salvo las que el addendum deja en NULL a
        // propósito: BR-/MP- transferencias externas y servicios sin clasificar).
        $sinCuentaPermitido = [
            'BR-TRANSF-EXT-ENT', 'BR-TRANSF-EXT-SAL', 'BR-PAGO-SERV',
            'MP-TRANSF-RECIB', 'MP-PAGO-SERV',
        ];
        $rotas = DB::table('erp_conciliacion_reglas')
            ->where('empresa_id', self::EMPRESA_ID)
            ->where('activa', 1)
            ->where('cuenta_contable_modo', 'FIJO')
            ->whereNull('cuenta_contable_id')
            ->whereNotIn('codigo', $sinCuentaPermitido)
            ->pluck('codigo')->all();
        // Solo informativo — no abortar (puede haber reglas viejas sin cuenta).
        $this->stats['fijo_sin_cuenta_inesperadas'] = $rotas;
    }

    // -- helpers --

    private function normalizarCodigo(string $c): string
    {
        // 'INTERESES GANADOS' (JSON) → 'INTERESES_GANADOS' (real).
        return trim(str_replace(' ', '_', trim($c)));
    }

    private function cuentaId(?string $codigo): ?int
    {
        if (! $codigo) return null;
        $id = DB::table('erp_cuentas_contables')
            ->where('empresa_id', self::EMPRESA_ID)->where('codigo', $codigo)->value('id');
        if (! $id) $this->stats['cuentas_no_encontradas'][] = $codigo;
        return $id ? (int) $id : null;
    }

    private function auxiliarFijoId(string $codigo): ?int
    {
        $patron = self::AUX_FIJO[$codigo] ?? null;
        if (! $patron) return null;
        $q = DB::table('erp_auxiliares')->where('empresa_id', self::EMPRESA_ID);
        str_contains($patron, '%')
            ? $q->where('codigo', 'like', $patron)
            : $q->where('codigo', $patron);
        $id = $q->value('id');
        return $id ? (int) $id : null;
    }

    private function mapSigno(?string $s): string
    {
        return match (trim((string) $s)) {
            '+' => 'CREDITO',
            '-' => 'DEBITO',
            default => 'AMBOS',
        };
    }

    /** Quita delimitadores /.../ y flags finales — el matcher agrega /.../iu. */
    private function stripDelim(string $regex): string
    {
        $s = trim($regex);
        if (preg_match('~^/(.*)/[a-z]*$~is', $s, $m)) {
            return $m[1];
        }
        return $s;
    }
}

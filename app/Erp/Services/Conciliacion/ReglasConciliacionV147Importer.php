<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Support\AuditLogger;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v1.47 — Seeder de cuentas/auxiliares/reglas de conciliación.
 *
 *  1. Crea 3 cuentas contables nuevas (2.1.3.99, 1.1.6.99, 4.4.01).
 *  2. Crea 5 auxiliares nuevos (normaliza tipo: OTRO→Organismo, etc.).
 *  3. Ajusta el regex de 7 reglas existentes.
 *  4. Inserta 42 reglas nuevas (ICBC 25 + Brubank 6 + MP 11).
 *
 * Convenciones (idénticas a v1.45):
 *  - tabla real erp_conciliacion_reglas; patron_concepto SIN delimitadores.
 *  - signo +/- → CREDITO/DEBITO; ± → AMBOS; banco_id NULL.
 *  - reglas DINAMICO_POR_AUXILIAR → matching_auto_factura=TRUE + tipo_auxiliar
 *    por signo (CLIENTE entrada / PROVEEDOR salida) para el matching por nombre.
 */
class ReglasConciliacionV147Importer
{
    private const EMPRESA_ID = 1;
    private const TIPO_REGEX = 'CONCEPTO_REGEX';

    /** Mapeo tipo auxiliar del JSON (mayúsculas) → enum real. */
    private const TIPO_AUX = [
        'CLIENTE' => 'Cliente', 'PROVEEDOR' => 'Proveedor', 'DISTRIBUIDOR' => 'Distribuidor',
        'EMPLEADO' => 'Empleado', 'SOCIO' => 'Socio', 'OTRO' => 'Organismo',
    ];

    private array $stats = [
        'cuentas_creadas' => 0, 'cuentas_existentes' => 0,
        'auxiliares_creados' => 0, 'auxiliares_existentes' => 0,
        'regex_ajustadas' => 0, 'regex_no_encontradas' => [],
        'reglas_creadas' => 0, 'reglas_actualizadas' => 0,
        'cuentas_no_encontradas' => [], 'auxiliares_no_encontrados' => [],
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function run(string $jsonPath, int $userId, bool $dryRun = false): array
    {
        if (! file_exists($jsonPath)) throw new RuntimeException("JSON no encontrado: {$jsonPath}");
        $data = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

        try {
            DB::beginTransaction();
            DB::statement('SET @erp_current_user_id = ?', [$userId]);

            $this->crearCuentas($data['schema_delta']['cuentas_contables_nuevas'] ?? []);
            $this->crearAuxiliares($data['schema_delta']['auxiliares_nuevos'] ?? []);
            $this->ajustarRegex($data['reglas_existentes_ajustar_regex'] ?? []);
            foreach (['reglas_nuevas_icbc', 'reglas_nuevas_brubank', 'reglas_nuevas_mercadopago'] as $bloque) {
                $this->crearReglas($data[$bloque] ?? []);
            }

            if ($dryRun) { DB::rollBack(); return ['ok' => true, 'dry_run' => true, 'stats' => $this->stats]; }

            DB::commit();
            $this->audit->logEvento(
                accion: 'SEED_REGLAS_CONCILIACION_V147', modulo: 'tesoreria',
                descripcion: sprintf('v1.47: %d cuentas, %d auxiliares, %d regex ajustadas, %d reglas nuevas',
                    $this->stats['cuentas_creadas'], $this->stats['auxiliares_creados'],
                    $this->stats['regex_ajustadas'], $this->stats['reglas_creadas'] + $this->stats['reglas_actualizadas']),
                empresaId: self::EMPRESA_ID,
            );
            return ['ok' => true, 'dry_run' => false, 'stats' => $this->stats];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    private function crearCuentas(array $rows): void
    {
        foreach ($rows as $c) {
            if (DB::table('erp_cuentas_contables')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $c['codigo'])->exists()) {
                $this->stats['cuentas_existentes']++;
                continue;
            }
            $padreCodigo = substr($c['codigo'], 0, strrpos($c['codigo'], '.'));
            $padreId = DB::table('erp_cuentas_contables')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $padreCodigo)->value('id');
            // enum real tipo: A/P/PN/RP/RN/CO. El JSON usa 'I' (ingreso) → RP.
            $tipo = match (strtoupper($c['tipo'])) {
                'A' => 'A', 'P' => 'P', 'PN' => 'PN',
                'I', 'R+', 'RP' => 'RP', 'R-', 'RN' => 'RN', 'CO' => 'CO',
                default => 'A',
            };
            DB::table('erp_cuentas_contables')->insert([
                'empresa_id' => self::EMPRESA_ID,
                'codigo' => $c['codigo'],
                'codigo_padre_id' => $padreId,
                'nivel' => $c['nivel'] ?? (substr_count($c['codigo'], '.') + 1),
                'nombre' => $c['nombre'],
                'tipo' => $tipo,
                'rubro_ec' => $c['rubro_ec'] ?? null,
                'imputable' => ($c['imputable'] ?? true) ? 1 : 0,
                'moneda' => $c['moneda'] ?? 'ARS',
                'admite_cc' => 0, 'admite_auxiliar' => 0,
                'etiqueta_cierre' => $c['etiqueta_cierre'] ?? null,
                'saldo_normal' => $tipo === 'A' ? 'DEUDOR' : 'ACREEDOR',
                'regularizadora' => 0, 'activo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->stats['cuentas_creadas']++;
        }
    }

    private function crearAuxiliares(array $rows): void
    {
        foreach ($rows as $a) {
            if (DB::table('erp_auxiliares')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $a['codigo'])->exists()) {
                $this->stats['auxiliares_existentes']++;
                continue;
            }
            DB::table('erp_auxiliares')->insert([
                'empresa_id' => self::EMPRESA_ID,
                'tipo' => self::TIPO_AUX[strtoupper($a['tipo'] ?? 'OTRO')] ?? 'Organismo',
                'codigo' => $a['codigo'],
                'nombre' => $a['razon_social'] ?? $a['codigo'],
                'activo' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->stats['auxiliares_creados']++;
        }
    }

    private function ajustarRegex(array $rows): void
    {
        foreach ($rows as $r) {
            $afectadas = DB::table('erp_conciliacion_reglas')
                ->where('empresa_id', self::EMPRESA_ID)->where('codigo', $r['codigo'])
                ->update(['patron_concepto' => $this->stripDelim($r['regex_nuevo']), 'updated_at' => now()]);
            $afectadas > 0 ? $this->stats['regex_ajustadas']++ : ($this->stats['regex_no_encontradas'][] = $r['codigo']);
        }
    }

    private function crearReglas(array $rows): void
    {
        foreach ($rows as $r) {
            $codigo = $r['codigo'];
            $modo = $r['modo'] ?? 'FIJO';
            $esDinamico = $modo === 'DINAMICO_POR_AUXILIAR';
            $signo = $this->mapSigno($r['signo'] ?? null);
            $payload = [
                'empresa_id' => self::EMPRESA_ID,
                'descripcion' => $r['descripcion'] ?? $r['comentario'] ?? $codigo,
                'tipo' => self::TIPO_REGEX,
                'patron_concepto' => $this->stripDelim($r['regex'] ?? ''),
                'cuenta_contable_id' => $this->cuentaId($r['cuenta'] ?? null),
                'cuenta_contable_modo' => $modo,
                'auxiliar_id' => $this->auxiliarId($r['auxiliar'] ?? null),
                'matching_auto_factura' => $esDinamico ? 1 : 0,
                'tipo_auxiliar' => $esDinamico ? ($signo === 'CREDITO' ? 'CLIENTE' : 'PROVEEDOR') : null,
                'banco_id' => null,
                'signo' => $signo,
                'orden_prioridad' => (int) ($r['prioridad'] ?? 50),
                'confianza' => (int) ($r['confianza'] ?? 90),
                'activa' => 1,
                'observacion' => '[v1.47] ' . ($r['comentario'] ?? ''),
                'updated_at' => now(),
            ];
            $existe = DB::table('erp_conciliacion_reglas')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $codigo)->first();
            if ($existe) {
                DB::table('erp_conciliacion_reglas')->where('id', $existe->id)->update($payload);
                $this->stats['reglas_actualizadas']++;
            } else {
                $payload['codigo'] = $codigo;
                $payload['created_at'] = now();
                DB::table('erp_conciliacion_reglas')->insert($payload);
                $this->stats['reglas_creadas']++;
            }
        }
    }

    private function cuentaId(?string $codigo): ?int
    {
        if (! $codigo) return null;
        $id = DB::table('erp_cuentas_contables')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $codigo)->value('id');
        if (! $id) $this->stats['cuentas_no_encontradas'][] = $codigo;
        return $id ? (int) $id : null;
    }

    private function auxiliarId(?string $codigo): ?int
    {
        if (! $codigo) return null;
        $id = DB::table('erp_auxiliares')->where('empresa_id', self::EMPRESA_ID)->where('codigo', $codigo)->value('id');
        if (! $id) $this->stats['auxiliares_no_encontrados'][] = $codigo;
        return $id ? (int) $id : null;
    }

    private function mapSigno(?string $s): string
    {
        return match (trim((string) $s)) {
            '+' => 'CREDITO',
            '-' => 'DEBITO',
            default => 'AMBOS', // ± u otro
        };
    }

    private function stripDelim(string $regex): string
    {
        $s = trim($regex);
        if (preg_match('~^/(.*)/[a-z]*$~is', $s, $m)) return $m[1];
        return $s;
    }
}

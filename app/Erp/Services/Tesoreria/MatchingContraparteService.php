<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\Tesoreria\AliasContraparte;
use App\Erp\Models\Tesoreria\ConciliacionPrefijo;
use App\Erp\Models\Tesoreria\ConciliacionRegla;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use Illuminate\Support\Facades\DB;

/**
 * Identifica la contraparte de un movimiento bancario combinando 4 estrategias
 * (en este orden — primer match gana):
 *
 *   1. Reglas explícitas (`erp_conciliacion_reglas`) filtradas por banco/signo/
 *      cod_concepto y matcheadas por `patron_concepto` (regex). Resultado:
 *      cuenta_contable_propuesta_id + confianza de la regla.
 *   2. Extracción de CUIT del concepto via prefijos del catálogo
 *      `erp_conciliacion_prefijos` (CBU/POLIZA/CUENTA_SERVICIO/TELEFONO/CUIT) +
 *      lookup en `erp_auxiliares` por CUIT. Confianza 90.
 *   3. Cache de alias previos (`erp_alias_contraparte`) por nombre normalizado.
 *      Confianza = la que se grabó en el alias (default 100).
 *   4. Fuzzy match contra `erp_auxiliares.nombre` por LIKE/levenshtein.
 *      Confianza 70 (único) o 0 (múltiple/ninguno).
 *
 * También detecta transferencias internas: si el nombre matchea otra
 * `erp_cuentas_bancarias` de la misma empresa con el mismo CUIT
 * propio → `cuenta_propia_id`.
 *
 * El servicio NO escribe en el movimiento — devuelve un MatchResult que el
 * caller (ExtractoImporterService) aplica.
 */
class MatchingContraparteService
{
    /**
     * @return array{
     *   cuit_contraparte: ?string,
     *   nombre_contraparte: ?string,
     *   persona_id: ?int,
     *   cliente_id: ?int,
     *   cuenta_propia_id: ?int,
     *   referencia_externa: ?string,
     *   regla_aplicada_id: ?int,
     *   cuenta_contable_propuesta_id: ?int,
     *   confianza_match: int,
     *   estrategia: string,
     * }
     */
    public function matchear(MovimientoBancario $mov): array
    {
        $base = [
            'cuit_contraparte' => null,
            'nombre_contraparte' => null,
            'persona_id' => null,
            'cliente_id' => null,
            'cuenta_propia_id' => null,
            'referencia_externa' => null,
            'regla_aplicada_id' => null,
            'cuenta_contable_propuesta_id' => null,
            'confianza_match' => 0,
            'estrategia' => 'NINGUNA',
        ];

        $empresaId = (int) $mov->cuentaBancaria->empresa_id;
        $bancoId   = (int) $mov->cuentaBancaria->banco_id;

        // 1. Reglas explícitas.
        if ($r = $this->aplicarRegla($mov, $empresaId, $bancoId)) {
            return [...$base, ...$r, 'estrategia' => 'REGLA'];
        }

        // 2. CUIT en el concepto via prefijos del catálogo del banco.
        if ($r = $this->extraerPorPrefijo($mov, $bancoId)) {
            return [...$base, ...$r];
        }

        // 3. Alias previo asignado manualmente.
        if ($r = $this->lookupAlias($mov, $empresaId, $bancoId)) {
            return [...$base, ...$r, 'estrategia' => 'ALIAS'];
        }

        // 4. Fuzzy contra erp_auxiliares por nombre.
        if ($r = $this->fuzzyAuxiliares($mov, $empresaId)) {
            return [...$base, ...$r, 'estrategia' => 'FUZZY'];
        }

        return $base;
    }

    /**
     * Itera reglas activas del banco/empresa filtradas por signo + cod_concepto
     * en orden_prioridad ascendente y devuelve el primer match.
     */
    private function aplicarRegla(MovimientoBancario $mov, int $empresaId, int $bancoId): ?array
    {
        $signo = $mov->esCredito()
            ? ConciliacionRegla::SIGNO_CREDITO
            : ConciliacionRegla::SIGNO_DEBITO;

        $reglas = ConciliacionRegla::query()
            ->where('empresa_id', $empresaId)
            ->where('activa', 1)
            ->where(fn ($q) => $q->whereNull('banco_id')->orWhere('banco_id', $bancoId))
            ->whereIn('signo', [$signo, ConciliacionRegla::SIGNO_AMBOS])
            ->orderBy('orden_prioridad')
            ->get();

        $codConcepto = $this->extraerCodConcepto($mov->concepto);
        $importeAbs  = abs($mov->importeFirmado());

        foreach ($reglas as $regla) {
            // Filtro cod_concepto si la regla lo declara.
            if ($regla->cod_concepto && $codConcepto !== $regla->cod_concepto) {
                continue;
            }

            // CONCEPTO_REGEX o COMBINADA: probar regex.
            if (in_array($regla->tipo, [ConciliacionRegla::TIPO_CONCEPTO_REGEX, ConciliacionRegla::TIPO_COMBINADA], true)) {
                if (! $regla->patron_concepto) {
                    continue;
                }
                if (! @preg_match("/{$regla->patron_concepto}/iu", $mov->concepto)) {
                    continue;
                }
            }

            // IMPORTE_EXACTO o COMBINADA: chequear rango.
            if (in_array($regla->tipo, [ConciliacionRegla::TIPO_IMPORTE_EXACTO, ConciliacionRegla::TIPO_COMBINADA], true)) {
                $desde = (float) ($regla->patron_importe_desde ?? -INF);
                $hasta = (float) ($regla->patron_importe_hasta ??  INF);
                if ($importeAbs < $desde || $importeAbs > $hasta) {
                    continue;
                }
            }

            return [
                'regla_aplicada_id' => $regla->id,
                'cuenta_contable_propuesta_id' => $regla->cuenta_contable_id,
                'confianza_match' => (int) ($regla->confianza ?? 80),
            ];
        }

        return null;
    }

    /**
     * Recorre los prefijos del banco. Si encuentra uno cuyo prefijo aparece en
     * el concepto, extrae el siguiente bloque numérico que cumpla la longitud
     * configurada y, si es un CUIT, busca en `erp_auxiliares`.
     */
    private function extraerPorPrefijo(MovimientoBancario $mov, int $bancoId): ?array
    {
        $prefijos = ConciliacionPrefijo::query()
            ->where('banco_id', $bancoId)
            ->where('activo', 1)
            ->get();

        $concepto = mb_strtoupper($mov->concepto);

        foreach ($prefijos as $p) {
            $prefijoUpper = mb_strtoupper($p->prefijo);
            $pos = mb_strpos($concepto, $prefijoUpper);
            if ($pos === false) {
                continue;
            }

            $resto = mb_substr($concepto, $pos + mb_strlen($prefijoUpper));
            // El primer bloque numérico que cumpla longitud configurada.
            $minLen = $p->longitud_min ?? 1;
            $maxLen = $p->longitud_max ?? 30;
            if (! preg_match("/(\d{{$minLen},{$maxLen}})/", $resto, $m)) {
                continue;
            }
            $numero = $m[1];

            $hit = [
                'cuenta_contable_propuesta_id' => $p->cuenta_contable_default_id,
                'referencia_externa' => $numero,
                'estrategia' => "PREFIJO:{$p->tipo_numero}",
            ];

            if ($p->tipo_numero === 'CUIT' && $this->esCuitValido($numero)) {
                $hit['cuit_contraparte'] = $numero;
                if ($aux = $this->buscarPorCuit($mov->cuentaBancaria->empresa_id, $numero)) {
                    $hit = [...$hit, ...$aux];
                    $hit['confianza_match'] = 90;
                } else {
                    $hit['confianza_match'] = 50; // CUIT extraído pero sin match
                }
            } else {
                $hit['confianza_match'] = 60; // referencia extraída sin contraparte resuelta
            }

            return $hit;
        }

        return null;
    }

    /** Busca alias previo por nombre normalizado. */
    private function lookupAlias(MovimientoBancario $mov, int $empresaId, int $bancoId): ?array
    {
        $alias = AliasContraparte::normalizar($mov->concepto);
        if ($alias === '') {
            return null;
        }

        $hit = AliasContraparte::query()
            ->where('empresa_id', $empresaId)
            ->where(fn ($q) => $q->whereNull('banco_id')->orWhere('banco_id', $bancoId))
            ->where('alias_normalizado', $alias)
            ->first();

        if (! $hit) {
            return null;
        }

        return [
            'persona_id' => $hit->persona_id,
            'cliente_id' => $hit->cliente_id,
            'nombre_contraparte' => $alias,
            'cuenta_contable_propuesta_id' => $hit->cuenta_contable_id,
            'confianza_match' => (int) $hit->confianza,
        ];
    }

    /**
     * Fuzzy match contra erp_auxiliares.nombre.
     * Estrategia: extraer "stop-words" de prefijos comunes ("Transferencia
     * recibida", "Pago", "Ingreso de dinero") + buscar LIKE %palabra%
     * suficientemente larga.
     */
    private function fuzzyAuxiliares(MovimientoBancario $mov, int $empresaId): ?array
    {
        $alias = AliasContraparte::normalizar($mov->concepto);
        if ($alias === '') {
            return null;
        }

        // Quitar prefijos típicos para quedarme con el "nombre puro".
        $stopwords = [
            'TRANSFERENCIA RECIBIDA', 'TRANSFERENCIA ENVIADA',
            'INGRESO DE DINERO', 'PAGO DE SERVICIO',
            'PAGO CON QR', 'PAGO',
            'DEBITO INMEDIATO', 'CREDITO INMEDIATO',
        ];
        foreach ($stopwords as $sw) {
            if (str_starts_with($alias, $sw.' ') || $alias === $sw) {
                $alias = trim(mb_substr($alias, mb_strlen($sw)));
                break;
            }
        }
        if (mb_strlen($alias) < 4) {
            return null;
        }

        // Tomar palabras significativas (>=4 chars) y buscar candidatos.
        $palabras = array_filter(
            explode(' ', $alias),
            fn ($p) => mb_strlen($p) >= 4
        );
        if (empty($palabras)) {
            return null;
        }

        $q = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->select('id', 'nombre', 'cuit', 'tipo', 'tabla_ref', 'id_ref');
        foreach ($palabras as $palabra) {
            $q->where('nombre', 'LIKE', "%{$palabra}%");
        }

        $candidatos = $q->limit(5)->get();

        if ($candidatos->count() === 1) {
            return $this->mapAuxiliar($candidatos[0], confianza: 70, nombre: $alias);
        }

        if ($candidatos->count() > 1) {
            // Si entre los múltiples uno coincide casi exacto, ganamos confianza.
            foreach ($candidatos as $c) {
                $nNorm = AliasContraparte::normalizar((string) $c->nombre);
                if (levenshtein($nNorm, $alias) <= 2) {
                    return $this->mapAuxiliar($c, confianza: 75, nombre: $alias);
                }
            }
            return [
                'nombre_contraparte' => $alias,
                'confianza_match' => 30,
            ];
        }

        return null;
    }

    /** Busca un auxiliar por CUIT exacto. */
    private function buscarPorCuit(int $empresaId, string $cuit): ?array
    {
        $cuitLimpio = preg_replace('/[^0-9]/', '', $cuit);
        $aux = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('cuit', $cuitLimpio)
            ->where('activo', 1)
            ->first();

        // Si el CUIT pertenece a la propia empresa, intentar mapear a cuenta propia.
        $cuentaPropia = CuentaBancaria::query()
            ->where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->whereHas('empresa', fn ($q) => $q->where('cuit', $cuitLimpio))
            ->first();
        if ($cuentaPropia) {
            return [
                'cuenta_propia_id' => $cuentaPropia->id,
                'nombre_contraparte' => $cuentaPropia->nombre,
            ];
        }

        return $aux ? $this->mapAuxiliar($aux, confianza: 90, nombre: $aux->nombre) : null;
    }

    /** Convierte una fila erp_auxiliares al formato MatchResult. */
    private function mapAuxiliar(object $aux, int $confianza, string $nombre): array
    {
        $hit = [
            'nombre_contraparte' => $nombre,
            'cuit_contraparte' => $aux->cuit ?: null,
            'confianza_match' => $confianza,
        ];
        if ($aux->tabla_ref === 'basepersonal.clientes' && $aux->id_ref) {
            $hit['cliente_id'] = (int) $aux->id_ref;
        } elseif ($aux->tabla_ref === 'basepersonal.personas' && $aux->id_ref) {
            $hit['persona_id'] = (int) $aux->id_ref;
        }
        return $hit;
    }

    /**
     * Extrae el código de concepto si el banco lo embebe (ej. ICBC: pone un
     * código de 3-4 caracteres entre paréntesis al final del concepto).
     */
    private function extraerCodConcepto(string $concepto): ?string
    {
        if (preg_match('/\(([A-Z0-9]{2,6})\)\s*$/u', $concepto, $m)) {
            return $m[1];
        }
        return null;
    }

    /** Validación simple de check digit de CUIT. */
    public function esCuitValido(string $cuit): bool
    {
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        if (strlen($cuit) !== 11) {
            return false;
        }
        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ((int) $cuit[$i]) * $multipliers[$i];
        }
        $mod = $sum % 11;
        $check = $mod === 0 ? 0 : ($mod === 1 ? 9 : 11 - $mod);
        return $check === (int) $cuit[10];
    }
}

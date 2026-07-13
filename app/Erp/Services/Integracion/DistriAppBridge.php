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
     * v1.32 — Último número de recibo emitido en DistriApp para un PV dado.
     * Lee `basepersonal.liquidacion_recibos` directamente. Si la tabla no
     * está accesible (DB desconectada / drift de schema), devuelve 0 — el
     * caller debe combinar con su secuencia local.
     */
    public function ultimoNumeroRecibo(string $puntoVenta): int
    {
        try {
            $row = DB::selectOne(
                "SELECT MAX(CAST(numero_recibo AS UNSIGNED)) AS m
                   FROM basepersonal.liquidacion_recibos
                  WHERE punto_venta = ?",
                [$puntoVenta],
            );
            return (int) ($row->m ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * v1.35 — Órdenes de pago de DistriApp (basepersonal.liq_ordenes_pago).
     * Lectura directa (read-only). Soporta paginación + filtro incremental por
     * updated_at. Devuelve filas crudas; el SyncService las normaliza.
     *
     * @param  array{offset?:int, limit?:int, updated_desde?:?string}  $opts
     * @return array{data: list<object>, total: int}
     */
    public function fetchOrdenesPago(array $opts = []): array
    {
        $limit = (int) ($opts['limit'] ?? 200);
        $offset = (int) ($opts['offset'] ?? 0);
        $updatedDesde = $opts['updated_desde'] ?? null;

        try {
            $base = DB::table('basepersonal.liq_ordenes_pago as op')
                ->leftJoin('basepersonal.liq_ordenes_pago_conceptos as c', 'c.id', '=', 'op.concepto_id');

            if ($updatedDesde) {
                $base->where('op.updated_at', '>', $updatedDesde);
            }

            $total = (clone $base)->count();

            $rows = $base->orderBy('op.id')
                ->offset($offset)->limit($limit)
                ->get([
                    'op.id', 'op.concepto_id', 'c.nombre as concepto_nombre', 'c.codigo as concepto_codigo',
                    'op.numero', 'op.numero_display', 'op.anio', 'op.mes', 'op.fecha_emision',
                    'op.beneficiario_tipo', 'op.beneficiario_id', 'op.beneficiario_nombre',
                    'op.beneficiario_cuil', 'op.beneficiario_cbu',
                    'op.subtotal', 'op.total_descuentos', 'op.total_a_pagar',
                    'op.estado', 'op.agrupacion', 'op.medio_pago',
                    'op.icbc_tx_id', 'op.icbc_acreditado_at', 'op.observaciones',
                    'op.created_at', 'op.updated_at',
                ]);

            return ['data' => $rows->all(), 'total' => $total];
        } catch (\Throwable $e) {
            return ['data' => [], 'total' => 0];
        }
    }

    /**
     * v1.32 — Datos extendidos de un cliente DistriApp (para snapshot de recibo).
     * Lee `basepersonal.clientes` directo para obtener direccion completa
     * (la vista `erp_v_clientes_distriapp` solo trae 1 dirección).
     *
     * @return ?object {nombre, cuit, direccion_1, direccion_2, condicion_iva}
     */
    public function datosCliente(int $distriappId): ?object
    {
        try {
            $row = DB::selectOne(
                "SELECT nombre, documento_fiscal AS cuit, direccion
                   FROM basepersonal.clientes
                  WHERE id = ? AND deleted_at IS NULL",
                [$distriappId],
            );
            if (! $row) return null;

            // basepersonal.clientes.direccion viene como texto único.
            // Particionamos en línea 1 / 2 si tiene salto de línea, sino todo
            // va en direccion_1.
            $direccion = trim((string) ($row->direccion ?? ''));
            $partes = preg_split('/\r\n|\r|\n/', $direccion, 2);
            return (object) [
                'nombre' => $row->nombre,
                'cuit' => $row->cuit,
                'direccion_1' => $partes[0] ?? '',
                'direccion_2' => $partes[1] ?? '',
                // condicion_iva no está en basepersonal.clientes — el form
                // del recibo la pide al operador al cargar el cliente.
                'condicion_iva' => null,
            ];
        } catch (\Throwable $e) {
            return null;
        }
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
     * v1.36 — Prevención de duplicados: si ya existe un auxiliar Cliente con
     * ese CUIT (típicamente creado por el importador del Libro IVA como
     * CLI-{cuit}), se REUSA — solo se vincula id_ref/tabla_ref a DistriApp en
     * vez de crear un DA-CLI-{id} paralelo. El código DA-CLI- queda como
     * fallback solo para clientes sin CUIT válido.
     *
     * Idempotente. Devuelve {creados, actualizados, vinculados, total}.
     */
    public function syncClientes(int $empresaId = 1): array
    {
        $creados = 0;
        $actualizados = 0;
        $vinculados = 0;
        $clientes = $this->clientes();

        foreach ($clientes as $c) {
            $cuitLimpio = $c->cuit ? preg_replace('/[^0-9]/', '', $c->cuit) : null;
            if ($cuitLimpio && strlen($cuitLimpio) !== 11) {
                $cuitLimpio = null;  // rechaza CUITs inválidos, no rompe el sync
            }

            // v1.36 — buscar primero por CUIT (cualquier código).
            $porCuit = $cuitLimpio
                ? DB::table('erp_auxiliares')
                    ->where('empresa_id', $empresaId)->where('tipo', 'Cliente')
                    ->where('cuit', $cuitLimpio)->first()
                : null;

            if ($porCuit) {
                // Ya existe (probablemente del importador). Solo vinculamos a
                // DistriApp si no estaba vinculado; NO pisamos el código CLI-.
                $update = ['activo' => 1, 'updated_at' => now()];
                if (! $porCuit->id_ref) {
                    $update['tabla_ref'] = 'basepersonal.clientes';
                    $update['id_ref'] = $c->distriapp_id;
                }
                DB::table('erp_auxiliares')->where('id', $porCuit->id)->update($update);
                $vinculados++;
                continue;
            }

            // No existe por CUIT — buscar por código legacy DA-CLI- (idempotencia
            // con syncs previos) o crear nuevo.
            $codigo = $cuitLimpio
                ? 'CLI-' . $cuitLimpio
                : 'DA-CLI-' . str_pad((string) $c->distriapp_id, 5, '0', STR_PAD_LEFT);

            $legacy = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)->where('tipo', 'Cliente')
                ->where('tabla_ref', 'basepersonal.clientes')->where('id_ref', $c->distriapp_id)
                ->first();

            $attrs = [
                'nombre' => $c->razon_social,
                'cuit' => $cuitLimpio,
                'tabla_ref' => 'basepersonal.clientes',
                'id_ref' => $c->distriapp_id,
                'activo' => 1,
                'updated_at' => now(),
            ];

            if ($legacy) {
                DB::table('erp_auxiliares')->where('id', $legacy->id)->update($attrs);
                $actualizados++;
            } else {
                $nuevoId = DB::table('erp_auxiliares')->insertGetId([
                    'empresa_id' => $empresaId,
                    'tipo' => 'Cliente',
                    'codigo' => $codigo,
                    ...$attrs,
                    'created_at' => now(),
                ]);
                \App\Erp\Support\CcCliente::asegurar($nuevoId); // bug 3
                $creados++;
            }
        }

        return [
            'creados' => $creados,
            'actualizados' => $actualizados,
            'vinculados' => $vinculados,
            'total' => $clientes->count(),
        ];
    }

    /**
     * Lista clientes de la plataforma (basepersonal.clientes) para completarlos
     * desde el ERP. Incluye el estado fiscal actual (tax_profiles) y si ya está
     * vinculado a un auxiliar del ERP. Filtra por nombre/código/documento.
     *
     * @return array<int, object>
     */
    public function clientesPlataforma(string $termino = '', int $limite = 50): array
    {
        $termino = trim($termino);
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $termino).'%';
        $soloDigitos = preg_replace('/[^0-9]/', '', $termino);

        $sql = "SELECT c.id, c.codigo, c.nombre, c.documento_fiscal, c.direccion,
                       tp.razon_social, tp.iva_condition,
                       tp.fiscal_address_street, tp.fiscal_address_number,
                       tp.fiscal_address_floor, tp.fiscal_address_unit,
                       tp.fiscal_address_locality, tp.fiscal_address_postal_code,
                       tp.fiscal_address_province,
                       a.id AS erp_auxiliar_id, a.condicion_iva_id AS erp_condicion_iva_id,
                       a.sincronizado_plataforma_at
                  FROM basepersonal.clientes c
                  LEFT JOIN basepersonal.tax_profiles tp
                         ON tp.entity_type = 'cliente' AND tp.entity_id = c.id
                  LEFT JOIN erp_auxiliares a
                         ON a.tabla_ref = 'basepersonal.clientes' AND a.id_ref = c.id
                        AND a.tipo = 'Cliente'
                 WHERE c.deleted_at IS NULL";
        $params = [];
        if ($termino !== '') {
            $sql .= " AND (c.nombre LIKE ? OR c.codigo LIKE ?
                          OR REPLACE(REPLACE(c.documento_fiscal, '-', ''), ' ', '') LIKE ?)";
            $params = [$like, $like, $soloDigitos !== '' ? $soloDigitos.'%' : $like];
        }
        $sql .= ' ORDER BY c.nombre LIMIT '.(int) $limite;

        return DB::select($sql, $params);
    }

    /**
     * Completa los datos fiscales reales de un cliente de la plataforma desde el
     * ERP. Escribe en AMBOS lados de forma atómica (misma conexión MySQL ⇒ una
     * sola transacción cubre erp_logistica_prod + basepersonal):
     *   1) Upsert del auxiliar ERP (guarda los datos fiscales completos + vínculo).
     *   2) UPDATE in-place de basepersonal.clientes (solo nombre/documento/dirección;
     *      NUNCA cambia id/codigo ⇒ no rompe FKs, pre-altas ni liquidaciones).
     *   3) Upsert de basepersonal.tax_profiles (razón social, CUIT, domicilio fiscal,
     *      condición IVA).
     *
     * @param  array{
     *   razon_social:string, cuit:?string, condicion_iva_id:?int,
     *   domicilio_calle:?string, domicilio_nro:?string, domicilio_piso:?string,
     *   domicilio_depto:?string, localidad:?string, provincia:?string, cod_postal:?string,
     * }  $input
     * @return array{auxiliar_id:int, cliente_plataforma_id:int}
     */
    public function completarCliente(int $clienteId, array $input, int $empresaId = 1, ?int $usuarioId = null): array
    {
        $cli = DB::selectOne(
            'SELECT id, codigo FROM basepersonal.clientes WHERE id = ? AND deleted_at IS NULL',
            [$clienteId],
        );
        if (! $cli) {
            throw new \DomainException('CLIENTE_PLATAFORMA_NO_ENCONTRADO: no existe el cliente #'.$clienteId.' en la plataforma.');
        }

        $razon = trim((string) ($input['razon_social'] ?? ''));
        if ($razon === '') {
            throw new \DomainException('RAZON_SOCIAL_REQUERIDA: cargá la razón social / nombre real del cliente.');
        }
        $cuit = ! empty($input['cuit']) ? preg_replace('/[^0-9]/', '', (string) $input['cuit']) : null;
        if ($cuit !== null && strlen($cuit) !== 11) {
            throw new \DomainException('CUIT_INVALIDO: el CUIT/CUIL debe tener 11 dígitos (o dejarse vacío).');
        }

        // Resolver condición IVA del catálogo ERP (nombre + flag inscripto).
        $ivaCondNombre = null;
        $ivaInscripto = null;
        $condId = ! empty($input['condicion_iva_id']) ? (int) $input['condicion_iva_id'] : null;
        if ($condId) {
            $cond = DB::table('erp_condiciones_iva')->where('id', $condId)->first(['codigo_interno', 'nombre']);
            if (! $cond) {
                throw new \DomainException('CONDICION_IVA_INVALIDA: la condición IVA seleccionada no existe.');
            }
            $ivaCondNombre = $cond->nombre;
            $ivaInscripto = $cond->codigo_interno === 'RI' ? 1 : 0;
        }

        $dir = $this->componerDireccion($input);

        return DB::transaction(function () use ($clienteId, $empresaId, $usuarioId, $cli, $razon, $cuit, $condId, $ivaCondNombre, $ivaInscripto, $dir, $input) {
            // ── 1) Upsert auxiliar ERP ──────────────────────────────────────
            $aux = DB::table('erp_auxiliares')
                ->where('empresa_id', $empresaId)->where('tipo', 'Cliente')
                ->where('tabla_ref', 'basepersonal.clientes')->where('id_ref', $clienteId)
                ->first();

            // OJO: razon_social_normalizada es columna GENERADA (la calcula la DB),
            // no se escribe acá.
            $auxAttrs = [
                'nombre' => $razon,
                'cuit' => $cuit,
                'domicilio_calle' => $input['domicilio_calle'] ?? null,
                'domicilio_nro' => $input['domicilio_nro'] ?? null,
                'domicilio_piso' => $input['domicilio_piso'] ?? null,
                'domicilio_depto' => $input['domicilio_depto'] ?? null,
                'localidad' => $input['localidad'] ?? null,
                'provincia' => $input['provincia'] ?? null,
                'cod_postal' => $input['cod_postal'] ?? null,
                'condicion_iva_id' => $condId,
                'tabla_ref' => 'basepersonal.clientes',
                'id_ref' => $clienteId,
                'activo' => 1,
                'sincronizado_plataforma_at' => now(),
                'updated_at' => now(),
            ];

            if ($aux) {
                DB::table('erp_auxiliares')->where('id', $aux->id)->update($auxAttrs);
                $auxId = $aux->id;
            } else {
                // Si ya existe un auxiliar Cliente por CUIT (del importador), lo
                // reusamos y le vinculamos el cliente de la plataforma.
                $porCuit = $cuit
                    ? DB::table('erp_auxiliares')->where('empresa_id', $empresaId)
                        ->where('tipo', 'Cliente')->where('cuit', $cuit)->whereNull('id_ref')->first()
                    : null;
                if ($porCuit) {
                    DB::table('erp_auxiliares')->where('id', $porCuit->id)->update($auxAttrs);
                    $auxId = $porCuit->id;
                } else {
                    $codigo = $cuit ? 'CLI-'.$cuit : 'DA-CLI-'.str_pad((string) $clienteId, 5, '0', STR_PAD_LEFT);
                    $auxId = DB::table('erp_auxiliares')->insertGetId([
                        'empresa_id' => $empresaId, 'tipo' => 'Cliente', 'codigo' => $codigo,
                        ...$auxAttrs, 'created_at' => now(),
                    ]);
                    \App\Erp\Support\CcCliente::asegurar($auxId); // bug 3
                }
            }

            // ── 2) UPDATE in-place de basepersonal.clientes ─────────────────
            // Solo columnas descriptivas. NUNCA id/codigo ⇒ no rompe nada.
            DB::update(
                'UPDATE basepersonal.clientes
                    SET nombre = ?, documento_fiscal = ?, direccion = ?, updated_at = NOW()
                  WHERE id = ?',
                [$razon, $cuit, $dir, $clienteId],
            );

            // ── 3) Upsert basepersonal.tax_profiles ─────────────────────────
            $cols = [
                'cuit' => $cuit,
                'razon_social' => $razon,
                'iva_condition' => $ivaCondNombre,
                'iva_inscripto' => $ivaInscripto,
                'fiscal_address_street' => $input['domicilio_calle'] ?? null,
                'fiscal_address_number' => $input['domicilio_nro'] ?? null,
                'fiscal_address_floor' => $input['domicilio_piso'] ?? null,
                'fiscal_address_unit' => $input['domicilio_depto'] ?? null,
                'fiscal_address_locality' => $input['localidad'] ?? null,
                'fiscal_address_postal_code' => $input['cod_postal'] ?? null,
                'fiscal_address_province' => $input['provincia'] ?? null,
            ];
            $tp = DB::selectOne(
                "SELECT id FROM basepersonal.tax_profiles WHERE entity_type = 'cliente' AND entity_id = ?",
                [$clienteId],
            );
            if ($tp) {
                $set = implode(', ', array_map(fn ($k) => "$k = ?", array_keys($cols)));
                DB::update(
                    "UPDATE basepersonal.tax_profiles SET $set, updated_at = NOW() WHERE id = ?",
                    [...array_values($cols), $tp->id],
                );
            } else {
                $insCols = array_merge(['entity_type', 'entity_id'], array_keys($cols));
                $insVals = array_merge(['cliente', $clienteId], array_values($cols));
                $ph = implode(', ', array_fill(0, count($insCols), '?'));
                DB::insert(
                    'INSERT INTO basepersonal.tax_profiles ('.implode(', ', $insCols).', created_at, updated_at) '
                    ."VALUES ($ph, NOW(), NOW())",
                    $insVals,
                );
            }

            // Auditoría de la corrida (tabla puente del ERP). El enum `flujo` no
            // tiene una opción de cliente, usamos DASHBOARD como catch-all.
            DB::table('erp_integracion_log')->insert([
                'timestamp' => now(),
                'flujo' => 'DASHBOARD',
                'distriapp_tabla' => 'basepersonal.clientes',
                'distriapp_id' => $clienteId,
                'estado' => 'OK',
                'mensaje' => 'Cliente completado desde el ERP: '.$razon.($cuit ? ' (CUIT '.$cuit.')' : ''),
                'payload' => json_encode([
                    'auxiliar_id' => $auxId,
                    'razon_social' => $razon,
                    'cuit' => $cuit,
                    'usuario_id' => $usuarioId,
                ], JSON_UNESCAPED_UNICODE),
            ]);

            return ['auxiliar_id' => (int) $auxId, 'cliente_plataforma_id' => $clienteId];
        });
    }

    /** Compone la dirección de texto único de la plataforma desde las partes. */
    private function componerDireccion(array $i): ?string
    {
        $calle = trim((string) ($i['domicilio_calle'] ?? ''));
        $linea1 = trim($calle.' '.trim((string) ($i['domicilio_nro'] ?? '')));
        $extra = array_filter([
            ($i['domicilio_piso'] ?? '') ? 'Piso '.$i['domicilio_piso'] : '',
            ($i['domicilio_depto'] ?? '') ? 'Dpto '.$i['domicilio_depto'] : '',
        ]);
        if ($extra) $linea1 = trim($linea1.' '.implode(' ', $extra));
        $partes = array_filter([
            $linea1,
            trim((string) ($i['localidad'] ?? '')),
            trim((string) ($i['provincia'] ?? '')),
            ($i['cod_postal'] ?? '') ? '(CP '.$i['cod_postal'].')' : '',
        ], fn ($p) => $p !== '');
        $dir = trim(implode(', ', $partes));
        return $dir !== '' ? $dir : null;
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
     * Búsqueda live de personas DistriApp (CUIL o nombre) para el autocomplete
     * del editor de asientos / alta de auxiliares. Reemplaza el bulk sync
     * cuando se necesita resolver un registro puntual sin sincronizar todo.
     *
     * @return Collection<int, object>
     */
    public function buscarPersonas(string $termino, int $limite = 20): Collection
    {
        $termino = trim($termino);
        if ($termino === '') {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $termino).'%';
        $soloDigitos = preg_replace('/[^0-9]/', '', $termino);

        return collect(DB::select(
            'SELECT * FROM erp_v_distribuidores
             WHERE CONCAT_WS(" ", apellidos, nombres) LIKE ?
                OR REPLACE(REPLACE(cuil, "-", ""), " ", "") LIKE ?
             ORDER BY apellidos, nombres
             LIMIT '.(int) $limite,
            [$like, $soloDigitos !== '' ? $soloDigitos.'%' : $like]
        ));
    }

    /**
     * Búsqueda live de clientes DistriApp por razón social o CUIT.
     *
     * @return Collection<int, object>
     */
    public function buscarClientes(string $termino, int $limite = 20): Collection
    {
        $termino = trim($termino);
        if ($termino === '') {
            return collect();
        }

        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $termino).'%';
        $soloDigitos = preg_replace('/[^0-9]/', '', $termino);

        return collect(DB::select(
            'SELECT * FROM erp_v_clientes_distriapp
             WHERE activo = 1
               AND (razon_social LIKE ?
                    OR REPLACE(REPLACE(cuit, "-", ""), " ", "") LIKE ?)
             ORDER BY razon_social
             LIMIT '.(int) $limite,
            [$like, $soloDigitos !== '' ? $soloDigitos.'%' : $like]
        ));
    }

    /**
     * Resuelve o crea un `erp_auxiliares` a partir de una persona DistriApp.
     * Idempotente: si ya existe por (empresa, tipo, codigo), devuelve el existente.
     *
     * @return object  Fila de erp_auxiliares
     */
    public function crearDesdePersona(int $personaId, int $empresaId = 1): object
    {
        $persona = DB::selectOne(
            'SELECT * FROM erp_v_distribuidores WHERE distriapp_id = ?',
            [$personaId]
        );
        if (! $persona) {
            throw new \DomainException('PERSONA_NO_ENCONTRADA: '.$personaId);
        }

        $codigo = 'DA-DIST-'.str_pad((string) $persona->distriapp_id, 5, '0', STR_PAD_LEFT);
        $cuil = $persona->cuil ? preg_replace('/[^0-9]/', '', $persona->cuil) : null;
        if ($cuil && strlen($cuil) !== 11) {
            $cuil = null;
        }

        return $this->upsertAuxiliar($empresaId, 'Distribuidor', $codigo, [
            'nombre' => trim($persona->nombre_completo ?? ''),
            'cuit' => $cuil,
            'tabla_ref' => 'basepersonal.personas',
            'id_ref' => $persona->distriapp_id,
        ]);
    }

    /**
     * Resuelve o crea un `erp_auxiliares` a partir de un cliente DistriApp.
     * Idempotente.
     *
     * @return object  Fila de erp_auxiliares
     */
    public function crearDesdeCliente(int $clienteId, int $empresaId = 1): object
    {
        $cliente = DB::selectOne(
            'SELECT * FROM erp_v_clientes_distriapp WHERE distriapp_id = ?',
            [$clienteId]
        );
        if (! $cliente) {
            throw new \DomainException('CLIENTE_NO_ENCONTRADO: '.$clienteId);
        }

        $codigo = 'DA-CLI-'.str_pad((string) $cliente->distriapp_id, 5, '0', STR_PAD_LEFT);
        $cuit = $cliente->cuit ? preg_replace('/[^0-9]/', '', $cliente->cuit) : null;
        if ($cuit && strlen($cuit) !== 11) {
            $cuit = null;
        }

        return $this->upsertAuxiliar($empresaId, 'Cliente', $codigo, [
            'nombre' => $cliente->razon_social,
            'cuit' => $cuit,
            'tabla_ref' => 'basepersonal.clientes',
            'id_ref' => $cliente->distriapp_id,
        ]);
    }

    private function upsertAuxiliar(int $empresaId, string $tipo, string $codigo, array $attrs): object
    {
        $existing = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('tipo', $tipo)
            ->where('codigo', $codigo)
            ->first();

        $payload = [
            ...$attrs,
            'activo' => 1,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('erp_auxiliares')->where('id', $existing->id)->update($payload);

            return (object) DB::table('erp_auxiliares')->where('id', $existing->id)->first();
        }

        $id = DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => $empresaId,
            'tipo' => $tipo,
            'codigo' => $codigo,
            ...$payload,
            'created_at' => now(),
        ]);
        \App\Erp\Support\CcCliente::asegurar($id); // bug 3: no-op si no es Cliente

        return (object) DB::table('erp_auxiliares')->where('id', $id)->first();
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

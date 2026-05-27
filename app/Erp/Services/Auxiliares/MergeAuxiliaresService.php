<?php

namespace App\Erp\Services\Auxiliares;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * v1.36 — Unifica auxiliares duplicados por CUIT (mismo cliente real con 2+
 * registros: típicamente CLI-{cuit} del importador vs DA-CLI-{id} del sync
 * DistriApp). Reasigna todas las FKs al canónico y borra los perdedores.
 *
 * Schema real (verificado en prod): erp_auxiliares NO tiene deleted_at,
 * razon_social, origen_alta. El nombre vive en `nombre`. El borrado del
 * perdedor es físico (la trazabilidad queda en erp_auxiliares_merge_audit).
 *
 * Alcance acordado: solo tipo=Cliente. Excluye CUITs placeholder/inválidos.
 */
class MergeAuxiliaresService
{
    /**
     * FKs formales a erp_auxiliares.id (14) + 1 sin FK defensiva.
     * erp_centros_costo se maneja aparte (UNIQUE 1:1).
     *
     * @var array<string,string> tabla => columna
     */
    private const TABLAS_FK = [
        'erp_af_bienes' => 'proveedor_auxiliar_id',
        'erp_cliente_saldos_cc' => 'auxiliar_id',
        'erp_cobros' => 'auxiliar_id',
        'erp_conciliacion_reglas' => 'auxiliar_id',
        'erp_facturas_compra' => 'auxiliar_id',
        'erp_facturas_venta' => 'auxiliar_id',
        'erp_movimientos_asiento' => 'auxiliar_id',
        'erp_ordenes_pago' => 'auxiliar_id',
        'erp_proveedor_saldos_cc' => 'auxiliar_id',
        'erp_recibos' => 'cliente_auxiliar_id',
        'erp_retenciones_practicadas' => 'proveedor_id',
        'erp_saldos_cuenta' => 'auxiliar_id',
        // erp_facturas_compra.cliente_auxiliar_id se reasigna aparte (2da col de la misma tabla).
        // erp_movimientos_bancarios.cliente_id: sin FK; se reasigna defensivamente.
    ];

    /** CUITs placeholder/inválidos que NUNCA se mergean. */
    private const CUITS_PLACEHOLDER = ['11111111111', '00000000000', '99999999999', '20000000000'];

    /**
     * Detecta grupos de duplicados a mergear (tipo=Cliente por default).
     *
     * @return list<array{cuit:string, tipo:string, ids:list<int>, codigos:array<int,string>}>
     */
    public function detectarGrupos(string $tipo = 'Cliente', array $cuitsExcluir = [], int $empresaId = 1): array
    {
        $excluir = array_merge(self::CUITS_PLACEHOLDER, array_map(
            fn ($c) => preg_replace('/[^0-9]/', '', (string) $c), $cuitsExcluir,
        ));

        $rows = DB::table('erp_auxiliares')
            ->where('empresa_id', $empresaId)
            ->where('tipo', $tipo)
            ->whereNotNull('cuit')->where('cuit', '!=', '')
            ->get(['id', 'codigo', 'nombre', 'cuit', 'created_at']);

        $grupos = [];
        foreach ($rows as $r) {
            $cuitNorm = preg_replace('/[^0-9]/', '', (string) $r->cuit);
            if (strlen($cuitNorm) !== 11 || in_array($cuitNorm, $excluir, true)) continue;
            $grupos[$cuitNorm][] = $r;
        }

        $resultado = [];
        foreach ($grupos as $cuit => $filas) {
            if (count($filas) < 2) continue;
            $resultado[] = [
                'cuit' => $cuit,
                'tipo' => $tipo,
                'ids' => array_map(fn ($f) => (int) $f->id, $filas),
                'codigos' => collect($filas)->mapWithKeys(fn ($f) => [(int) $f->id => $f->codigo])->all(),
                'nombres' => collect($filas)->mapWithKeys(fn ($f) => [(int) $f->id => $f->nombre])->all(),
            ];
        }
        return $resultado;
    }

    /**
     * Elige el id canónico de un grupo: más movimientos → más viejo →
     * código CLI- (import) sobre DA-CLI- (sync) → menor id.
     */
    public function elegirCanonico(array $ids): int
    {
        $auxiliares = DB::table('erp_auxiliares')->whereIn('id', $ids)->get(['id', 'codigo', 'created_at']);
        $ranked = $auxiliares->map(fn ($a) => [
            'id' => (int) $a->id,
            'movs' => $this->contarMovimientos((int) $a->id),
            'created' => $a->created_at,
            'es_cli' => str_starts_with((string) $a->codigo, 'CLI-') ? 1 : 0,
        ])->sort(function ($a, $b) {
            return $b['movs'] <=> $a['movs']
                ?: strcmp((string) $a['created'], (string) $b['created'])
                ?: $b['es_cli'] <=> $a['es_cli']
                ?: $a['id'] <=> $b['id'];
        })->values();
        return $ranked->first()['id'];
    }

    public function contarMovimientos(int $id): int
    {
        $total = 0;
        foreach (self::TABLAS_FK as $tabla => $col) {
            $total += DB::table($tabla)->where($col, $id)->count();
        }
        $total += DB::table('erp_facturas_compra')->where('cliente_auxiliar_id', $id)->count();
        return $total;
    }

    /**
     * Cuenta cuántas FKs se reasignarían (para el dry-run).
     */
    public function contarFksAReasignar(array $idsPerdedores): array
    {
        $out = [];
        foreach (self::TABLAS_FK as $tabla => $col) {
            $c = DB::table($tabla)->whereIn($col, $idsPerdedores)->count();
            if ($c > 0) $out["{$tabla}.{$col}"] = $c;
        }
        $c = DB::table('erp_facturas_compra')->whereIn('cliente_auxiliar_id', $idsPerdedores)->count();
        if ($c > 0) $out['erp_facturas_compra.cliente_auxiliar_id'] = $c;
        $c = DB::table('erp_centros_costo')->whereIn('auxiliar_id', $idsPerdedores)->count();
        if ($c > 0) $out['erp_centros_costo.auxiliar_id'] = $c;
        return $out;
    }

    /**
     * Ejecuta el merge real de un grupo. Transaccional + lock pesimista.
     *
     * @return array{canonico_id:int, mergeados_ids:list<int>, fks_reasignadas:array}
     */
    public function ejecutarMerge(array $ids, string $cuit, string $motivo, ?int $userId = null): array
    {
        if (count($ids) < 2) {
            throw new \InvalidArgumentException('Se necesitan al menos 2 ids para mergear.');
        }
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        if (strlen($cuit) !== 11 || in_array($cuit, self::CUITS_PLACEHOLDER, true)) {
            throw new \InvalidArgumentException("CUIT inválido o placeholder: {$cuit}");
        }

        return DB::transaction(function () use ($ids, $cuit, $motivo, $userId) {
            // v1.36 — El trigger trg_fv_inmutable_bu bloquea reasignar auxiliar_id
            // en facturas de venta con CAE (RN-32). Para el merge legítimo (mismo
            // cliente real, solo se cambia el puntero al maestro) seteamos la var
            // de sesión que exime SOLO el cambio de auxiliar_id — los demás guards
            // fiscales (importes, CAE, número) siguen activos.
            DB::statement('SET @erp_merge_auxiliares = 1');

            $auxiliares = DB::table('erp_auxiliares')->whereIn('id', $ids)
                ->lockForUpdate()->get();
            if ($auxiliares->count() < 2) {
                throw new RuntimeException('Algunos ids ya no existen (¿merge concurrente?).');
            }
            $tipos = $auxiliares->pluck('tipo')->unique();
            if ($tipos->count() > 1) {
                throw new RuntimeException('No se mergean auxiliares de distinto tipo: '.$tipos->implode(','));
            }

            $canonicoId = $this->elegirCanonico($ids);
            $canonico = $auxiliares->firstWhere('id', $canonicoId);
            $perdedores = $auxiliares->reject(fn ($a) => (int) $a->id === $canonicoId);
            $perdedoresIds = $perdedores->pluck('id')->map(fn ($v) => (int) $v)->all();

            $snapshotPre = (array) $canonico;
            $codigosOriginales = $auxiliares->mapWithKeys(fn ($a) => [(int) $a->id => $a->codigo])->all();

            // 1) Reasignar FKs genéricas.
            $fks = [];
            foreach (self::TABLAS_FK as $tabla => $col) {
                $n = DB::table($tabla)->whereIn($col, $perdedoresIds)->update([$col => $canonicoId]);
                if ($n > 0) $fks["{$tabla}.{$col}"] = $n;
            }
            // 1b) 2da columna de facturas_compra.
            $n = DB::table('erp_facturas_compra')->whereIn('cliente_auxiliar_id', $perdedoresIds)
                ->update(['cliente_auxiliar_id' => $canonicoId]);
            if ($n > 0) $fks['erp_facturas_compra.cliente_auxiliar_id'] = $n;
            // 1c) movimientos_bancarios.cliente_id (sin FK, defensivo).
            $n = DB::table('erp_movimientos_bancarios')->whereIn('cliente_id', $perdedoresIds)
                ->update(['cliente_id' => $canonicoId]);
            if ($n > 0) $fks['erp_movimientos_bancarios.cliente_id'] = $n;

            // 2) Centros de costo (UNIQUE 1:1 sobre auxiliar_id).
            $fks = array_merge($fks, $this->reasignarCentrosCosto($perdedoresIds, $canonicoId));

            // 3) Normalizar canónico (código CLI-{cuit}, cuit sin guiones) +
            //    heredar del perdedor lo que al canónico le falte (vínculo
            //    DistriApp id_ref/tabla_ref + cuenta contable default).
            $codigoFinal = $canonico->tipo === 'Proveedor' ? "PROV-{$cuit}" : "CLI-{$cuit}";
            $update = ['codigo' => $codigoFinal, 'cuit' => $cuit, 'updated_at' => now()];
            if (empty($canonico->id_ref)) {
                $donante = $perdedores->first(fn ($p) => ! empty($p->id_ref));
                if ($donante) {
                    $update['tabla_ref'] = $donante->tabla_ref;
                    $update['id_ref'] = $donante->id_ref;
                }
            }
            if (empty($canonico->cuenta_contable_default_id)) {
                $donanteCta = $perdedores->first(fn ($p) => ! empty($p->cuenta_contable_default_id));
                if ($donanteCta) {
                    $update['cuenta_contable_default_id'] = $donanteCta->cuenta_contable_default_id;
                }
            }
            DB::table('erp_auxiliares')->where('id', $canonicoId)->update($update);

            // 4) Borrar perdedores (físico — no hay soft delete).
            DB::table('erp_auxiliares')->whereIn('id', $perdedoresIds)->delete();

            // 5) Audit.
            $snapshotPost = (array) DB::table('erp_auxiliares')->where('id', $canonicoId)->first();
            DB::table('erp_auxiliares_merge_audit')->insert([
                'cuit' => $cuit,
                'tipo' => $canonico->tipo,
                'id_canonico' => $canonicoId,
                'ids_mergeados' => json_encode($perdedoresIds),
                'codigos_originales' => json_encode($codigosOriginales),
                'fks_reasignadas' => json_encode($fks),
                'snapshot_canonico_pre' => json_encode($snapshotPre),
                'snapshot_canonico_post' => json_encode($snapshotPost),
                'decision_log' => $motivo,
                'ejecutado_por_user_id' => $userId,
                'ejecutado_at' => now(),
            ]);

            DB::statement('SET @erp_merge_auxiliares = NULL');

            return [
                'canonico_id' => $canonicoId,
                'mergeados_ids' => $perdedoresIds,
                'fks_reasignadas' => $fks,
            ];
        });
    }

    /**
     * Reasigna el CC de los perdedores al canónico. Si el canónico ya tiene CC
     * (colisión UNIQUE), reasigna las referencias downstream del CC perdedor al
     * CC ganador y borra el CC perdedor.
     */
    private function reasignarCentrosCosto(array $perdedoresIds, int $canonicoId): array
    {
        $fks = [];
        $ccCanonico = DB::table('erp_centros_costo')->where('auxiliar_id', $canonicoId)->value('id');
        $ccPerdedores = DB::table('erp_centros_costo')->whereIn('auxiliar_id', $perdedoresIds)->get(['id', 'auxiliar_id']);

        foreach ($ccPerdedores as $cc) {
            if (! $ccCanonico) {
                // Sin colisión: el canónico hereda el CC del perdedor.
                DB::table('erp_centros_costo')->where('id', $cc->id)->update([
                    'auxiliar_id' => $canonicoId, 'updated_at' => now(),
                ]);
                $ccCanonico = $cc->id; // por si hubiera más de un perdedor con CC.
                $fks['erp_centros_costo.auxiliar_id'] = ($fks['erp_centros_costo.auxiliar_id'] ?? 0) + 1;
            } else {
                // Colisión: mover referencias downstream del CC perdedor → CC ganador.
                $n = $this->reasignarReferenciasCc((int) $cc->id, (int) $ccCanonico);
                DB::table('erp_centros_costo')->where('id', $cc->id)->delete();
                $fks['erp_centros_costo.consolidados'] = ($fks['erp_centros_costo.consolidados'] ?? 0) + 1;
                $fks['centro_costo.refs_reasignadas'] = ($fks['centro_costo.refs_reasignadas'] ?? 0) + $n;
            }
        }
        return $fks;
    }

    /** Reasigna todas las columnas centro_costo_id de ccViejo → ccNuevo. */
    private function reasignarReferenciasCc(int $ccViejo, int $ccNuevo): int
    {
        $refs = [
            'erp_af_bienes' => 'centro_costo_id',
            'erp_conciliacion_reglas' => 'centro_costo_id',
            'erp_factura_compra_items' => 'centro_costo_id',
            'erp_factura_venta_items' => 'centro_costo_id',
            'erp_facturas_compra' => 'centro_costo_id',
            'erp_facturas_venta' => 'centro_costo_id',
            'erp_movimientos_asiento' => 'centro_costo_id',
            'erp_presupuesto_items' => 'centro_costo_id',
            'erp_saldos_cuenta' => 'centro_costo_id',
        ];
        $total = 0;
        foreach ($refs as $tabla => $col) {
            $total += DB::table($tabla)->where($col, $ccViejo)->update([$col => $ccNuevo]);
        }
        // af_movimientos tiene 2 columnas.
        $total += DB::table('erp_af_movimientos')->where('cc_nuevo_id', $ccViejo)->update(['cc_nuevo_id' => $ccNuevo]);
        $total += DB::table('erp_af_movimientos')->where('cc_anterior_id', $ccViejo)->update(['cc_anterior_id' => $ccNuevo]);
        return $total;
    }
}

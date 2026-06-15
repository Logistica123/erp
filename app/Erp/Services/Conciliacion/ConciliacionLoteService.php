<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Models\Asiento;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.47 §15 — Conciliación en lote N:M (caso URBANO).
 * N movimientos bancarios ↔ M facturas del mismo auxiliar, un asiento consolidado.
 */
class ConciliacionLoteService
{
    private const TOLERANCIA = 1.00;
    private const ESTADOS_MOV_ELEGIBLE = ['PENDIENTE', 'ETIQUETADO', 'MATCH_AUTO'];
    private const ESTADOS_FV = ['EMITIDA', 'CONTROLADA', 'COBRO_PARCIAL'];
    private const ESTADOS_FC = ['RECIBIDA', 'CONTROLADA', 'OBSERVADA', 'PAGO_PARCIAL'];

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /** Candidatos: movimientos elegibles + facturas pendientes del auxiliar. */
    public function candidatos(int $auxiliarId, ?int $cuentaBancariaId, string $tipoFactura = 'VENTA'): array
    {
        $movQ = DB::table('erp_movimientos_bancarios as m')
            ->leftJoin('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->whereIn('m.estado', self::ESTADOS_MOV_ELEGIBLE)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))->from('erp_conciliacion_lotes_movimientos as lm')->whereColumn('lm.movimiento_bancario_id', 'm.id');
            })
            ->where(fn ($q) => $q->where('m.auxiliar_resuelto_id', $auxiliarId)->orWhereNull('m.auxiliar_resuelto_id'))
            ->orderBy('m.fecha')
            ->select(['m.id', 'm.fecha', 'm.concepto', 'm.debito', 'm.credito', 'm.estado', 'm.auxiliar_resuelto_id', 'cb.nombre as cuenta_nombre']);
        if ($cuentaBancariaId) $movQ->where('m.cuenta_bancaria_id', $cuentaBancariaId);
        $movs = $movQ->limit(300)->get();

        $facturas = $this->facturasPendientes($auxiliarId, $tipoFactura);

        return ['movimientos' => $movs, 'facturas' => $facturas];
    }

    /** @return array<int,array{id:int,numero:string,fecha:string,total:float,saldo:float}> */
    private function facturasPendientes(int $auxiliarId, string $tipoFactura): array
    {
        if ($tipoFactura === 'VENTA') {
            $rows = DB::table('erp_facturas_venta')->where('auxiliar_id', $auxiliarId)
                ->whereIn('estado', self::ESTADOS_FV)->whereNull('deleted_at')
                ->orderBy('fecha_emision')->get(['id', 'numero', 'punto_venta', 'fecha_emision', 'imp_total']);
            $out = [];
            foreach ($rows as $f) {
                $imp = (float) DB::table('erp_recibos_comprobantes_imputados')->where('factura_venta_id', $f->id)->sum('monto_imputado');
                $impLote = (float) DB::table('erp_conciliacion_lotes_facturas as lf')
                    ->join('erp_conciliacion_lotes as l', 'l.id', '=', 'lf.lote_id')
                    ->where('lf.factura_id', $f->id)->where('lf.factura_tipo', 'VENTA')
                    ->where('l.estado', '!=', 'REVERTIDO')->sum('lf.monto_imputado');
                $saldo = round((float) $f->imp_total - $imp - $impLote, 2);
                if ($saldo > 0.005) {
                    $out[] = ['id' => (int) $f->id, 'numero' => sprintf('%04d-%08d', $f->punto_venta, $f->numero),
                        'fecha' => (string) $f->fecha_emision, 'total' => (float) $f->imp_total, 'saldo' => $saldo];
                }
            }
            return $out;
        }
        $rows = DB::table('erp_facturas_compra')->where('auxiliar_id', $auxiliarId)
            ->whereIn('estado', self::ESTADOS_FC)->whereNull('deleted_at')
            ->orderBy('fecha_emision')->get(['id', 'numero', 'punto_venta', 'fecha_emision', 'imp_total']);
        $out = [];
        foreach ($rows as $f) {
            $impLote = (float) DB::table('erp_conciliacion_lotes_facturas as lf')
                ->join('erp_conciliacion_lotes as l', 'l.id', '=', 'lf.lote_id')
                ->where('lf.factura_id', $f->id)->where('lf.factura_tipo', 'COMPRA')
                ->where('l.estado', '!=', 'REVERTIDO')->sum('lf.monto_imputado');
            $saldo = round((float) $f->imp_total - $impLote, 2);
            if ($saldo > 0.005) {
                $out[] = ['id' => (int) $f->id, 'numero' => sprintf('%04d-%08d', $f->punto_venta, $f->numero),
                    'fecha' => (string) $f->fecha_emision, 'total' => (float) $f->imp_total, 'saldo' => $saldo];
            }
        }
        return $out;
    }

    /**
     * @param  array{auxiliar_id:int, cuenta_bancaria_id:int, signo:string,
     *   movimientos:array<int,int>, facturas:array<int,array{id:int,tipo:string,monto:float}>,
     *   observaciones?:?string, motivo_diferencia?:?string, cuenta_ajuste_id?:?int, usuario_id:int}  $data
     */
    public function crear(array $data): int
    {
        $movIds = array_values(array_unique(array_map('intval', $data['movimientos'] ?? [])));
        $facturas = $data['facturas'] ?? [];
        if (empty($movIds)) throw new DomainException('SIN_MOVIMIENTOS');
        if (empty($facturas)) throw new DomainException('SIN_FACTURAS');

        // Validar que ningún mov ya esté en un lote.
        $yaEnLote = DB::table('erp_conciliacion_lotes_movimientos')->whereIn('movimiento_bancario_id', $movIds)->pluck('movimiento_bancario_id')->all();
        if (! empty($yaEnLote)) throw new DomainException('MOV_YA_EN_LOTE: ' . implode(',', $yaEnLote));

        $movs = MovimientoBancario::whereIn('id', $movIds)->get();
        $sumaMovs = round($movs->sum(fn ($m) => (float) max($m->debito, $m->credito)), 2);
        $sumaFacturas = round(collect($facturas)->sum(fn ($f) => (float) $f['monto']), 2);
        $diff = round($sumaMovs - $sumaFacturas, 2);

        if (abs($diff) > self::TOLERANCIA) {
            if (empty($data['motivo_diferencia']) || empty($data['cuenta_ajuste_id'])) {
                throw new DomainException(sprintf('DIFERENCIA_NO_PERMITIDA: movs $%.2f vs facturas $%.2f (dif $%.2f). Requiere motivo + cuenta de ajuste.', $sumaMovs, $sumaFacturas, $diff));
            }
        }

        return DB::transaction(function () use ($data, $movIds, $movs, $facturas, $sumaMovs, $diff) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);
            $fecha = $movs->max('fecha') ?? now()->toDateString();
            $codigo = $this->siguienteCodigo(Carbon::parse($fecha));

            $loteId = DB::table('erp_conciliacion_lotes')->insertGetId([
                'codigo' => $codigo,
                'auxiliar_id' => (int) $data['auxiliar_id'],
                'cuenta_bancaria_id' => (int) $data['cuenta_bancaria_id'],
                'fecha' => Carbon::parse($fecha)->toDateString(),
                'monto_total' => $sumaMovs,
                'signo' => $data['signo'],
                'estado' => 'BORRADOR',
                'observaciones' => $data['observaciones'] ?? null,
                'motivo_diferencia' => abs($diff) > self::TOLERANCIA ? ($data['motivo_diferencia'] ?? null) : null,
                'cuenta_ajuste_id' => abs($diff) > self::TOLERANCIA ? ($data['cuenta_ajuste_id'] ?? null) : null,
                'created_by' => $data['usuario_id'],
                'created_at' => now(), 'updated_at' => now(),
            ]);

            foreach ($movs as $m) {
                DB::table('erp_conciliacion_lotes_movimientos')->insert([
                    'lote_id' => $loteId, 'movimiento_bancario_id' => $m->id,
                    'monto' => (float) max($m->debito, $m->credito),
                ]);
            }
            MovimientoBancario::whereIn('id', $movIds)->update(['estado' => 'EN_LOTE']);

            foreach ($facturas as $f) {
                DB::table('erp_conciliacion_lotes_facturas')->insert([
                    'lote_id' => $loteId, 'factura_id' => (int) $f['id'],
                    'factura_tipo' => $f['tipo'], 'monto_imputado' => round((float) $f['monto'], 2),
                ]);
            }

            $this->audit->logEvento(accion: 'LOTE_CONCILIACION_CREADO', modulo: 'tesoreria',
                descripcion: "Lote {$codigo} creado (BORRADOR): " . count($movIds) . " movs, " . count($facturas) . " facturas, total \$" . number_format($sumaMovs, 2));
            return $loteId;
        });
    }

    public function detalle(int $loteId): array
    {
        $lote = DB::table('erp_conciliacion_lotes as l')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'l.auxiliar_id')
            ->leftJoin('erp_cuentas_bancarias as cb', 'cb.id', '=', 'l.cuenta_bancaria_id')
            ->where('l.id', $loteId)
            ->select(['l.*', 'a.nombre as auxiliar_nombre', 'cb.nombre as cuenta_nombre'])->first();
        if (! $lote) throw new DomainException('LOTE_NO_ENCONTRADO');
        $movs = DB::table('erp_conciliacion_lotes_movimientos as lm')
            ->join('erp_movimientos_bancarios as m', 'm.id', '=', 'lm.movimiento_bancario_id')
            ->where('lm.lote_id', $loteId)
            ->select(['m.id', 'm.fecha', 'm.concepto', 'lm.monto', 'm.estado'])->get();
        $facturas = DB::table('erp_conciliacion_lotes_facturas')->where('lote_id', $loteId)->get();
        return ['lote' => $lote, 'movimientos' => $movs, 'facturas' => $facturas];
    }

    public function confirmar(int $loteId, User $usuario): void
    {
        $lote = DB::table('erp_conciliacion_lotes')->where('id', $loteId)->first();
        if (! $lote) throw new DomainException('LOTE_NO_ENCONTRADO');
        if ($lote->estado !== 'BORRADOR') throw new DomainException("ESTADO_INVALIDO: lote {$lote->estado}");

        DB::transaction(function () use ($lote, $loteId, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);

            $cuentaBanco = DB::table('erp_cuentas_bancarias')->where('id', $lote->cuenta_bancaria_id)->first();
            $empresaId = (int) $cuentaBanco->empresa_id;
            $cuentaAux = DB::table('erp_auxiliares')->where('id', $lote->auxiliar_id)->value('cuenta_contable_default_id');
            $esCobro = $lote->signo === '+';
            if (! $cuentaAux) {
                $cuentaAux = DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)
                    ->where('codigo', $esCobro ? '1.1.4.01' : '2.1.1.01')->value('id');
            }
            $diarioBan = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'BAN')->value('id')
                ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');

            $monto = (float) $lote->monto_total;
            $glosa = "Conciliación en lote {$lote->codigo}";
            $movsAsiento = $esCobro
                ? [
                    ['cuenta_id' => $cuentaBanco->cuenta_contable_id, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa],
                    ['cuenta_id' => $cuentaAux, 'auxiliar_id' => $lote->auxiliar_id, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa],
                ]
                : [
                    ['cuenta_id' => $cuentaAux, 'auxiliar_id' => $lote->auxiliar_id, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa],
                    ['cuenta_id' => $cuentaBanco->cuenta_contable_id, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa],
                ];

            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => $empresaId, 'diario_id' => $diarioBan, 'fecha' => $lote->fecha,
                'glosa' => $glosa, 'origen' => 'BANCO', 'origen_tabla' => 'erp_conciliacion_lotes', 'origen_id' => $loteId,
                'usuario_id' => $usuario->id, 'movimientos' => $movsAsiento,
            ]);
            $asiento = $this->asientoService->contabilizar($asiento);

            // Movs → CONFIRMADO_EN_LOTE. Facturas → IMPUTADA_EN_LOTE.
            $movIds = DB::table('erp_conciliacion_lotes_movimientos')->where('lote_id', $loteId)->pluck('movimiento_bancario_id')->all();
            MovimientoBancario::whereIn('id', $movIds)->update(['estado' => 'CONFIRMADO_EN_LOTE', 'asiento_id' => $asiento->id]);
            foreach (DB::table('erp_conciliacion_lotes_facturas')->where('lote_id', $loteId)->get() as $lf) {
                $tabla = $lf->factura_tipo === 'VENTA' ? 'erp_facturas_venta' : 'erp_facturas_compra';
                DB::table($tabla)->where('id', $lf->factura_id)->update(['estado' => 'IMPUTADA_EN_LOTE']);
            }

            DB::table('erp_conciliacion_lotes')->where('id', $loteId)->update([
                'estado' => 'CONFIRMADO', 'asiento_id' => $asiento->id,
                'confirmed_by' => $usuario->id, 'confirmed_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->logEvento(accion: 'LOTE_CONCILIACION_CONFIRMADO', modulo: 'tesoreria',
                descripcion: "Lote {$lote->codigo} confirmado · asiento #{$asiento->id}", empresaId: $empresaId);
        });
    }

    public function revertir(int $loteId, string $motivo, User $usuario): void
    {
        $lote = DB::table('erp_conciliacion_lotes')->where('id', $loteId)->first();
        if (! $lote) throw new DomainException('LOTE_NO_ENCONTRADO');
        if ($lote->estado !== 'CONFIRMADO') throw new DomainException("ESTADO_INVALIDO: solo se revierte CONFIRMADO (actual {$lote->estado}).");
        if (strlen(trim($motivo)) < 10) throw new DomainException('MOTIVO_CORTO: mínimo 10 caracteres.');

        DB::transaction(function () use ($lote, $loteId, $motivo, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);
            if ($lote->asiento_id) {
                $asiento = Asiento::find($lote->asiento_id);
                if ($asiento && $asiento->estado === Asiento::ESTADO_CONTABILIZADO) {
                    $this->asientoService->anular($asiento, $usuario->id, "Reversión lote {$lote->codigo}: {$motivo}");
                }
            }
            // Movs → PENDIENTE (invariante explícita §15.7). Facturas → estado pendiente.
            $movIds = DB::table('erp_conciliacion_lotes_movimientos')->where('lote_id', $loteId)->pluck('movimiento_bancario_id')->all();
            MovimientoBancario::whereIn('id', $movIds)->update(['estado' => 'PENDIENTE', 'asiento_id' => null]);
            foreach (DB::table('erp_conciliacion_lotes_facturas')->where('lote_id', $loteId)->get() as $lf) {
                $tabla = $lf->factura_tipo === 'VENTA' ? 'erp_facturas_venta' : 'erp_facturas_compra';
                $estadoRestaurado = $lf->factura_tipo === 'VENTA' ? 'EMITIDA' : 'RECIBIDA';
                DB::table($tabla)->where('id', $lf->factura_id)->where('estado', 'IMPUTADA_EN_LOTE')
                    ->update(['estado' => $estadoRestaurado]);
            }
            DB::table('erp_conciliacion_lotes')->where('id', $loteId)->update([
                'estado' => 'REVERTIDO', 'motivo_reversion' => $motivo,
                'reverted_by' => $usuario->id, 'reverted_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->logEvento(accion: 'LOTE_CONCILIACION_REVERTIDO', modulo: 'tesoreria',
                descripcion: "Lote {$lote->codigo} revertido: {$motivo}");
        });
    }

    public function borrar(int $loteId, User $usuario): void
    {
        $lote = DB::table('erp_conciliacion_lotes')->where('id', $loteId)->first();
        if (! $lote) throw new DomainException('LOTE_NO_ENCONTRADO');
        if ($lote->estado !== 'BORRADOR') throw new DomainException('ESTADO_INVALIDO: solo se borra un lote en BORRADOR (confirmado → revertir).');

        DB::transaction(function () use ($lote, $loteId, $usuario) {
            DB::statement('SET @erp_current_user_id = ?', [$usuario->id]);
            $movIds = DB::table('erp_conciliacion_lotes_movimientos')->where('lote_id', $loteId)->pluck('movimiento_bancario_id')->all();
            MovimientoBancario::whereIn('id', $movIds)->where('estado', 'EN_LOTE')->update(['estado' => 'PENDIENTE']);
            DB::table('erp_conciliacion_lotes')->where('id', $loteId)->delete(); // cascade borra hijos
            $this->audit->logEvento(accion: 'LOTE_CONCILIACION_BORRADO', modulo: 'tesoreria',
                descripcion: "Lote {$lote->codigo} (BORRADOR) borrado");
        });
    }

    public function listar(array $filtros): array
    {
        $q = DB::table('erp_conciliacion_lotes as l')
            ->leftJoin('erp_auxiliares as a', 'a.id', '=', 'l.auxiliar_id')
            ->leftJoin('erp_cuentas_bancarias as cb', 'cb.id', '=', 'l.cuenta_bancaria_id')
            ->select(['l.*', 'a.nombre as auxiliar_nombre', 'cb.nombre as cuenta_nombre'])
            ->orderByDesc('l.fecha')->orderByDesc('l.id');
        if (! empty($filtros['estado'])) $q->where('l.estado', $filtros['estado']);
        if (! empty($filtros['auxiliar_id'])) $q->where('l.auxiliar_id', (int) $filtros['auxiliar_id']);
        return $q->paginate((int) ($filtros['per_page'] ?? 50))->toArray();
    }

    private function siguienteCodigo(Carbon $fecha): string
    {
        $prefijo = 'LOTE-' . $fecha->format('Y-m') . '-';
        $ultimo = DB::table('erp_conciliacion_lotes')->where('codigo', 'like', $prefijo . '%')
            ->orderByDesc('codigo')->value('codigo');
        $n = $ultimo ? ((int) substr($ultimo, -4)) + 1 : 1;
        return $prefijo . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
    }
}

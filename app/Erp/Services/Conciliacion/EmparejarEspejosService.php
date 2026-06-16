<?php

namespace App\Erp\Services\Conciliacion;

use App\Erp\Services\AsientoService;
use App\Erp\Models\Tesoreria\Conciliacion;
use Illuminate\Support\Facades\DB;
use DomainException;

/**
 * Bloque B v1.48 — empareja movimientos marcados como transferencia interna
 * (CUIT propio detectado en la importación) buscando su espejo de signo opuesto
 * en otra cuenta bancaria. Al encontrar exactamente uno genera el asiento
 * banco→banco y deja ambos en CONFIRMADO_TRANSF_INTERNA.
 *
 * Adaptado al schema real: no hay columna `importe` con signo; se usa
 * debito/credito. El lado crédito es el banco destino (entró plata), el lado
 * débito es el origen (salió plata).
 */
class EmparejarEspejosService
{
    public const TOLERANCIA_DIAS = 3;

    /** @return array{emparejados:int,ambiguos:int,sin_espejo:int} */
    public function emparejarEspejos(?int $empresaId = null, ?int $userId = null): array
    {
        $emparejados = 0; $ambiguos = 0; $sinEspejo = 0;

        $candidatos = DB::table('erp_movimientos_bancarios as m')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->where('m.es_transferencia_interna', 1)
            ->where('m.estado', 'PENDIENTE_TRANSF_INTERNA')
            ->whereNull('m.mov_espejo_id')
            ->when($empresaId, fn ($q) => $q->where('cb.empresa_id', $empresaId))
            ->orderBy('m.id')
            ->get(['m.id', 'm.cuenta_bancaria_id', 'm.fecha', 'm.debito', 'm.credito', 'cb.empresa_id']);

        $procesados = [];
        foreach ($candidatos as $mov) {
            if (isset($procesados[$mov->id])) continue;

            $monto = round((float) max($mov->debito, $mov->credito), 2);
            // El espejo tiene el signo opuesto: si este movió débito, el espejo
            // tiene crédito por el mismo monto (y viceversa).
            $movEsDebito = (float) $mov->debito > 0.005;

            $desde = date('Y-m-d', strtotime($mov->fecha . ' -' . self::TOLERANCIA_DIAS . ' days'));
            $hasta = date('Y-m-d', strtotime($mov->fecha . ' +' . self::TOLERANCIA_DIAS . ' days'));

            $espejos = DB::table('erp_movimientos_bancarios as m')
                ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
                ->where('m.es_transferencia_interna', 1)
                ->where('m.estado', 'PENDIENTE_TRANSF_INTERNA')
                ->whereNull('m.mov_espejo_id')
                ->where('m.id', '!=', $mov->id)
                ->where('m.cuenta_bancaria_id', '!=', $mov->cuenta_bancaria_id)
                ->where('cb.empresa_id', $mov->empresa_id)
                ->when($movEsDebito,
                    fn ($q) => $q->whereRaw('ABS(m.credito - ?) < 0.005', [$monto]),
                    fn ($q) => $q->whereRaw('ABS(m.debito - ?) < 0.005', [$monto]))
                ->whereBetween(DB::raw('DATE(m.fecha)'), [$desde, $hasta])
                ->get(['m.id', 'm.cuenta_bancaria_id', 'm.fecha', 'm.debito', 'm.credito']);

            if ($espejos->count() === 1) {
                $espejo = $espejos[0];
                DB::transaction(function () use ($mov, $espejo, $monto, $movEsDebito, $userId) {
                    // Banco destino = el que recibió (crédito); banco origen = el que pagó (débito).
                    $movDestino  = $movEsDebito ? $espejo : $mov;     // el de crédito
                    $movOrigen   = $movEsDebito ? $mov : $espejo;     // el de débito
                    $this->generarAsiento($mov, $movOrigen, $movDestino, $monto, $userId);

                    DB::table('erp_movimientos_bancarios')->where('id', $mov->id)
                        ->update(['mov_espejo_id' => $espejo->id, 'estado' => 'CONFIRMADO_TRANSF_INTERNA']);
                    DB::table('erp_movimientos_bancarios')->where('id', $espejo->id)
                        ->update(['mov_espejo_id' => $mov->id, 'estado' => 'CONFIRMADO_TRANSF_INTERNA']);
                });
                $procesados[$mov->id] = true;
                $procesados[$espejo->id] = true;
                $emparejados++;
            } elseif ($espejos->count() > 1) {
                $ambiguos++; // queda PENDIENTE_TRANSF_INTERNA para resolución manual
            } else {
                $sinEspejo++;
            }
        }

        return ['emparejados' => $emparejados, 'ambiguos' => $ambiguos, 'sin_espejo' => $sinEspejo];
    }

    /**
     * Empareja manualmente dos movimientos (pantalla de ambigüedades).
     */
    public function emparejarManual(int $movId, int $espejoId, ?int $userId = null): void
    {
        $mov = DB::table('erp_movimientos_bancarios as m')
            ->join('erp_cuentas_bancarias as cb', 'cb.id', '=', 'm.cuenta_bancaria_id')
            ->where('m.id', $movId)
            ->first(['m.id', 'm.cuenta_bancaria_id', 'm.fecha', 'm.debito', 'm.credito', 'm.estado', 'cb.empresa_id']);
        $espejo = DB::table('erp_movimientos_bancarios')
            ->where('id', $espejoId)
            ->first(['id', 'cuenta_bancaria_id', 'fecha', 'debito', 'credito', 'estado']);

        if (! $mov || ! $espejo) {
            throw new DomainException('MOV_INEXISTENTE');
        }
        if ($mov->estado !== 'PENDIENTE_TRANSF_INTERNA' || $espejo->estado !== 'PENDIENTE_TRANSF_INTERNA') {
            throw new DomainException('ESTADO_INVALIDO: ambos movimientos deben estar PENDIENTE_TRANSF_INTERNA.');
        }
        if ($mov->cuenta_bancaria_id === $espejo->cuenta_bancaria_id) {
            throw new DomainException('MISMA_CUENTA: el espejo debe ser de otra cuenta bancaria.');
        }

        $monto = round((float) max($mov->debito, $mov->credito), 2);
        $movEsDebito = (float) $mov->debito > 0.005;

        DB::transaction(function () use ($mov, $espejo, $monto, $movEsDebito, $userId) {
            $movDestino = $movEsDebito ? $espejo : $mov;
            $movOrigen  = $movEsDebito ? $mov : $espejo;
            $this->generarAsiento($mov, $movOrigen, $movDestino, $monto, $userId);
            DB::table('erp_movimientos_bancarios')->where('id', $mov->id)
                ->update(['mov_espejo_id' => $espejo->id, 'estado' => 'CONFIRMADO_TRANSF_INTERNA']);
            DB::table('erp_movimientos_bancarios')->where('id', $espejo->id)
                ->update(['mov_espejo_id' => $mov->id, 'estado' => 'CONFIRMADO_TRANSF_INTERNA']);
        });
    }

    /**
     * Marca un movimiento como NO transferencia interna: vuelve a PENDIENTE.
     */
    public function descartarTransferenciaInterna(int $movId): void
    {
        $mov = DB::table('erp_movimientos_bancarios')->where('id', $movId)->first(['id', 'estado', 'mov_espejo_id']);
        if (! $mov) throw new DomainException('MOV_INEXISTENTE');
        if (! in_array($mov->estado, ['PENDIENTE_TRANSF_INTERNA'], true)) {
            throw new DomainException('ESTADO_INVALIDO: solo PENDIENTE_TRANSF_INTERNA puede descartarse.');
        }
        DB::table('erp_movimientos_bancarios')->where('id', $movId)->update([
            'es_transferencia_interna' => 0,
            'mov_espejo_id' => null,
            'estado' => 'PENDIENTE',
        ]);
    }

    /**
     * D: banco destino (recibió)   $monto
     * H: banco origen (pagó)       $monto
     */
    private function generarAsiento(object $movRef, object $movOrigen, object $movDestino, float $monto, ?int $userId = null): void
    {
        $empresaId = (int) (DB::table('erp_cuentas_bancarias')->where('id', $movDestino->cuenta_bancaria_id)->value('empresa_id') ?: 1);
        $userId = $userId ?? (int) (auth()->id() ?: DB::table('users')->min('id'));
        if ($userId) DB::statement('SET @erp_current_user_id = ?', [$userId]);

        $ctaDestino = DB::table('erp_cuentas_bancarias')->where('id', $movDestino->cuenta_bancaria_id)->value('cuenta_contable_id');
        $ctaOrigen  = DB::table('erp_cuentas_bancarias')->where('id', $movOrigen->cuenta_bancaria_id)->value('cuenta_contable_id');
        if (! $ctaDestino || ! $ctaOrigen) {
            throw new DomainException('CUENTA_CONTABLE_BANCARIA_INEXISTENTE: vinculá ambas cuentas bancarias a su cuenta contable.');
        }

        $diarioBan = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'BAN')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');
        if (! $diarioBan) {
            throw new DomainException('DIARIO_BAN_INEXISTENTE: creá un diario con código BAN o GEN.');
        }

        $glosa = "Transferencia interna mov #{$movOrigen->id} → #{$movDestino->id}";
        $asientoSvc = app(AsientoService::class);
        $asiento = $asientoSvc->crearBorrador([
            'empresa_id' => $empresaId,
            'diario_id'  => $diarioBan,
            'fecha'      => $movDestino->fecha,
            'glosa'      => $glosa,
            'origen'     => 'BANCO',
            'origen_tabla' => 'erp_movimientos_bancarios',
            'origen_id'  => $movDestino->id,
            'usuario_id' => $userId,
            'movimientos' => [
                ['cuenta_id' => (int) $ctaDestino, 'auxiliar_id' => null, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => (int) $ctaOrigen,  'auxiliar_id' => null, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa],
            ],
        ]);
        $asiento = $asientoSvc->contabilizar($asiento);

        foreach ([$movOrigen->id, $movDestino->id] as $mid) {
            Conciliacion::create([
                'movimiento_bancario_id' => $mid,
                'referencia_tipo' => 'TRANSFERENCIA_INTERNA',
                'referencia_id'   => $asiento->id,
                'importe_conciliado' => $monto,
                'user_id' => $userId,
                'modo' => 'AUTO',
                'observacion' => $glosa,
            ]);
        }
    }
}

<?php

namespace App\Erp\Services\Cierres;

use App\Erp\Models\Asiento;
use App\Erp\Models\MovimientoAsiento;
use App\Erp\Models\Periodo;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Models\Tesoreria\MovimientoBancario;
use App\Erp\Models\Tesoreria\TransferenciaInterna;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Detector de transferencias internas cross-banco (RN-CD-8 del anexo).
 *
 * Busca pares de movimientos de un mismo día que cumplen:
 *   - Mismo importe absoluto.
 *   - Signos opuestos (uno débito, el otro crédito).
 *   - Distinta cuenta_bancaria_id.
 *   - Ambas cuentas son propias (mismo empresa_id).
 *   - Ninguno está CONCILIADO/IGNORADO previamente.
 *
 * Por cada par encontrado:
 *   1. Inserta erp_transferencias_internas (CONCILIADA).
 *   2. Genera asiento DEBE cta_contable_destino / HABER cta_contable_origen.
 *   3. Estampa los 2 movs como CONCILIADO con asiento_id.
 *
 * Idempotente: re-correr el mismo día no genera duplicados (los movs ya
 * estarían en estado CONCILIADO y se filtran).
 */
class DetectorTransferenciasInternasService
{
    public function detectarYConciliar(Carbon $fecha, int $empresaId, ?User $usuario = null): array
    {
        $cuentasIds = CuentaBancaria::where('empresa_id', $empresaId)
            ->where('activo', 1)
            ->pluck('id')->all();
        if (count($cuentasIds) < 2) {
            return ['pares' => 0, 'transferencias' => []];
        }

        // Movs candidatos del día, cuentas propias, no conciliados aún.
        $movs = MovimientoBancario::with(['cuentaBancaria:id,empresa_id,cuenta_contable_id,moneda_id,codigo,nombre'])
            ->whereIn('cuenta_bancaria_id', $cuentasIds)
            ->whereDate('fecha', $fecha->toDateString())
            ->whereIn('estado', ['PENDIENTE', 'ETIQUETADO'])
            ->get();
        if ($movs->count() < 2) {
            return ['pares' => 0, 'transferencias' => []];
        }

        // Index por importe absoluto para hacer matching O(n).
        $byImporte = [];
        foreach ($movs as $m) {
            $imp = (float) $m->debito > 0 ? (float) $m->debito : (float) $m->credito;
            if ($imp <= 0) continue;
            $key = number_format($imp, 2, '.', '');
            $byImporte[$key][] = $m;
        }

        $userId = $usuario?->id ?? 1;
        $transferencias = [];
        $pares = 0;

        DB::transaction(function () use ($byImporte, $empresaId, $fecha, $userId, &$pares, &$transferencias) {
            foreach ($byImporte as $candidatos) {
                if (count($candidatos) < 2) continue;

                // Buscar pares (debe vs haber) por orden de importe.
                $usados = [];
                $debitos = array_filter($candidatos, fn ($m) => (float) $m->debito > 0);
                $creditos = array_filter($candidatos, fn ($m) => (float) $m->credito > 0);

                foreach ($debitos as $d) {
                    if (in_array($d->id, $usados, true)) continue;
                    foreach ($creditos as $c) {
                        if (in_array($c->id, $usados, true)) continue;
                        if ($d->cuenta_bancaria_id === $c->cuenta_bancaria_id) continue;

                        // Match: $d sale (cuenta origen) → $c entra (cuenta destino)
                        $tr = $this->generarTransferencia($d, $c, $empresaId, $fecha, $userId);
                        $transferencias[] = $tr->id;
                        $usados[] = $d->id;
                        $usados[] = $c->id;
                        $pares++;
                        break;
                    }
                }
            }
        });

        return ['pares' => $pares, 'transferencias' => $transferencias];
    }

    private function generarTransferencia(MovimientoBancario $debe, MovimientoBancario $haber, int $empresaId, Carbon $fecha, int $userId): TransferenciaInterna
    {
        $importe = (float) $debe->debito;

        // Asiento contable: DEBE cuenta_contable de cuenta destino (ingresa $)
        //                    HABER cuenta_contable de cuenta origen (sale $)
        $asiento = $this->crearAsientoTransferenciaInterna(
            $fecha, $importe,
            (int) $haber->cuentaBancaria->cuenta_contable_id,  // destino → DEBE
            (int) $debe->cuentaBancaria->cuenta_contable_id,   // origen  → HABER
            $debe, $haber, $userId, $empresaId
        );

        $tr = TransferenciaInterna::create([
            'empresa_id'             => $empresaId,
            'numero'                 => $this->proximoNumero($empresaId),
            'fecha'                  => $fecha->toDateString(),
            'cuenta_origen_id'       => $debe->cuenta_bancaria_id,
            'cuenta_destino_id'      => $haber->cuenta_bancaria_id,
            'moneda_origen_id'       => (int) $debe->cuentaBancaria->moneda_id,
            'moneda_destino_id'      => (int) $haber->cuentaBancaria->moneda_id,
            'importe_origen'         => $importe,
            'importe_destino'        => $importe,
            'tipo_cambio'            => 1,
            'estado'                 => TransferenciaInterna::ESTADO_CONCILIADA,
            'movimiento_origen_id'   => $debe->id,
            'movimiento_destino_id'  => $haber->id,
            'asiento_id'             => $asiento->id,
            'concepto'               => sprintf('TRF interna detectada: %s → %s ($%s)',
                                            $debe->cuentaBancaria->codigo, $haber->cuentaBancaria->codigo, number_format($importe, 2)),
            'creado_por_user_id'     => $userId,
        ]);

        // Marcar ambos movimientos como CONCILIADO con asiento_id.
        MovimientoBancario::whereIn('id', [$debe->id, $haber->id])
            ->update([
                'estado'     => 'CONCILIADO',
                'asiento_id' => $asiento->id,
                'updated_at' => now(),
            ]);

        return $tr;
    }

    private function crearAsientoTransferenciaInterna(
        Carbon $fecha, float $importe,
        int $cuentaDebeId, int $cuentaHaberId,
        MovimientoBancario $movOrigen, MovimientoBancario $movDestino,
        int $userId, int $empresaId
    ): Asiento {
        $periodo = Periodo::where('fecha_inicio', '<=', $fecha->toDateString())
            ->where('fecha_fin', '>=', $fecha->toDateString())
            ->where('estado', '!=', 'CERRADO')
            ->orderByDesc('fecha_inicio')->first();
        if (! $periodo) {
            throw new DomainException('PERIODO_NO_ENCONTRADO: no hay período abierto que contenga '.$fecha->toDateString());
        }

        $asiento = Asiento::create([
            'empresa_id'    => $empresaId,
            'ejercicio_id'  => (int) $periodo->ejercicio_id,
            'periodo_id'    => (int) $periodo->id,
            'diario_id'     => 5, // BAN — Bancos
            'numero'        => $this->proximoNumeroAsiento($empresaId),
            'fecha'         => $fecha->toDateString(),
            'glosa'         => sprintf('Transferencia interna detectada %s → %s', $movOrigen->cuentaBancaria->codigo, $movDestino->cuentaBancaria->codigo),
            'origen'        => 'BANCO',
            'origen_id'     => $movOrigen->id,
            'origen_tabla'  => 'erp_movimientos_bancarios',
            'estado'        => 'BORRADOR',
            'moneda_base'   => 'ARS',
            'total_debe'    => 0,
            'total_haber'   => 0,
            'usuario_id'    => $userId,
        ]);

        $req = $this->cuentasQueRequierenCC([$cuentaDebeId, $cuentaHaberId]);
        $ccDefault = $this->ccDefault();

        MovimientoAsiento::create([
            'asiento_id'      => $asiento->id, 'linea' => 1,
            'cuenta_id'       => $cuentaDebeId,
            'centro_costo_id' => in_array($cuentaDebeId, $req, true) ? $ccDefault : null,
            'debe'            => $importe, 'haber' => 0, 'moneda' => 'ARS',
        ]);
        MovimientoAsiento::create([
            'asiento_id'      => $asiento->id, 'linea' => 2,
            'cuenta_id'       => $cuentaHaberId,
            'centro_costo_id' => in_array($cuentaHaberId, $req, true) ? $ccDefault : null,
            'debe'            => 0, 'haber' => $importe, 'moneda' => 'ARS',
        ]);

        $asiento->update([
            'total_debe'  => $importe,
            'total_haber' => $importe,
            'estado'      => 'CONTABILIZADO',
            'fecha_contabilizacion' => now(),
        ]);

        return $asiento;
    }

    private function ccDefault(): int
    {
        $id = DB::table('erp_centros_costo')->where('empresa_id', 1)->where('activo', 1)
            ->orderBy('id')->value('id');
        if (! $id) {
            throw new DomainException('CC_DEFAULT_NO_ENCONTRADO');
        }
        return (int) $id;
    }

    /** @param int[] $cuentaIds @return int[] */
    private function cuentasQueRequierenCC(array $cuentaIds): array
    {
        if (empty($cuentaIds)) return [];
        return DB::table('erp_cuentas_contables')
            ->whereIn('id', $cuentaIds)->where('admite_cc', 1)
            ->pluck('id')->map(fn ($v) => (int) $v)->all();
    }

    private function proximoNumero(int $empresaId): string
    {
        $row = DB::table('erp_transferencias_internas')->where('empresa_id', $empresaId)->orderByDesc('id')->first(['numero']);
        $n = $row ? ((int) preg_replace('/\D/', '', (string) $row->numero) + 1) : 1;
        return 'TI-'.str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }

    private function proximoNumeroAsiento(int $empresaId): string
    {
        $row = DB::table('erp_asientos')->where('empresa_id', $empresaId)->orderByDesc('id')->limit(1)->first(['numero']);
        $n = $row ? ((int) preg_replace('/\D/', '', (string) $row->numero) + 1) : 1;
        return str_pad((string) $n, 8, '0', STR_PAD_LEFT);
    }
}

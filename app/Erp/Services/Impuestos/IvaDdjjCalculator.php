<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\IvaDdjj;
use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Calcula la DDJJ IVA F.2002 a partir del libro IVA del período (H1) +
 * percepciones sufridas (H2) + arrastre del saldo a favor del período
 * anterior (RN-51).
 *
 *   débito_fiscal      = sum(iva_*) del libro de ventas
 *   crédito_fiscal     = sum(iva_*) del libro de compras
 *   saldo_técnico      = débito - crédito                (puede ser negativo)
 *   percepciones_suf.  = SUM(erp_percepciones_sufridas WHERE tipo='IVA')
 *   retenciones_suf.   = libro_iva_compras.retenciones_iva_sufridas (H3)
 *   saldo_libre_disp_anterior = último saldo_libre_disp_final del período IVA
 *                              cerrado anterior (RN-51, llamado vía
 *                              PeriodoFiscalService::saldoLibreDispAnterior).
 *
 * Si saldo_técnico ≥ 0 (a pagar):
 *   importe_a_pagar           = max(0, saldo_técnico - percepciones - retenciones - pagos_a_cuenta - saldo_libre_disp_anterior)
 *   saldo_libre_disp_final    = max(0, percepciones+retenciones+pagos_a_cuenta+saldo_libre_disp_anterior - saldo_técnico)
 *
 * Si saldo_técnico < 0 (a favor):
 *   saldo_libre_disp_final    = |saldo_técnico| + percepciones + retenciones + pagos_a_cuenta + saldo_libre_disp_anterior
 *   importe_a_pagar           = 0
 *
 * Idempotente: se persiste con updateOrCreate sobre `erp_iva_ddjj` (UNIQUE
 * por periodo_id).
 */
class IvaDdjjCalculator
{
    public function __construct(
        private readonly PeriodoFiscalService $periodoService,
        private readonly PercepcionesSufridasService $percepciones,
        private readonly LibroIvaService $libroIva,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Calcula y persiste la DDJJ. Si el período no es editable, lanza error.
     * `pagos_a_cuenta` es opcional (override manual).
     */
    public function calcular(PeriodoFiscal $periodo, User $usuario, float $pagosACuenta = 0.0): IvaDdjj
    {
        if ($periodo->impuesto !== 'IVA') {
            throw new DomainException('IVA_DDJJ_PERIODO_INVALIDO: solo períodos IVA');
        }
        if (! $periodo->esEditable()) {
            throw new DomainException(
                "IVA_DDJJ_PERIODO_NO_EDITABLE: estado actual {$periodo->estado}"
            );
        }

        return DB::transaction(function () use ($periodo, $usuario, $pagosACuenta) {
            // Asegurar libro IVA al día.
            $this->libroIva->armar($periodo, $usuario);
            $this->percepciones->recalcular($periodo);

            $cabeceraVentas  = $periodo->fresh()->libroIvaVentas;
            $cabeceraCompras = $periodo->fresh()->libroIvaCompras;

            $debito  = (float) ($cabeceraVentas->iva_21
                + $cabeceraVentas->iva_10_5
                + $cabeceraVentas->iva_27
                + $cabeceraVentas->iva_5
                + $cabeceraVentas->iva_2_5);

            $credito = (float) ($cabeceraCompras->iva_21
                + $cabeceraCompras->iva_10_5
                + $cabeceraCompras->iva_27
                + $cabeceraCompras->iva_5
                + $cabeceraCompras->iva_2_5);

            $saldoTec = round($debito - $credito, 2);

            $percIva = (float) ($this->percepciones->totales($periodo)['IVA'] ?? 0);
            $retIva  = (float) $cabeceraCompras->retenciones_iva_sufridas;
            $saldoAnt = $this->periodoService->saldoLibreDispAnterior(
                $periodo->empresa_id, $periodo->anio, $periodo->mes
            );

            $aFavor = $percIva + $retIva + $pagosACuenta + $saldoAnt;
            if ($saldoTec >= 0) {
                $importeAPagar = max(0, round($saldoTec - $aFavor, 2));
                $saldoLDFinal  = round(max(0, $aFavor - $saldoTec), 2);
            } else {
                $importeAPagar = 0.0;
                $saldoLDFinal  = round(abs($saldoTec) + $aFavor, 2);
            }

            $ddjj = IvaDdjj::updateOrCreate(
                ['periodo_id' => $periodo->id],
                [
                    'debito_fiscal'             => $debito,
                    'credito_fiscal'            => $credito,
                    'saldo_tecnico'             => $saldoTec,
                    'saldo_libre_disp_anterior' => $saldoAnt,
                    'retenciones_sufridas'      => $retIva,
                    'percepciones_sufridas'     => $percIva,
                    'pagos_a_cuenta'            => $pagosACuenta,
                    'saldo_libre_disp_final'    => $saldoLDFinal,
                    'importe_a_pagar'           => $importeAPagar,
                    // Conservamos archivo previo si existe — se reemplaza al regenerar F.2002.
                ]
            );

            $this->audit->log('calcular_iva_ddjj', $periodo, null, [
                'debito' => $debito, 'credito' => $credito, 'saldo_tec' => $saldoTec,
                'a_pagar' => $importeAPagar, 'saldo_ld_final' => $saldoLDFinal,
            ], "IVA F.2002 calc {$periodo->anio}/{$periodo->mes} (user #{$usuario->id})");

            return $ddjj->fresh();
        });
    }
}

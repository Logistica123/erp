<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;

/**
 * Orquestador que arma el "paquete EECC" en JSON. Cada componente se
 * incluye según el array `incluir` (BG, ER, EPN, EFE, NOTAS).
 *
 * Verificación previa (RN-59): si el ejercicio no cierra (BG no balancea
 * o hay asientos en BORRADOR), se incluye `estado.cierra=false` con
 * detalle. El consumidor (PDF, controller) decide si emite con marca
 * "BORRADOR — NO CIERRA" o aborta.
 */
class EecCService
{
    public function __construct(
        private readonly BalanceGeneralService $bg,
        private readonly EstadoResultadosService $er,
        private readonly EpnService $epn,
        private readonly FlujoEfectivoService $efe,
        private readonly NotasService $notas,
        private readonly AjusteInflacionService $ajuste,
    ) {}

    /**
     * @param array<int,string> $incluir BG, ER, EPN, EFE, NOTAS
     * @return array
     */
    public function armar(Ejercicio $ejercicio, array $incluir): array
    {
        $bg = in_array('BG',  $incluir) ? $this->bg->calcular($ejercicio) : null;
        $er = in_array('ER',  $incluir) ? $this->er->calcular($ejercicio) : null;
        $epn = in_array('EPN', $incluir) ? $this->epn->calcular($ejercicio) : null;
        $efe = in_array('EFE', $incluir) ? $this->efe->calcular($ejercicio) : null;
        $notas = in_array('NOTAS', $incluir) ? $this->notas->paraEjercicio($ejercicio) : collect();

        $estado = $this->verificarCierre($ejercicio, $bg);

        return [
            'incluir'  => $incluir,
            'estado'   => $estado,
            'bg'       => $bg,
            'er'       => $er,
            'epn'      => $epn,
            'efe'      => $efe,
            'notas'    => $notas,
            'anexo_inflacion' => $this->ajuste->anexo($ejercicio),
        ];
    }

    /**
     * RN-59: valida que A=P+PN (si hay BG) y que no haya asientos
     * BORRADOR del ejercicio.
     */
    private function verificarCierre(Ejercicio $ejercicio, ?array $bg): array
    {
        $motivos = [];

        if ($bg && ! $bg['verificacion']['cierra']) {
            $motivos[] = "Balance no cierra: diferencia {$bg['verificacion']['diferencia']}";
        }

        $borradores = \DB::table('erp_asientos')
            ->where('empresa_id', $ejercicio->empresa_id)
            ->where('ejercicio_id', $ejercicio->id)
            ->where('estado', 'BORRADOR')
            ->count();

        if ($borradores > 0) {
            $motivos[] = "{$borradores} asientos en BORRADOR del ejercicio";
        }

        return [
            'cierra' => count($motivos) === 0,
            'motivo' => implode(' · ', $motivos),
            'asientos_borrador' => $borradores,
        ];
    }
}

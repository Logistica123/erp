<?php

namespace App\Erp\Services\Eecc;

use App\Erp\Models\Ejercicio;
use DomainException;

/**
 * Ajuste por inflación RT 6 (RN-63).
 *
 * Aplica reexpresión a moneda de cierre cuando `erp_ejercicios.ajusta_por_inflacion=1`.
 *
 * Mecánica simplificada (MVP):
 *   coef_reexp = indice_cierre / indice_origen
 *   valor_reexpresado = valor_original × coef_reexp
 *
 * REI (Resultado por Exposición a la Inflación) se calcula como la
 * diferencia entre el patrimonio neto reexpresado vs el contable. Una
 * implementación completa requeriría reexpresar cada movimiento por su
 * fecha de origen — H8 entrega el cálculo agregado y deja el detalle
 * por movimiento como mejora futura.
 *
 * Cuando un ejercicio tiene flag activo, los servicios BG/ER/EPN/EFE
 * deben llamar a `reexpresar()` antes de devolver montos finales.
 */
class AjusteInflacionService
{
    /**
     * Si el ejercicio aplica RT 6, devuelve un coeficiente de reexpresión
     * promedio (placeholder: indice_cierre / indice_inicio_aproximado).
     * Si no aplica, devuelve 1.0.
     */
    public function coeficiente(Ejercicio $ejercicio): float
    {
        if (! $ejercicio->ajusta_por_inflacion) {
            return 1.0;
        }
        if (! $ejercicio->indice_cierre) {
            throw new DomainException('AJUSTE_INFLACION_INDICE_FALTANTE: cargar indice_cierre en el ejercicio');
        }

        // Para una implementación full, se debería conocer el índice de cada
        // mes del ejercicio. Como MVP usamos un proxy: indice_cierre / 1.0
        // (inicio normalizado) y devolvemos el cociente para reexpresar
        // movimientos al cierre. Si en el futuro se carga la curva mensual
        // de IPC, se reemplaza por una tabla `erp_indices_inflacion`.
        return (float) $ejercicio->indice_cierre;
    }

    public function aplica(Ejercicio $ejercicio): bool
    {
        return (bool) $ejercicio->ajusta_por_inflacion;
    }

    /**
     * Reexpresa un set de filas {valor, fecha_origen?}. Si fecha_origen está
     * presente y sería razonable, podría usarse una curva mensual; por ahora
     * todo se reexpresa con `coeficiente()`.
     *
     * @param array<int, array{valor:float}> $filas
     */
    public function reexpresar(Ejercicio $ejercicio, array $filas): array
    {
        $coef = $this->coeficiente($ejercicio);
        return array_map(function ($f) use ($coef) {
            $f['valor_original'] = (float) $f['valor'];
            $f['valor']          = round((float) $f['valor'] * $coef, 2);
            return $f;
        }, $filas);
    }

    /**
     * REI estimado = (PN reexpresado − resultado del ejercicio) − PN contable.
     * Es un cálculo placeholder; requeriría más datos para ser exacto.
     */
    public function reiEstimado(Ejercicio $ejercicio, float $pnContable, float $resultadoContable): float
    {
        if (! $this->aplica($ejercicio)) {
            return 0.0;
        }
        $coef = $this->coeficiente($ejercicio);
        $pnReexp = $pnContable * $coef;
        return round($pnReexp - $pnContable - ($resultadoContable * ($coef - 1)), 2);
    }

    /**
     * Anexo "Mecánica del ajuste por inflación" para incluir como apéndice
     * del paquete EECC.
     */
    public function anexo(Ejercicio $ejercicio): array
    {
        return [
            'aplica'         => $this->aplica($ejercicio),
            'indice_cierre'  => $ejercicio->indice_cierre !== null ? (float) $ejercicio->indice_cierre : null,
            'coeficiente'    => $this->aplica($ejercicio) ? $this->coeficiente($ejercicio) : null,
            'metodo'         => 'RT_6_FACPCE_SIMPLIFICADO',
            'observaciones'  => $this->aplica($ejercicio)
                ? 'Reexpresión aplicada con coeficiente único cierre/inicio. Para ejercicios con alta volatilidad mensual se sugiere migrar a curva IPC mensual.'
                : 'Ejercicio no ajustado por inflación.',
        ];
    }
}

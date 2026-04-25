<?php

namespace App\Erp\Services\Af;

use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfReexpresion;
use App\Erp\Models\Ejercicio;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Reexpresión RT 6 al cierre de ejercicio (SPEC 06 RN-82).
 *
 * Solo corre si `erp_ejercicios.ajusta_por_inflacion = 1`. Para cada bien
 * activo al cierre del ejercicio:
 *   coeficiente        = indice_cierre / indice_origen
 *   valor_reexpresado  = valor_origen × coeficiente
 *   resultado_expos    = valor_reexpresado − valor_origen
 *
 * Persiste un registro por (bien, ejercicio) en `erp_af_reexpresiones`. La
 * suma de los REI alimenta el REI total del ejercicio (SPEC 05 RN-63).
 *
 * Si un bien no tiene `indice_alta` cargado, se asume 1.0 — el caller
 * puede pasar `indice_origen_default` en el contexto.
 */
class AfReexpresionService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{
     *   ejercicio_id:int, indice_cierre:float,
     *   filas:array<int, array>,
     *   totales:array{valor_original:float, valor_reexpresado:float, rei:float}
     * }
     */
    public function generar(Ejercicio $ejercicio, User $usuario, ?float $indiceOrigenDefault = null): array
    {
        if (! $ejercicio->ajusta_por_inflacion) {
            throw new DomainException('AF_REEXP_NO_APLICA: ejercicio no tiene ajusta_por_inflacion=true');
        }
        $indiceCierre = (float) ($ejercicio->indice_cierre ?? 0);
        if ($indiceCierre <= 0) {
            throw new DomainException('AF_REEXP_INDICE_CIERRE_FALTANTE');
        }

        $cierre = (string) $ejercicio->fecha_cierre;

        // Bienes activos al cierre.
        $bienes = AfBien::where('empresa_id', $ejercicio->empresa_id)
            ->whereNull('deleted_at')
            ->where('fecha_alta', '<=', $cierre)
            ->where(function ($q) use ($cierre) {
                $q->whereNull('fecha_baja')->orWhere('fecha_baja', '>', $cierre);
            })
            ->get();

        return DB::transaction(function () use ($ejercicio, $bienes, $indiceCierre, $indiceOrigenDefault, $usuario) {
            $filas = [];
            $totalOriginal = 0.0;
            $totalReexp = 0.0;
            $totalRei = 0.0;

            foreach ($bienes as $bien) {
                $indiceOrigen = (float) ($bien->indice_alta ?? $indiceOrigenDefault ?? 1.0);
                if ($indiceOrigen <= 0) {
                    continue;
                }
                $coef = round($indiceCierre / $indiceOrigen, 6);
                $valorOriginal = (float) $bien->valor_origen;
                $valorReexp = round($valorOriginal * $coef, 2);
                $rei = round($valorReexp - $valorOriginal, 2);

                $row = AfReexpresion::updateOrCreate(
                    ['bien_id' => $bien->id, 'ejercicio_id' => $ejercicio->id],
                    [
                        'indice_origen' => $indiceOrigen,
                        'indice_cierre' => $indiceCierre,
                        'coeficiente'   => $coef,
                        'valor_original'=> $valorOriginal,
                        'valor_reexpresado' => $valorReexp,
                        'resultado_exposicion' => $rei,
                    ]
                );

                // Snapshot del bien con valor reexpresado.
                $bien->update(['valor_reexpresado' => $valorReexp]);

                $filas[] = [
                    'bien_id' => $bien->id,
                    'nro_inventario' => $bien->nro_inventario,
                    'indice_origen' => $indiceOrigen,
                    'indice_cierre' => $indiceCierre,
                    'coeficiente' => $coef,
                    'valor_original' => $valorOriginal,
                    'valor_reexpresado' => $valorReexp,
                    'rei' => $rei,
                ];
                $totalOriginal += $valorOriginal;
                $totalReexp += $valorReexp;
                $totalRei += $rei;
            }

            $this->audit->logEvento(
                'af_reexpresion_rt6',
                'af',
                "Reexpresión RT 6 ejercicio #{$ejercicio->id}: ".count($filas)
                ." bienes, REI={$totalRei} (user #{$usuario->id})",
                $ejercicio->empresa_id
            );

            return [
                'ejercicio_id' => $ejercicio->id,
                'indice_cierre' => $indiceCierre,
                'filas' => $filas,
                'totales' => [
                    'valor_original'    => round($totalOriginal, 2),
                    'valor_reexpresado' => round($totalReexp, 2),
                    'rei'               => round($totalRei, 2),
                ],
            ];
        });
    }

    public function listar(Ejercicio $ejercicio): \Illuminate\Support\Collection
    {
        return AfReexpresion::with('bien')
            ->where('ejercicio_id', $ejercicio->id)
            ->orderBy('bien_id')->get();
    }
}

<?php

namespace App\Erp\Services\Presupuesto;

use App\Erp\Models\Presupuesto\Presupuesto;
use App\Erp\Models\Presupuesto\PresupuestoItem;
use App\Erp\Models\Presupuesto\PresupuestoVersion;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * CRUD + transiciones de presupuestos (SPEC 06 §6.5, RN-85, RN-86, RN-88).
 *
 * Estados: BORRADOR → APROBADO → VIGENTE → HISTORICO/DESCARTADO.
 * Transiciones permitidas:
 *   BORRADOR    → APROBADO, DESCARTADO
 *   APROBADO    → VIGENTE, BORRADOR (retrocede a editar), DESCARTADO
 *   VIGENTE     → HISTORICO (al marcar otro VIGENTE)
 *   HISTORICO   → (terminal)
 *   DESCARTADO  → (terminal)
 *
 * RN-85: al marcar uno como VIGENTE, el VIGENTE actual del mismo
 *        (empresa, ejercicio) pasa a HISTORICO.
 * RN-86: reforecast() clona items + crea registro nuevo es_reforecast=1
 *        forecast_base_id apuntando al original.
 * RN-88: items solo aceptan cuentas imputables.
 */
class PresupuestoService
{
    private const TRANSICIONES = [
        'BORRADOR'   => ['APROBADO', 'DESCARTADO'],
        'APROBADO'   => ['VIGENTE', 'BORRADOR', 'DESCARTADO'],
        'VIGENTE'    => ['HISTORICO'],
        'HISTORICO'  => [],
        'DESCARTADO' => [],
    ];

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function crear(array $datos, User $usuario): Presupuesto
    {
        return DB::transaction(function () use ($datos, $usuario) {
            $presupuesto = Presupuesto::create([
                'empresa_id'        => $datos['empresa_id'],
                'ejercicio_id'      => $datos['ejercicio_id'],
                'nombre'            => $datos['nombre'],
                'estado'            => 'BORRADOR',
                'es_reforecast'     => false,
                'forecast_base_id'  => null,
                'moneda'            => $datos['moneda'] ?? 'ARS',
                'descripcion'       => $datos['descripcion'] ?? null,
                'creado_por'        => $usuario->id,
            ]);

            PresupuestoVersion::create([
                'presupuesto_id' => $presupuesto->id,
                'evento'         => 'CREADO',
                'usuario_id'     => $usuario->id,
                'detalle'        => "Presupuesto creado: {$presupuesto->nombre}",
            ]);

            $this->audit->log('presupuesto_crear', $presupuesto, null, $presupuesto->toArray(),
                "Presupuesto #{$presupuesto->id} creado (user #{$usuario->id})");

            return $presupuesto->fresh();
        });
    }

    public function actualizarCabecera(Presupuesto $p, array $cambios, User $usuario): Presupuesto
    {
        if (! $p->esEditable()) {
            throw new DomainException("PRESUPUESTO_NO_EDITABLE: estado {$p->estado}");
        }
        $p->update(array_intersect_key($cambios, array_flip(['nombre', 'descripcion', 'moneda'])));

        $this->audit->log('presupuesto_editar', $p, null, $cambios,
            "Cabecera presupuesto #{$p->id} editada (user #{$usuario->id})");
        return $p->fresh();
    }

    public function transicionar(Presupuesto $p, string $nuevoEstado, User $usuario): Presupuesto
    {
        $permitidos = self::TRANSICIONES[$p->estado] ?? [];
        if (! in_array($nuevoEstado, $permitidos, true)) {
            throw new DomainException("PRESUPUESTO_TRANSICION_INVALIDA: {$p->estado} → {$nuevoEstado}");
        }

        return DB::transaction(function () use ($p, $nuevoEstado, $usuario) {
            // RN-85: al marcar VIGENTE, el VIGENTE anterior del (empresa, ejercicio) pasa a HISTORICO.
            if ($nuevoEstado === 'VIGENTE') {
                Presupuesto::where('empresa_id', $p->empresa_id)
                    ->where('ejercicio_id', $p->ejercicio_id)
                    ->where('estado', 'VIGENTE')
                    ->where('id', '!=', $p->id)
                    ->each(function ($vigente) use ($usuario) {
                        $vigente->update(['estado' => 'HISTORICO', 'vigente_hasta' => now()->toDateString()]);
                        PresupuestoVersion::create([
                            'presupuesto_id' => $vigente->id, 'evento' => 'HISTORICO',
                            'usuario_id' => $usuario->id,
                            'detalle' => "Reemplazado por presupuesto #{$vigente->id} (RN-85)",
                        ]);
                    });
            }

            $cambios = ['estado' => $nuevoEstado];
            if ($nuevoEstado === 'APROBADO') {
                $cambios['aprobado_por'] = $usuario->id;
                $cambios['aprobado_at']  = now();
            }
            if ($nuevoEstado === 'VIGENTE') {
                $cambios['vigente_desde'] = now()->toDateString();
            }
            $p->update($cambios);

            PresupuestoVersion::create([
                'presupuesto_id' => $p->id, 'evento' => $nuevoEstado,
                'usuario_id' => $usuario->id,
                'detalle' => "Transición {$p->getOriginal('estado')} → {$nuevoEstado}",
            ]);

            $this->audit->log("presupuesto_{$nuevoEstado}", $p, null, $cambios,
                "Presupuesto #{$p->id}: {$p->getOriginal('estado')} → {$nuevoEstado}");

            return $p->fresh();
        });
    }

    /**
     * RN-86: clona el presupuesto base como nuevo BORRADOR con
     * `es_reforecast=1` y `forecast_base_id` apuntando al original.
     * Copia todos los items.
     */
    public function reforecast(Presupuesto $base, string $nombreNuevo, User $usuario): Presupuesto
    {
        if ($base->estado !== 'VIGENTE') {
            throw new DomainException("PRESUPUESTO_REFORECAST_REQUIERE_VIGENTE: {$base->estado}");
        }

        return DB::transaction(function () use ($base, $nombreNuevo, $usuario) {
            $nuevo = Presupuesto::create([
                'empresa_id'       => $base->empresa_id,
                'ejercicio_id'     => $base->ejercicio_id,
                'nombre'           => $nombreNuevo,
                'estado'           => 'BORRADOR',
                'es_reforecast'    => true,
                'forecast_base_id' => $base->id,
                'moneda'           => $base->moneda,
                'descripcion'      => "Reforecast de '{$base->nombre}'",
                'creado_por'       => $usuario->id,
            ]);

            // Copiar items.
            DB::table('erp_presupuesto_items')
                ->insertUsing(
                    ['presupuesto_id', 'cuenta_id', 'centro_costo_id', 'mes', 'importe', 'notas'],
                    DB::table('erp_presupuesto_items')
                        ->where('presupuesto_id', $base->id)
                        ->select(DB::raw($nuevo->id), 'cuenta_id', 'centro_costo_id', 'mes', 'importe', 'notas')
                );

            PresupuestoVersion::create([
                'presupuesto_id' => $nuevo->id, 'evento' => 'REFORECAST',
                'usuario_id' => $usuario->id,
                'detalle' => "Clonado de presupuesto #{$base->id} ({$base->nombre})",
            ]);

            $this->audit->log('presupuesto_reforecast', $nuevo, null, $nuevo->toArray(),
                "Reforecast de presupuesto #{$base->id} → #{$nuevo->id}");

            return $nuevo->fresh();
        });
    }

    /**
     * Bulk upsert de items del presupuesto. RN-88: solo cuentas imputables.
     *
     * @param array<int, array{cuenta_id:int, centro_costo_id?:int, mes:int, importe:float, notas?:string}> $items
     */
    public function bulkItems(Presupuesto $p, array $items, User $usuario): array
    {
        if (! $p->esEditable()) {
            throw new DomainException("PRESUPUESTO_NO_EDITABLE: estado {$p->estado}");
        }
        if (empty($items)) {
            throw new DomainException('PRESUPUESTO_BULK_VACIO');
        }

        return DB::transaction(function () use ($p, $items, $usuario) {
            $cuentaIds = array_unique(array_column($items, 'cuenta_id'));
            $imputables = DB::table('erp_cuentas_contables')
                ->whereIn('id', $cuentaIds)
                ->where('imputable', 1)
                ->pluck('id')->all();

            $invalidas = array_diff($cuentaIds, $imputables);
            if (! empty($invalidas)) {
                throw new DomainException(
                    'PRESUPUESTO_CUENTA_NO_IMPUTABLE: ids '.implode(',', $invalidas).' (RN-88)'
                );
            }

            $insertadas = 0;
            $actualizadas = 0;
            foreach ($items as $i) {
                if (empty($i['cuenta_id']) || empty($i['mes']) || ! isset($i['importe'])) {
                    continue;
                }
                $existente = PresupuestoItem::where([
                    'presupuesto_id'  => $p->id,
                    'cuenta_id'       => $i['cuenta_id'],
                    'centro_costo_id' => $i['centro_costo_id'] ?? null,
                    'mes'             => $i['mes'],
                ])->first();

                if ($existente) {
                    $existente->update(['importe' => $i['importe'], 'notas' => $i['notas'] ?? null]);
                    $actualizadas++;
                } else {
                    PresupuestoItem::create([
                        'presupuesto_id'  => $p->id,
                        'cuenta_id'       => $i['cuenta_id'],
                        'centro_costo_id' => $i['centro_costo_id'] ?? null,
                        'mes'             => $i['mes'],
                        'importe'         => $i['importe'],
                        'notas'           => $i['notas'] ?? null,
                    ]);
                    $insertadas++;
                }
            }

            $this->audit->log('presupuesto_bulk_items', $p, null,
                ['insertadas' => $insertadas, 'actualizadas' => $actualizadas],
                "Bulk items presupuesto #{$p->id}: +{$insertadas}, ~{$actualizadas}");

            return ['insertadas' => $insertadas, 'actualizadas' => $actualizadas];
        });
    }

    public function eliminarItem(Presupuesto $p, int $itemId, User $usuario): void
    {
        if (! $p->esEditable()) {
            throw new DomainException("PRESUPUESTO_NO_EDITABLE: estado {$p->estado}");
        }
        $item = PresupuestoItem::where('presupuesto_id', $p->id)->findOrFail($itemId);
        $item->delete();

        $this->audit->logEvento('presupuesto_item_eliminar', 'presupuesto',
            "Item #{$itemId} eliminado de presupuesto #{$p->id} (user #{$usuario->id})",
            $p->empresa_id);
    }
}

<?php

namespace App\Erp\Services\Af;

use App\Erp\Models\Af\AfBien;
use App\Erp\Models\Af\AfCategoria;
use App\Erp\Models\Af\AfMovimiento;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Alta, edición y bajas livianas de bienes de uso (SPEC 06 RN-75/76/77/84).
 *
 * Las operaciones que mutan contabilidad (mejora, revalúo, baja, transferencia
 * con asiento) viven en bloques posteriores (I2). En I1 cubrimos:
 *   - Alta manual (sin factura).
 *   - Alta desde factura de compra (RN-75).
 *   - Edición de campos no contables: ubicación, responsable.
 *   - Validación umbral RN-77 (rechaza alta si valor < umbral_baja_cuantia).
 *   - Auditoría completa en erp_af_movimientos (RN-84).
 */
class AfBienService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Alta manual de bien (SPEC 06 §6.2 POST /af/bienes).
     *
     * @param array{
     *   empresa_id:int, categoria_id:int, nro_inventario:string, descripcion:string,
     *   fecha_alta:string, valor_origen:float,
     *   marca?:string, modelo?:string, nro_serie?:string, patente?:string,
     *   moneda_origen?:string, valor_origen_me?:float, cotizacion_alta?:float,
     *   valor_residual_cfg?:float, vida_util_contable_meses?:int, vida_util_fiscal_meses?:int,
     *   centro_costo_id?:int, responsable_user_id?:int, ubicacion?:string,
     *   factura_compra_id?:int, proveedor_auxiliar_id?:int,
     * } $datos
     */
    public function alta(array $datos, User $usuario): AfBien
    {
        $categoria = AfCategoria::findOrFail($datos['categoria_id']);
        $this->validarUmbral($categoria, (float) $datos['valor_origen']);

        return DB::transaction(function () use ($datos, $usuario, $categoria) {
            $bien = AfBien::create([
                'empresa_id'              => $datos['empresa_id'],
                'nro_inventario'          => $datos['nro_inventario'],
                'categoria_id'            => $categoria->id,
                'descripcion'             => $datos['descripcion'],
                'marca'                   => $datos['marca'] ?? null,
                'modelo'                  => $datos['modelo'] ?? null,
                'nro_serie'               => $datos['nro_serie'] ?? null,
                'patente'                 => $datos['patente'] ?? null,
                'fecha_alta'              => $datos['fecha_alta'],
                'valor_origen'            => $datos['valor_origen'],
                'moneda_origen'           => $datos['moneda_origen'] ?? 'ARS',
                'valor_origen_me'         => $datos['valor_origen_me'] ?? null,
                'cotizacion_alta'         => $datos['cotizacion_alta'] ?? null,
                'valor_residual_cfg'      => $datos['valor_residual_cfg'] ?? null,
                'vida_util_contable_meses'=> $datos['vida_util_contable_meses'] ?? null,
                'vida_util_fiscal_meses'  => $datos['vida_util_fiscal_meses'] ?? null,
                'centro_costo_id'         => $datos['centro_costo_id'] ?? null,
                'responsable_user_id'     => $datos['responsable_user_id'] ?? null,
                'ubicacion'               => $datos['ubicacion'] ?? null,
                'factura_compra_id'       => $datos['factura_compra_id'] ?? null,
                'proveedor_auxiliar_id'   => $datos['proveedor_auxiliar_id'] ?? null,
                'estado'                  => 'ALTA',
            ]);

            AfMovimiento::create([
                'bien_id'           => $bien->id,
                'tipo'              => 'ALTA',
                'fecha'             => $bien->fecha_alta,
                'importe'           => $bien->valor_origen,
                'cc_nuevo_id'       => $bien->centro_costo_id,
                'responsable_nuevo_id' => $bien->responsable_user_id,
                'ubicacion_nueva'   => $bien->ubicacion,
                'descripcion'       => "Alta manual: {$bien->descripcion}",
                'factura_compra_id' => $bien->factura_compra_id,
                'usuario_id'        => $usuario->id,
            ]);

            $this->audit->log('af_bien_alta', $bien, null, $bien->toArray(),
                "Alta bien #{$bien->id} '{$bien->nro_inventario}' por user #{$usuario->id}");

            return $bien->fresh(['categoria', 'centroCosto']);
        });
    }

    /**
     * Activa una factura de compra como uno o más bienes (RN-75).
     *
     * Marca `erp_facturas_compra.af_activado = 1` y guarda en `af_bienes_ids`
     * los IDs creados. NO genera contabilidad nueva (la factura ya tiene su
     * asiento apuntando a la cuenta `Bienes de Uso`).
     *
     * @param array<int, array> $bienesData lista de bienes a crear (cada uno con la
     *   misma forma que el `alta` excepto factura_compra_id que se rellena).
     *
     * @return array<int, AfBien>
     */
    public function activarDesdeFactura(int $facturaCompraId, array $bienesData, User $usuario): array
    {
        $factura = DB::table('erp_facturas_compra')->where('id', $facturaCompraId)->first();
        if (! $factura) {
            throw new DomainException("AF_FACTURA_NO_ENCONTRADA: id={$facturaCompraId}");
        }
        if ($factura->af_activado) {
            throw new DomainException('AF_FACTURA_YA_ACTIVADA');
        }
        if ($factura->estado === 'ANULADA_POR_NC' || $factura->estado === 'RECHAZADA') {
            throw new DomainException("AF_FACTURA_ESTADO_INVALIDO: {$factura->estado}");
        }
        if (empty($bienesData)) {
            throw new DomainException('AF_BIENES_VACIO');
        }

        return DB::transaction(function () use ($factura, $bienesData, $usuario, $facturaCompraId) {
            $bienesCreados = [];
            foreach ($bienesData as $datos) {
                $datos['empresa_id']        ??= (int) $factura->empresa_id;
                $datos['fecha_alta']        ??= $factura->fecha_emision;
                $datos['proveedor_auxiliar_id'] ??= $factura->auxiliar_id;
                $datos['factura_compra_id']  = $facturaCompraId;
                $bienesCreados[] = $this->alta($datos, $usuario);
            }

            DB::table('erp_facturas_compra')->where('id', $facturaCompraId)->update([
                'af_activado'   => 1,
                'af_bienes_ids' => json_encode(array_map(fn ($b) => $b->id, $bienesCreados)),
                'updated_at'    => now(),
            ]);

            return $bienesCreados;
        });
    }

    /**
     * Edita campos no contables del bien (RN-84: cambios contables son
     * REVALUO/MEJORA y van en I2). Genera movimiento auditable si cambia
     * responsable o ubicación.
     */
    public function editar(AfBien $bien, array $cambios, User $usuario): AfBien
    {
        $permitidos = [
            'descripcion', 'marca', 'modelo', 'nro_serie', 'patente',
            'centro_costo_id', 'responsable_user_id', 'ubicacion', 'estado',
        ];

        $diff = array_intersect_key($cambios, array_flip($permitidos));
        if (empty($diff)) {
            return $bien;
        }

        return DB::transaction(function () use ($bien, $diff, $usuario) {
            $cambioCC      = isset($diff['centro_costo_id']) && (int) $diff['centro_costo_id'] !== (int) $bien->centro_costo_id;
            $cambioResp    = isset($diff['responsable_user_id']) && (int) $diff['responsable_user_id'] !== (int) $bien->responsable_user_id;
            $cambioUbic    = isset($diff['ubicacion']) && (string) $diff['ubicacion'] !== (string) $bien->ubicacion;

            $ccAnt = $bien->centro_costo_id;
            $respAnt = $bien->responsable_user_id;
            $ubicAnt = $bien->ubicacion;

            $bien->update($diff);

            if ($cambioCC) {
                AfMovimiento::create([
                    'bien_id' => $bien->id, 'tipo' => 'TRANSFERENCIA_CC',
                    'fecha' => now()->toDateString(),
                    'cc_anterior_id' => $ccAnt, 'cc_nuevo_id' => $bien->centro_costo_id,
                    'descripcion' => 'Transferencia entre CC sin contabilidad (RN-79)',
                    'usuario_id' => $usuario->id,
                ]);
            }
            if ($cambioResp) {
                AfMovimiento::create([
                    'bien_id' => $bien->id, 'tipo' => 'CAMBIO_RESPONSABLE',
                    'fecha' => now()->toDateString(),
                    'responsable_anterior_id' => $respAnt, 'responsable_nuevo_id' => $bien->responsable_user_id,
                    'descripcion' => 'Cambio de responsable',
                    'usuario_id' => $usuario->id,
                ]);
            }
            if ($cambioUbic) {
                AfMovimiento::create([
                    'bien_id' => $bien->id, 'tipo' => 'CAMBIO_UBICACION',
                    'fecha' => now()->toDateString(),
                    'ubicacion_anterior' => $ubicAnt, 'ubicacion_nueva' => $bien->ubicacion,
                    'descripcion' => 'Cambio de ubicación física',
                    'usuario_id' => $usuario->id,
                ]);
            }

            $this->audit->log('af_bien_editar', $bien, null, $diff,
                "Editar bien #{$bien->id} (user #{$usuario->id})");

            return $bien->fresh();
        });
    }

    private function validarUmbral(AfCategoria $categoria, float $valor): void
    {
        if ((float) $categoria->umbral_baja_cuantia > 0 && $valor < (float) $categoria->umbral_baja_cuantia) {
            throw new DomainException(sprintf(
                'AF_BIEN_BAJO_UMBRAL: valor %.2f < umbral %.2f de la categoría "%s" — imputar a gasto directo (RN-77)',
                $valor, (float) $categoria->umbral_baja_cuantia, $categoria->codigo
            ));
        }
    }
}

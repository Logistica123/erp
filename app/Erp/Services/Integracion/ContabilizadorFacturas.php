<?php

namespace App\Erp\Services\Integracion;

use App\Erp\Services\AsientoService;
use Illuminate\Support\Facades\DB;

/**
 * Genera asientos contables automáticos para facturas de venta EMITIDAS
 * que no tengan asiento_id todavía.
 *
 * Esquema del asiento (FACTURA, signo=+1):
 *   Debe:  Deudores por Ventas (1.1.4.01)                    imp_total
 *   Haber: Servicios de Distribución [cliente-específica]     imp_neto_gravado + imp_no_gravado + imp_exento
 *   Haber: IVA Débito Fiscal (2.1.3.01)                       imp_iva
 *
 * Para NOTA_CREDITO (signo=-1): invertido — Debe Ventas, Haber Deudores.
 *
 * Requiere:
 *  - Diario "VTA" (seed_erp_empresa)
 *  - Centro de costo "GENERAL" (seed post-deploy)
 *  - Cuentas 1.1.4.01, 2.1.3.01, 4.1.1.01/03/04/05 (seed plan de cuentas)
 *  - auxiliar_id válido en cada factura (sync-clientes previo)
 */
class ContabilizadorFacturas
{
    public function __construct(private AsientoService $asientoService) {}

    /** Mapeo nombre cliente → cuenta contable específica de ventas. */
    private const CUENTA_POR_CLIENTE = [
        'OCA' => '4.1.1.01',
        'URBANO' => '4.1.1.02',
        'OCASA' => '4.1.1.03',
        'Loginter' => '4.1.1.04',
    ];
    private const CUENTA_OTROS = '4.1.1.05';
    private const CUENTA_NCE = '4.1.2.01';   // Notas de Crédito Emitidas
    private const CUENTA_DEUDORES = '1.1.4.01';
    private const CUENTA_IVA_DF = '2.1.3.01';

    /**
     * Contabiliza todas las facturas sin asiento. Idempotente.
     *
     * @return array{contabilizadas:int, skipped:int, errores:array<string>}
     */
    public function contabilizarPendientes(int $empresaId = 1, int $usuarioId = 1): array
    {
        $facturas = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.estado', 'EMITIDA')
            ->whereNull('f.asiento_id')
            ->whereNull('f.deleted_at')
            ->select('f.*', 'tc.clase as cbte_clase', 'tc.signo as cbte_signo', 'a.nombre as cliente_nombre')
            ->orderBy('f.fecha_emision')
            ->get();

        $contabilizadas = 0;
        $skipped = 0;
        $errores = [];

        $diarioVta = DB::table('erp_diarios')
            ->where('empresa_id', $empresaId)->where('codigo', 'VTA')->value('id');
        if (!$diarioVta) {
            return ['contabilizadas' => 0, 'skipped' => 0, 'errores' => ['Diario VTA no existe']];
        }

        $ccGeneral = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');

        foreach ($facturas as $f) {
            try {
                $data = $this->armarPayload($f, $empresaId, $diarioVta, $ccGeneral, $usuarioId);
                $asiento = $this->asientoService->crearBorrador($data);
                $asiento = $this->asientoService->contabilizar($asiento);

                DB::table('erp_facturas_venta')
                    ->where('id', $f->id)
                    ->update(['asiento_id' => $asiento->id, 'updated_at' => now()]);

                $contabilizadas++;
            } catch (\Throwable $e) {
                $skipped++;
                $errores[] = "factura #{$f->id}: {$e->getMessage()}";
            }
        }

        return compact('contabilizadas', 'skipped', 'errores');
    }

    private function armarPayload(
        object $f, int $empresaId, int $diarioVta, ?int $ccGeneral, int $usuarioId
    ): array {
        $cuentaVentaCodigo = $this->resolverCuentaVenta($f->cliente_nombre);
        $cuentaDeudoresId = $this->cuentaId($empresaId, self::CUENTA_DEUDORES);
        $cuentaVentaId    = $this->cuentaId($empresaId, $cuentaVentaCodigo);
        $cuentaIvaId      = $this->cuentaId($empresaId, self::CUENTA_IVA_DF);

        $netoSinIva = round(
            (float) $f->imp_neto_gravado + (float) $f->imp_no_gravado + (float) $f->imp_exento, 2
        );
        $imp_iva = (float) $f->imp_iva;
        $imp_total = (float) $f->imp_total;
        $glosa = "Factura venta #{$f->numero} — {$f->cliente_nombre}";

        // Movimientos: signo +1 = factura, signo -1 = nota de crédito
        $esNC = ($f->cbte_signo < 0);

        if (!$esNC) {
            // Factura común: Deudores (Debe) vs Ventas + IVA (Haber)
            $movs = [
                // Debe: Deudores por Ventas (lleva auxiliar y CC)
                [
                    'cuenta_id' => $cuentaDeudoresId,
                    'centro_costo_id' => $ccGeneral,
                    'auxiliar_id' => $f->auxiliar_id,
                    'debe' => $imp_total,
                    'haber' => 0,
                    'glosa' => 'Deudores por venta',
                ],
            ];
            if ($netoSinIva > 0) {
                $movs[] = [
                    'cuenta_id' => $cuentaVentaId,
                    'centro_costo_id' => $ccGeneral,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaVentaId) ? $f->auxiliar_id : null,
                    'debe' => 0,
                    'haber' => $netoSinIva,
                    'glosa' => 'Ingresos por servicios',
                ];
            }
            if ($imp_iva > 0) {
                $movs[] = [
                    'cuenta_id' => $cuentaIvaId,
                    // IVA DF no admite CC — validar
                    'centro_costo_id' => $this->admiteCc($cuentaIvaId) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaIvaId) ? $f->auxiliar_id : null,
                    'debe' => 0,
                    'haber' => $imp_iva,
                    'glosa' => 'IVA Débito Fiscal 21%',
                ];
            }
        } else {
            // NC: usa cuenta 4.1.2.01 "NC emitidas" en Debe, Deudores en Haber
            $cuentaNc = $this->cuentaId($empresaId, self::CUENTA_NCE);
            $movs = [
                [
                    'cuenta_id' => $cuentaNc,
                    'centro_costo_id' => $ccGeneral,
                    'auxiliar_id' => $f->auxiliar_id,
                    'debe' => $netoSinIva,
                    'haber' => 0,
                    'glosa' => 'NC emitida',
                ],
            ];
            if ($imp_iva > 0) {
                $movs[] = [
                    'cuenta_id' => $cuentaIvaId,
                    'centro_costo_id' => $this->admiteCc($cuentaIvaId) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaIvaId) ? $f->auxiliar_id : null,
                    'debe' => $imp_iva,
                    'haber' => 0,
                    'glosa' => 'Reverso IVA DF',
                ];
            }
            $movs[] = [
                'cuenta_id' => $cuentaDeudoresId,
                'centro_costo_id' => $ccGeneral,
                'auxiliar_id' => $f->auxiliar_id,
                'debe' => 0,
                'haber' => $imp_total,
                'glosa' => 'Reverso deudor',
            ];
        }

        return [
            'empresa_id' => $empresaId,
            'diario_id' => $diarioVta,
            'fecha' => $f->fecha_emision instanceof \DateTime
                ? $f->fecha_emision->format('Y-m-d')
                : (string) $f->fecha_emision,
            'glosa' => $glosa,
            'origen' => 'FACTURA_VTA',
            'origen_id' => $f->id,
            'origen_tabla' => 'erp_facturas_venta',
            'usuario_id' => $usuarioId,
            'movimientos' => $movs,
        ];
    }

    private function resolverCuentaVenta(?string $clienteNombre): string
    {
        $nombre = strtoupper((string) $clienteNombre);
        // Orden importa: chequear OCASA antes que OCA
        foreach (self::CUENTA_POR_CLIENTE as $match => $codigo) {
            if (str_contains($nombre, strtoupper($match))) {
                return $codigo;
            }
        }
        return self::CUENTA_OTROS;
    }

    private function cuentaId(int $empresaId, string $codigo): int
    {
        $id = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('codigo', $codigo)
            ->value('id');
        if (!$id) {
            throw new \RuntimeException("Cuenta {$codigo} no existe");
        }
        return (int) $id;
    }

    private function admiteCc(int $cuentaId): bool
    {
        return (bool) DB::table('erp_cuentas_contables')->where('id', $cuentaId)->value('admite_cc');
    }

    private function admiteAuxiliar(int $cuentaId): bool
    {
        return (bool) DB::table('erp_cuentas_contables')->where('id', $cuentaId)->value('admite_auxiliar');
    }
}

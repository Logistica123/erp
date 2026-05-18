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
    // Compras
    private const CUENTA_PROVEEDORES = '2.1.1.01';
    // v1.23 — el fallback histórico apuntaba a `1.1.4.01` (Deudores por Ventas),
    // cuenta del lado activo de ventas que admite_auxiliar=Cliente. Eso rebotaba
    // 67/309 facturas reales con AUXILIAR_REQUERIDO al armar la línea IVA CF.
    // El plan de cuentas argentino estándar ya tiene `1.1.6.01 IVA Crédito Fiscal`
    // imputable + sin auxiliar — esa es la cuenta correcta.
    private const CUENTA_IVA_CF = '1.1.6.01';
    private const CUENTA_PERCEPCIONES_IVA_SUFRIDAS = '1.1.6.04';
    private const CUENTA_RETENCIONES_IVA_SUFRIDAS = '1.1.6.05';
    private const CUENTA_GASTO_DEFAULT = '5.1.01';  // Gastos generales; fallback busca prefijo '5.1'

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

    /**
     * Contabiliza UNA factura de venta puntual (usado desde FacturaVentaService::controlar).
     * Idempotente: si la factura ya tiene asiento_id, devuelve el existente.
     */
    public function contabilizarVenta(int $facturaId, int $empresaId = 1, int $usuarioId = 1): \App\Erp\Models\Asiento
    {
        $f = DB::table('erp_facturas_venta as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.id', $facturaId)
            ->whereNull('f.deleted_at')
            ->select('f.*', 'tc.clase as cbte_clase', 'tc.signo as cbte_signo', 'a.nombre as cliente_nombre')
            ->first();

        if (! $f) {
            throw new \RuntimeException('FACTURA_NO_ENCONTRADA: venta id='.$facturaId);
        }
        if ($f->asiento_id) {
            return \App\Erp\Models\Asiento::findOrFail($f->asiento_id);
        }

        $diarioVta = DB::table('erp_diarios')
            ->where('empresa_id', $empresaId)->where('codigo', 'VTA')->value('id');
        if (! $diarioVta) {
            throw new \RuntimeException('Diario VTA no existe');
        }
        $ccGeneral = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id')
            ?? DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'CENTRAL')->value('id');

        $payload = $this->armarPayload($f, $empresaId, (int) $diarioVta, $ccGeneral ? (int) $ccGeneral : null, $usuarioId);
        $asiento = $this->asientoService->crearBorrador($payload);
        $asiento = $this->asientoService->contabilizar($asiento);

        DB::table('erp_facturas_venta')->where('id', $f->id)
            ->update(['asiento_id' => $asiento->id, 'updated_at' => now()]);

        return $asiento;
    }

    /**
     * Contabiliza UNA factura de compra (RN-34 rama compra).
     *   Debe:  Gasto (5.1.xx) + Retenciones sufridas    imp_neto+no_grav+exento + imp_retenciones
     *   Debe:  IVA Crédito Fiscal (1.1.4.01.02 o 1.1.4.01)   imp_iva + imp_percepciones
     *   Haber: Proveedores (2.1.1.01)                         imp_total
     *
     * Para NOTA_CREDITO recibida (signo=-1): invertido — Debe Proveedores, Haber Gasto/IVA CF.
     */
    public function contabilizarCompra(int $facturaId, int $empresaId = 1, int $usuarioId = 1): \App\Erp\Models\Asiento
    {
        $f = DB::table('erp_facturas_compra as f')
            ->join('erp_tipos_comprobante as tc', 'tc.id', '=', 'f.tipo_comprobante_id')
            ->join('erp_auxiliares as a', 'a.id', '=', 'f.auxiliar_id')
            ->where('f.empresa_id', $empresaId)
            ->where('f.id', $facturaId)
            ->whereNull('f.deleted_at')
            ->select('f.*', 'tc.clase as cbte_clase', 'tc.signo as cbte_signo', 'a.nombre as proveedor_nombre')
            ->first();

        if (! $f) {
            throw new \RuntimeException('FACTURA_NO_ENCONTRADA: compra id='.$facturaId);
        }
        if ($f->asiento_id) {
            return \App\Erp\Models\Asiento::findOrFail($f->asiento_id);
        }

        $diarioCpr = DB::table('erp_diarios')
            ->where('empresa_id', $empresaId)->where('codigo', 'CPR')->value('id');
        if (! $diarioCpr) {
            throw new \RuntimeException('Diario CPR no existe');
        }
        $ccGeneral = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id')
            ?? DB::table('erp_centros_costo')->where('empresa_id', $empresaId)->where('codigo', 'CENTRAL')->value('id');

        $cuentaProv = $this->cuentaId($empresaId, self::CUENTA_PROVEEDORES);
        $cuentaIvaCf = $this->cuentaId($empresaId, self::CUENTA_IVA_CF);
        // v1.23 — percepciones IVA y retenciones IVA van a cuentas dedicadas
        // del plan estándar. Si alguna no existe, fallback al IVA CF para no
        // bloquear el asiento (caso de plan de cuentas reducido).
        $cuentaPercIva = $this->cuentaIdOptional($empresaId, self::CUENTA_PERCEPCIONES_IVA_SUFRIDAS) ?? $cuentaIvaCf;
        $cuentaRetIva = $this->cuentaIdOptional($empresaId, self::CUENTA_RETENCIONES_IVA_SUFRIDAS) ?? $cuentaIvaCf;
        $cuentaGasto = $this->resolverCuentaGasto($empresaId, $f->centro_costo_id, $f->auxiliar_id);

        $netoSinIva = round(
            (float) $f->imp_neto_gravado + (float) $f->imp_no_gravado + (float) $f->imp_exento, 2
        );
        // v1.23 — IVA "puro" (sin sumar percepciones, que van aparte).
        $impIva = (float) $f->imp_iva;
        $impPerc = (float) ($f->imp_percepciones ?? 0);
        $impRet = (float) ($f->imp_retenciones ?? 0);
        $impTotal = (float) $f->imp_total;
        $esNC = ($f->cbte_signo ?? 1) < 0;

        // v1.22 D-22-4 — comprobantes sin IVA discriminado (Factura C tipo 11,
        // NC C tipo 13, comprobantes de monotributistas). El neto y el IVA
        // vienen en 0 pero el total NO. Para que el asiento tenga al menos 2
        // líneas (regla de partida doble), forzamos la línea de gasto al total
        // y omitimos la línea de IVA Crédito Fiscal.
        $sinIvaDiscriminado = $netoSinIva == 0.0 && $impIva == 0.0 && $impTotal != 0.0;
        if ($sinIvaDiscriminado) {
            $netoSinIva = abs($impTotal);
            $impIva = 0.0;
        }

        // v1.22 D-22-5 — para NC el CSV trae importes negativos. El CHECK
        // `chk_mov_signo` de erp_movimientos_asiento exige debe/haber >= 0,
        // así que normalizamos a positivo: el signo se expresa por la rama
        // ($esNC) que swappea debe y haber.
        $netoSinIva = abs($netoSinIva);
        $impIva = abs($impIva);
        $impPerc = abs($impPerc);
        $impRet = abs($impRet);
        $impTotal = abs($impTotal);

        // ADDENDUM v1.9 — fecha del asiento = fecha_imputacion (no emisión).
        // Glosa enriquecida si la imputación es diferida.
        $fechaEmision = $f->fecha_emision instanceof \DateTimeInterface
            ? $f->fecha_emision->format('Y-m-d') : (string) $f->fecha_emision;
        $fechaImputacion = ! empty($f->fecha_imputacion)
            ? ($f->fecha_imputacion instanceof \DateTimeInterface
                ? $f->fecha_imputacion->format('Y-m-d') : (string) $f->fecha_imputacion)
            : $fechaEmision;
        $diferida = $fechaImputacion > $fechaEmision;

        $glosa = ($esNC ? 'NC compra #' : 'Factura compra #').$f->numero.' — '.$f->proveedor_nombre;
        if ($diferida) {
            $glosa .= sprintf(
                ' · comprobante del %s imputado al %s',
                date('d/m/Y', strtotime($fechaEmision)),
                date('d/m/Y', strtotime($fechaImputacion)),
            );
        }
        $movs = [];

        if (! $esNC) {
            if ($netoSinIva > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaGasto,
                    'centro_costo_id' => $this->admiteCc($cuentaGasto) ? ($f->centro_costo_id ?: $ccGeneral) : null,
                    // v1.22 D-22-6 — propagar auxiliar del proveedor si la cuenta lo exige.
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaGasto) ? $f->auxiliar_id : null,
                    'debe' => $netoSinIva,
                    'haber' => 0,
                    'glosa' => 'Gastos / servicios',
                ];
            }
            if ($impIva > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaIvaCf,
                    'centro_costo_id' => $this->admiteCc($cuentaIvaCf) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaIvaCf) ? $f->auxiliar_id : null,
                    'debe' => $impIva,
                    'haber' => 0,
                    'glosa' => 'IVA Crédito Fiscal',
                ];
            }
            // v1.23 — percepciones IVA sufridas a cuenta dedicada (1.1.6.04).
            if ($impPerc > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaPercIva,
                    'centro_costo_id' => $this->admiteCc($cuentaPercIva) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaPercIva) ? $f->auxiliar_id : null,
                    'debe' => $impPerc,
                    'haber' => 0,
                    'glosa' => 'Percepciones IVA sufridas',
                ];
            }
            if ($impRet > 0) {
                // v1.23 — retenciones IVA sufridas a cuenta dedicada (1.1.6.05).
                $movs[] = [
                    'cuenta_id' => (int) $cuentaRetIva,
                    'centro_costo_id' => $this->admiteCc($cuentaRetIva) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaRetIva) ? $f->auxiliar_id : null,
                    'debe' => $impRet,
                    'haber' => 0,
                    'glosa' => 'Retenciones IVA sufridas',
                ];
            }
            // Contrapartida única: Proveedores (total)
            $movs[] = [
                'cuenta_id' => (int) $cuentaProv,
                'centro_costo_id' => $this->admiteCc($cuentaProv) ? $ccGeneral : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaProv) ? $f->auxiliar_id : null,
                'debe' => 0,
                'haber' => $impTotal,
                'glosa' => 'Deuda con proveedor',
            ];
        } else {
            // NC recibida: invertido.
            $movs[] = [
                'cuenta_id' => (int) $cuentaProv,
                'centro_costo_id' => $this->admiteCc($cuentaProv) ? $ccGeneral : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaProv) ? $f->auxiliar_id : null,
                'debe' => $impTotal,
                'haber' => 0,
                'glosa' => 'Reverso deuda por NC',
            ];
            if ($netoSinIva > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaGasto,
                    'centro_costo_id' => $this->admiteCc($cuentaGasto) ? ($f->centro_costo_id ?: $ccGeneral) : null,
                    // v1.22 D-22-6 — mismo fix para reverso gasto (NC).
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaGasto) ? $f->auxiliar_id : null,
                    'debe' => 0,
                    'haber' => $netoSinIva,
                    'glosa' => 'Reverso gasto',
                ];
            }
            if ($impIva > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaIvaCf,
                    'centro_costo_id' => $this->admiteCc($cuentaIvaCf) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaIvaCf) ? $f->auxiliar_id : null,
                    'debe' => 0,
                    'haber' => $impIva,
                    'glosa' => 'Reverso IVA CF',
                ];
            }
            // v1.23 — mismo desglose para NC: percepciones y retenciones aparte.
            if ($impPerc > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaPercIva,
                    'centro_costo_id' => $this->admiteCc($cuentaPercIva) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaPercIva) ? $f->auxiliar_id : null,
                    'debe' => 0,
                    'haber' => $impPerc,
                    'glosa' => 'Reverso percepciones IVA',
                ];
            }
            if ($impRet > 0) {
                $movs[] = [
                    'cuenta_id' => (int) $cuentaRetIva,
                    'centro_costo_id' => $this->admiteCc($cuentaRetIva) ? $ccGeneral : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaRetIva) ? $f->auxiliar_id : null,
                    'debe' => 0,
                    'haber' => $impRet,
                    'glosa' => 'Reverso retenciones IVA',
                ];
            }
        }

        $payload = [
            'empresa_id' => $empresaId,
            'diario_id' => (int) $diarioCpr,
            'fecha' => $fechaImputacion, // ADDENDUM v1.9: imputación, no emisión.
            'glosa' => $glosa,
            'origen' => 'FACTURA_CPR',
            'origen_id' => $f->id,
            'origen_tabla' => 'erp_facturas_compra',
            'usuario_id' => $usuarioId,
            'movimientos' => $movs,
        ];

        $asiento = $this->asientoService->crearBorrador($payload);
        $asiento = $this->asientoService->contabilizar($asiento);

        DB::table('erp_facturas_compra')->where('id', $f->id)
            ->update(['asiento_id' => $asiento->id, 'updated_at' => now()]);

        return $asiento;
    }

    /**
     * ADDENDUM v1.10 — prioridad de resolución:
     *   1. Cuenta default asignada al auxiliar (cuenta_contable_default_id).
     *      Solo se usa si la cuenta default NO es la cuenta de proveedores
     *      (2.1.1.*) — esa va al haber, no al debe del gasto.
     *   2. Fallback: primera 5.1.* imputable.
     */
    private function resolverCuentaGasto(int $empresaId, ?int $ccId, ?int $auxiliarId = null): int
    {
        if ($auxiliarId) {
            $row = DB::table('erp_auxiliares as a')
                ->leftJoin('erp_cuentas_contables as c', 'c.id', '=', 'a.cuenta_contable_default_id')
                ->where('a.id', $auxiliarId)
                ->select('c.id', 'c.codigo', 'c.imputable')
                ->first();
            // Usar default solo si es una cuenta de gasto/imputable y no es de proveedores
            // (que ya se usa como contrapartida).
            if ($row && $row->id && $row->imputable
                && ! str_starts_with((string) $row->codigo, '2.1.1.')
                && ! str_starts_with((string) $row->codigo, '1.1.4.')) {
                return (int) $row->id;
            }
        }

        $id = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)
            ->where('codigo', 'like', '5.1%')
            ->where('imputable', true)
            ->orderBy('codigo')
            ->value('id');

        if (! $id) {
            throw new \RuntimeException('No se encontró cuenta de gasto 5.1.* imputable');
        }

        return (int) $id;
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

    /** v1.23 — variante que devuelve null en lugar de tirar excepción. */
    private function cuentaIdOptional(int $empresaId, string $codigo): ?int
    {
        $id = DB::table('erp_cuentas_contables')
            ->where('empresa_id', $empresaId)->where('codigo', $codigo)
            ->value('id');
        return $id ? (int) $id : null;
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

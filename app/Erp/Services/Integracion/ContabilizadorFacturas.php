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
        $idxAjuste = null; // línea que absorbe el redondeo (ingresos / NCE)

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
                $idxAjuste = count($movs) - 1; // ingresos absorbe el redondeo
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
                if ($idxAjuste === null) $idxAjuste = count($movs) - 1;
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
            $idxAjuste = 0; // NCE (debe) absorbe el redondeo
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

        // Ajuste de redondeo (espejo del de compras): los netos/IVA por alícuota
        // de AFIP pueden diferir del total por centavos. Si |debe-haber| ≤ $1 lo
        // absorbe la línea de ingresos (o NCE) para que el asiento cuadre y la
        // factura no quede sin asiento. Si supera $1, se deja el desbalance.
        $movs = $this->ajustarRedondeoEnLinea($movs, $idxAjuste);

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
        // v1.24 — mapeo concepto AFIP → cuenta contable cargado de
        // erp_configuracion_iva_mapeo (super_admin/contador pueden ajustarlo
        // desde la pantalla `/erp/contabilidad/configuracion-iva`).
        $mapeo = $this->cargarMapeoIva($empresaId);
        $cuentaIvaCfFallback = $this->cuentaIdOptional($empresaId, self::CUENTA_IVA_CF) ?? null;
        $cuentaRetIva = $this->cuentaIdOptional($empresaId, self::CUENTA_RETENCIONES_IVA_SUFRIDAS) ?? $cuentaIvaCfFallback;
        $cuentaGasto = $this->resolverCuentaGasto($empresaId, $f->centro_costo_id, $f->auxiliar_id);

        $netoSinIva = round(
            (float) $f->imp_neto_gravado + (float) $f->imp_no_gravado + (float) $f->imp_exento, 2
        );
        // v1.24 — desglose por alícuota IVA si vienen las columnas detalladas.
        $impIvaPorAlicuota = [
            'iva_credito_21'   => (float) ($f->imp_iva_21 ?? 0),
            'iva_credito_10_5' => (float) ($f->imp_iva_10_5 ?? 0),
            'iva_credito_27'   => (float) ($f->imp_iva_27 ?? 0),
            'iva_credito_2_5'  => (float) ($f->imp_iva_2_5 ?? 0),
            'iva_credito_5'    => (float) ($f->imp_iva_5 ?? 0),
        ];
        $impIvaTotal = (float) $f->imp_iva;
        $sumaAlicuotas = array_sum($impIvaPorAlicuota);

        // Fallback: facturas históricas o cargas manuales sin desglose por alícuota.
        // Si vinieron en 0 las columnas detalladas pero hay imp_iva > 0, todo a 21%.
        if ($sumaAlicuotas == 0.0 && $impIvaTotal > 0) {
            $impIvaPorAlicuota['iva_credito_21'] = $impIvaTotal;
        }

        // v1.24 — percepciones e impuestos detallados.
        $impPercIva     = (float) ($f->imp_percepciones_iva ?? 0);
        $impPercIibb    = (float) ($f->imp_percepciones_iibb ?? 0);
        $impPercOtrosNac= (float) ($f->imp_percepciones_otros_nac ?? 0);
        $impMunicipales = (float) ($f->imp_municipales ?? 0);
        $impInternos    = (float) ($f->imp_internos ?? 0);
        $impOtrosTrib   = (float) ($f->imp_otros_tributos ?? 0);
        // Fallback: facturas viejas con imp_percepciones agregado y sin desglose.
        $sumaPerc = $impPercIva + $impPercIibb + $impPercOtrosNac;
        if ($sumaPerc == 0.0 && (float) ($f->imp_percepciones ?? 0) > 0) {
            $impPercIva = (float) $f->imp_percepciones; // todo a Per IVA por compat
        }

        $impRet = (float) ($f->imp_retenciones ?? 0);
        $impTotal = (float) $f->imp_total;
        $esNC = ($f->cbte_signo ?? 1) < 0;

        // v1.22 D-22-4 — comprobantes sin IVA discriminado (Factura C tipo 11,
        // NC C tipo 13, comprobantes de monotributistas). El neto y el IVA
        // vienen en 0 pero el total NO. Forzamos línea de gasto al total y
        // omitimos toda la línea de IVA.
        $sinIvaDiscriminado = $netoSinIva == 0.0 && $impIvaTotal == 0.0 && $impTotal != 0.0;
        if ($sinIvaDiscriminado) {
            $netoSinIva = abs($impTotal);
            $impIvaPorAlicuota = array_map(fn () => 0.0, $impIvaPorAlicuota);
        }

        // v1.22 D-22-5 — abs para CHECK chk_mov_signo. El signo de NC se aplica
        // al final invirtiendo debe/haber.
        $netoSinIva = abs($netoSinIva);
        $impIvaPorAlicuota = array_map('abs', $impIvaPorAlicuota);
        $impPercIva = abs($impPercIva);
        $impPercIibb = abs($impPercIibb);
        $impPercOtrosNac = abs($impPercOtrosNac);
        $impMunicipales = abs($impMunicipales);
        $impInternos = abs($impInternos);
        $impOtrosTrib = abs($impOtrosTrib);
        $impRet = abs($impRet);
        $impTotal = abs($impTotal);

        // ADDENDUM v1.9 — fecha del asiento = fecha_imputacion (no emisión).
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

        // v1.24 — armamos las líneas como "factura normal" (debe = monto del
        // concepto, haber = 0 para los créditos fiscales / gastos; haber para
        // la contrapartida proveedor). Al final, si es NC invertimos todo.
        $movs = [];
        $idxLineaGastoPrincipal = null; // para ajuste de redondeo D-23-2

        if ($netoSinIva > 0) {
            $movs[] = [
                'cuenta_id' => (int) $cuentaGasto,
                'centro_costo_id' => $this->admiteCc($cuentaGasto) ? ($f->centro_costo_id ?: $ccGeneral) : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaGasto) ? $f->auxiliar_id : null,
                'debe' => $netoSinIva,
                'haber' => 0,
                'glosa' => 'Gastos / servicios',
            ];
            $idxLineaGastoPrincipal = array_key_last($movs);
        }

        // v1.24 — 5 líneas de IVA CF por alícuota (solo las que tienen monto).
        $glosaAlicuota = [
            'iva_credito_21' => 'IVA Crédito Fiscal 21%',
            'iva_credito_10_5' => 'IVA Crédito Fiscal 10,5%',
            'iva_credito_27' => 'IVA Crédito Fiscal 27%',
            'iva_credito_2_5' => 'IVA Crédito Fiscal 2,5%',
            'iva_credito_5' => 'IVA Crédito Fiscal 5%',
        ];
        foreach ($impIvaPorAlicuota as $concepto => $monto) {
            if ($monto <= 0) continue;
            $cuentaId = $this->cuentaPorMapeo($mapeo, $concepto, $cuentaIvaCfFallback);
            $movs[] = [
                'cuenta_id' => $cuentaId,
                'centro_costo_id' => $this->admiteCc($cuentaId) ? $ccGeneral : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaId) ? $f->auxiliar_id : null,
                'debe' => $monto,
                'haber' => 0,
                'glosa' => $glosaAlicuota[$concepto],
            ];
        }

        // v1.24 — percepciones IVA / IIBB / Otros Imp Nacionales.
        $conceptosPerc = [
            ['percepciones_iva',       $impPercIva,     'Percepciones IVA sufridas'],
            ['percepciones_iibb',      $impPercIibb,    'Percepciones IIBB sufridas'],
            ['percepciones_otros_nac', $impPercOtrosNac, 'Percepciones Otros Imp. Nacionales'],
        ];
        foreach ($conceptosPerc as [$concepto, $monto, $glosaConcepto]) {
            if ($monto <= 0) continue;
            $cuentaId = $this->cuentaPorMapeo($mapeo, $concepto, $cuentaIvaCfFallback);
            $movs[] = [
                'cuenta_id' => $cuentaId,
                'centro_costo_id' => $this->admiteCc($cuentaId) ? $ccGeneral : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaId) ? $f->auxiliar_id : null,
                'debe' => $monto,
                'haber' => 0,
                'glosa' => $glosaConcepto,
            ];
        }

        // v1.24 — impuestos como gasto (Municipales / Internos / Otros Tributos).
        $conceptosImp = [
            ['imp_municipales', $impMunicipales, 'Impuestos Municipales'],
            ['imp_internos',    $impInternos,    'Impuestos Internos'],
            ['otros_tributos',  $impOtrosTrib,   'Otros Tributos'],
        ];
        foreach ($conceptosImp as [$concepto, $monto, $glosaConcepto]) {
            if ($monto <= 0) continue;
            $cuentaId = $this->cuentaPorMapeo($mapeo, $concepto, $cuentaGasto);
            $movs[] = [
                'cuenta_id' => $cuentaId,
                'centro_costo_id' => $this->admiteCc($cuentaId) ? ($f->centro_costo_id ?: $ccGeneral) : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaId) ? $f->auxiliar_id : null,
                'debe' => $monto,
                'haber' => 0,
                'glosa' => $glosaConcepto,
            ];
        }

        if ($impRet > 0 && $cuentaRetIva) {
            $movs[] = [
                'cuenta_id' => (int) $cuentaRetIva,
                'centro_costo_id' => $this->admiteCc($cuentaRetIva) ? $ccGeneral : null,
                'auxiliar_id' => $this->admiteAuxiliar($cuentaRetIva) ? $f->auxiliar_id : null,
                'debe' => $impRet,
                'haber' => 0,
                'glosa' => 'Retenciones IVA sufridas',
            ];
        }

        // Contrapartida: Proveedores por el total.
        $movs[] = [
            'cuenta_id' => (int) $cuentaProv,
            'centro_costo_id' => $this->admiteCc($cuentaProv) ? $ccGeneral : null,
            'auxiliar_id' => $this->admiteAuxiliar($cuentaProv) ? $f->auxiliar_id : null,
            'debe' => 0,
            'haber' => $impTotal,
            'glosa' => 'Deuda con proveedor',
        ];

        // Salvaguarda ASIENTO_MINIMO — cuenta líneas con importe > 0 (las que
        // AsientoService::validarMovimientos cuenta efectivamente).
        // Si quedó < 2 líneas (típicamente: solo proveedor con haber > 0 + 0
        // líneas de débito porque neto/iva/percep/imp vinieron todos en 0),
        // forzar línea de gasto = impTotal (espejo del fix v1.22 D-22-4 pero
        // post-armado para cubrir casos que no entraron al sinIvaDiscriminado
        // inicial). Sin esto, el AsientoService rebota la fila con
        // ASIENTO_MINIMO y la atomicidad TODO-O-NADA voltea todo el import.
        $lineasConImporte = array_filter($movs, fn ($m) => ($m['debe'] ?? 0) > 0 || ($m['haber'] ?? 0) > 0);
        if (count($lineasConImporte) < 2 && $impTotal > 0) {
            // Buscar si ya hay línea de gasto; sino agregarla.
            $tieneGasto = false;
            foreach ($movs as $m) {
                if (($m['cuenta_id'] ?? null) === (int) $cuentaGasto && ($m['debe'] ?? 0) > 0) {
                    $tieneGasto = true;
                    break;
                }
            }
            if (! $tieneGasto) {
                array_unshift($movs, [
                    'cuenta_id' => (int) $cuentaGasto,
                    'centro_costo_id' => $this->admiteCc($cuentaGasto) ? ($f->centro_costo_id ?: $ccGeneral) : null,
                    'auxiliar_id' => $this->admiteAuxiliar($cuentaGasto) ? $f->auxiliar_id : null,
                    'debe' => $impTotal,
                    'haber' => 0,
                    'glosa' => 'Gastos / servicios (forzado por salvaguarda)',
                ]);
                $idxLineaGastoPrincipal = 0;
            }
        }

        // v1.24 D-23-1 + D-23-2 — tolerancia de redondeo $1. Los CSV de AFIP
        // traen totales por alícuota redondeados que pueden diferir del total
        // de la factura por unos centavos. Si |debe - haber| ≤ 1 ajustamos la
        // línea de gasto principal; si supera, dejamos el desbalance para que
        // la validación posterior tire ASIENTO_DESBALANCEADO con detalle.
        $movs = $this->ajustarRedondeo($movs, $idxLineaGastoPrincipal);

        if ($esNC) {
            // v1.22 RN-22-4 — invertir todas las líneas para que el asiento
            // contable de la NC sea opuesto a la factura original.
            foreach ($movs as &$linea) {
                $tmp = $linea['debe'];
                $linea['debe'] = $linea['haber'];
                $linea['haber'] = $tmp;
                if (isset($linea['glosa'])) {
                    $linea['glosa'] = 'Reverso · ' . $linea['glosa'];
                }
            }
            unset($linea);
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

    /**
     * v1.24 — Carga el mapeo concepto AFIP → cuenta_contable_id desde
     * `erp_configuracion_iva_mapeo`. Cacheado por request (no por minutos)
     * para que un PUT al mapeo se refleje inmediatamente en el siguiente
     * import sin invalidación explícita.
     *
     * @return array<string,int>  concepto_csv => cuenta_contable_id
     */
    private array $mapeoCache = [];
    private function cargarMapeoIva(int $empresaId): array
    {
        if (isset($this->mapeoCache[$empresaId])) {
            return $this->mapeoCache[$empresaId];
        }
        $rows = DB::table('erp_configuracion_iva_mapeo')
            ->where('empresa_id', $empresaId)->where('activo', 1)
            ->pluck('cuenta_contable_id', 'concepto_csv')->all();
        return $this->mapeoCache[$empresaId] = array_map('intval', $rows);
    }

    /**
     * v1.24 — Obtiene la cuenta del mapeo o un fallback.
     * Si el mapeo no tiene el concepto y no hay fallback, lanza error claro
     * (D-23-8: la falta de mapeo es bug del setup, no silenciar).
     */
    private function cuentaPorMapeo(array $mapeo, string $concepto, ?int $fallback = null): int
    {
        if (isset($mapeo[$concepto])) {
            return $mapeo[$concepto];
        }
        if ($fallback !== null) {
            return $fallback;
        }
        throw new \RuntimeException(
            "MAPEO_IVA_FALTANTE: falta mapeo para '{$concepto}' en erp_configuracion_iva_mapeo. "
            . 'Configurá la cuenta en Contabilidad → Configuración IVA.'
        );
    }

    /**
     * v1.24 D-23-1 + D-23-2 — Ajuste de redondeo $1.
     *
     * Los CSV de AFIP traen totales por alícuota redondeados que pueden diferir
     * del Importe Total por unos centavos. Si |debe - haber| ≤ 1 ajustamos la
     * línea de gasto principal (la mayor); si supera, devolvemos el array sin
     * tocar y la validación posterior tira ASIENTO_DESBALANCEADO con detalle.
     */
    private function ajustarRedondeo(array $movs, ?int $idxGastoPrincipal): array
    {
        $debe = 0.0; $haber = 0.0;
        foreach ($movs as $m) {
            $debe  += (float) $m['debe'];
            $haber += (float) $m['haber'];
        }
        $diff = round($debe - $haber, 2);
        if (abs($diff) < 0.005) return $movs; // ya cuadra (menos de medio centavo)
        if (abs($diff) > 1.0) return $movs;   // > $1 → no se ajusta, dejará error

        // Buscar línea para ajustar: idx pasado explícito, o la línea con mayor
        // 'debe' (gasto principal). Si el ajuste sumara negativo a debe, no
        // podemos restar más que el monto existente — en ese caso, agregar al
        // haber del proveedor (caso edge muy raro).
        $idx = $idxGastoPrincipal;
        if ($idx === null) {
            $maxDebe = 0.0;
            foreach ($movs as $i => $m) {
                if ((float) $m['debe'] > $maxDebe) {
                    $maxDebe = (float) $m['debe'];
                    $idx = $i;
                }
            }
        }
        if ($idx === null) return $movs;

        // Si debe > haber → restar a la línea de debe.
        // Si haber > debe → sumar a la línea de debe.
        $movs[$idx]['debe'] = round((float) $movs[$idx]['debe'] - $diff, 2);
        if ($movs[$idx]['debe'] < 0) {
            // Edge raro: el ajuste haría negativa la línea. Revertir y dejar
            // que la validación posterior reporte el error.
            $movs[$idx]['debe'] = round((float) $movs[$idx]['debe'] + $diff, 2);
        }
        return $movs;
    }

    /**
     * Ajuste de redondeo $1 que absorbe la diferencia en una línea dada, sea
     * de debe o de haber (para ventas/NC: ingresos en haber, NCE en debe).
     * Si |debe - haber| ≤ $1 ajusta esa línea; si supera, deja el desbalance
     * para que la validación posterior reporte ASIENTO_DESBALANCEADO.
     */
    private function ajustarRedondeoEnLinea(array $movs, ?int $idx): array
    {
        $debe = 0.0; $haber = 0.0;
        foreach ($movs as $m) {
            $debe  += (float) $m['debe'];
            $haber += (float) $m['haber'];
        }
        $diff = round($debe - $haber, 2);
        if (abs($diff) < 0.005) return $movs; // ya cuadra
        if (abs($diff) > 1.0) return $movs;   // > $1 → no se ajusta
        if ($idx === null || ! isset($movs[$idx])) {
            return $this->ajustarRedondeo($movs, null); // fallback genérico (línea debe mayor)
        }

        // La línea objetivo absorbe el diff manteniendo su naturaleza:
        //  - línea de haber → si faltó haber (diff>0) hay que sumarle.
        //  - línea de debe  → si faltó debe (diff<0) hay que sumarle (resta de diff).
        if ((float) $movs[$idx]['haber'] > 0) {
            $nuevo = round((float) $movs[$idx]['haber'] + $diff, 2);
            if ($nuevo >= 0) $movs[$idx]['haber'] = $nuevo;
        } else {
            $nuevo = round((float) $movs[$idx]['debe'] - $diff, 2);
            if ($nuevo >= 0) $movs[$idx]['debe'] = $nuevo;
        }
        return $movs;
    }
}

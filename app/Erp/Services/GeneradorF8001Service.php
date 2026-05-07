<?php

namespace App\Erp\Services;

use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Models\VentasCompras\LibroIvaComprasExport;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * ADDENDUM v1.11 — Generador de TXT F.8001 Libro IVA Digital Compras.
 *
 * Formato verificado contra fixtures reales del estudio LIBER (período
 * 2026-03, 421 facturas):
 *
 *   F8001_marzo_2026_CBTE.txt:
 *     325 chars/línea, encoding ISO-8859-1, line ending CRLF.
 *     Una línea por factura.
 *
 *   F8001_marzo_2026_ALICUOTAS.txt:
 *     84 chars/línea, encoding ISO-8859-1, line ending CRLF.
 *     Una línea por par (factura × alícuota IVA con base > 0).
 *
 * El generador SOLO incluye facturas con `no_tomada=0` del período
 * solicitado. Las facturas con `no_tomada=1` se excluyen explícitamente
 * (decisión FI-03 del Addendum v1.11).
 *
 * Validaciones bloqueantes (RN-F8-2):
 *   - CUIT del proveedor debe ser válido (check digit estándar).
 *   - Tipo de comprobante debe estar en `erp_tipos_comprobante`.
 *   - Suma de alícuotas IVA por factura debe coincidir con `imp_iva` (±0.01).
 *
 * Cada generación queda registrada en `erp_libros_iva_compras_export` con
 * SHA-256 de cada archivo para auditoría y comparación con LIBER durante
 * el soak.
 */
class GeneradorF8001Service
{
    private const ENCODING = 'ISO-8859-1';
    private const LINE_END = "\r\n";

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @return array{export_id:int, cbte_path:string, alicuotas_path:string,
     *               filas_cbte:int, filas_alicuotas:int,
     *               total_neto:float, total_iva:float, total_facturas:float,
     *               cbte_hash:string, alicuotas_hash:string}
     */
    public function generar(int $periodoId, User $usuario, int $empresaId = 1): array
    {
        $periodo = DB::table('erp_periodos')->where('id', $periodoId)->first();
        if (! $periodo) {
            throw new DomainException('PERIODO_NO_ENCONTRADO');
        }

        // Si el período está cerrado/bloqueado, requiere permiso especial.
        if (in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
            $tienePermiso = DB::table('erp_rol_permiso as rp')
                ->join('erp_permisos as p', 'p.id', '=', 'rp.permiso_id')
                ->join('erp_usuario_rol as ur', 'ur.rol_id', '=', 'rp.rol_id')
                ->join('erp_usuario_perfil as up', 'up.id', '=', 'ur.usuario_perfil_id')
                ->where('up.user_id', $usuario->id)
                ->where('p.codigo', 'compras.exportar_libro_iva_periodo_cerrado')
                ->exists();
            if (! $tienePermiso) {
                throw new DomainException(
                    'PERIODO_CERRADO_SIN_PERMISO: el período está cerrado y no tenés permiso compras.exportar_libro_iva_periodo_cerrado.'
                );
            }
        }

        $facturas = $this->cargarFacturasTomadas($empresaId, $periodoId);
        if ($facturas->isEmpty()) {
            throw new DomainException('SIN_FACTURAS: no hay facturas tomadas en el período seleccionado.');
        }

        $errores = $this->validar($facturas);
        if (! empty($errores)) {
            throw new DomainException(
                'VALIDACION_BLOQUEANTE: '.json_encode($errores, JSON_UNESCAPED_UNICODE)
            );
        }

        // Generar líneas.
        $cbteLines = [];
        $alicLines = [];
        $totalNeto = 0.0;
        $totalIva = 0.0;
        $totalFacturas = 0.0;

        foreach ($facturas as $f) {
            $alicuotasFila = $this->extraerAlicuotas($f);
            $cbteLines[] = $this->lineaCbte($f, $alicuotasFila);

            // Una línea ALICUOTAS por cada alícuota con base > 0.
            foreach ($alicuotasFila as $alic) {
                $alicLines[] = $this->lineaAlicuotas($f, $alic);
            }

            $totalNeto += (float) $f->imp_neto_gravado;
            $totalIva += (float) $f->imp_iva;
            $totalFacturas += (float) $f->imp_total;
        }

        $cbteContent = implode(self::LINE_END, $cbteLines).self::LINE_END;
        $alicContent = implode(self::LINE_END, $alicLines).self::LINE_END;
        $cbteHash = hash('sha256', $cbteContent);
        $alicHash = hash('sha256', $alicContent);

        $ts = now()->format('Ymd_His');
        $base = sprintf('erp/libro_iva_compras_export/%d/%d_%d/', $empresaId, $periodo->anio, $periodo->mes);
        $cbtePath = $base."LIBRO_IVA_DIGITAL_COMPRAS_ORDINARIAS_CBTE_{$ts}.txt";
        $alicPath = $base."LIBRO_IVA_DIGITAL_COMPRAS_ORDINARIAS_ALICUOTAS_{$ts}.txt";
        Storage::disk('local')->put($cbtePath, $cbteContent);
        Storage::disk('local')->put($alicPath, $alicContent);

        $export = LibroIvaComprasExport::create([
            'empresa_id' => $empresaId,
            'periodo_id' => $periodoId,
            'archivo_cbte_path' => $cbtePath,
            'archivo_alicuotas_path' => $alicPath,
            'archivo_cbte_hash' => $cbteHash,
            'archivo_alicuotas_hash' => $alicHash,
            'filas_cbte' => count($cbteLines),
            'filas_alicuotas' => count($alicLines),
            'total_neto' => $totalNeto,
            'total_iva' => $totalIva,
            'total_facturas' => $totalFacturas,
            'generado_por' => $usuario->id,
            'generado_at' => now(),
        ]);

        $this->audit->logEvento(
            accion: 'GENERAR_F8001_COMPRAS',
            modulo: 'compras',
            descripcion: sprintf(
                'F8001 Compras export #%d — periodo %02d/%d, %d cbte, %d alicuotas, total %.2f',
                $export->id, $periodo->mes, $periodo->anio,
                count($cbteLines), count($alicLines), $totalFacturas
            ),
            empresaId: $empresaId,
        );

        return [
            'export_id' => $export->id,
            'cbte_path' => $cbtePath,
            'alicuotas_path' => $alicPath,
            'filas_cbte' => count($cbteLines),
            'filas_alicuotas' => count($alicLines),
            'total_neto' => round($totalNeto, 2),
            'total_iva' => round($totalIva, 2),
            'total_facturas' => round($totalFacturas, 2),
            'cbte_hash' => $cbteHash,
            'alicuotas_hash' => $alicHash,
        ];
    }

    /**
     * Línea CBTE.txt (325 chars).
     *
     * Formato verificado byte-a-byte contra fixture real (RG 4597 — Libro IVA
     * Digital, diseño de registro "Comprobantes de Compras"):
     *
     *   pos 1-8     fecha (YYYYMMDD)
     *   pos 9-11    tipo cbte (3, código AFIP)
     *   pos 12-16   PV (5)
     *   pos 17-36   número (20, padding 0s izq)
     *   pos 37-52   despacho importación (16, espacios — solo aduanas)
     *   pos 53-54   doc tipo vendedor (2, "80"=CUIT)
     *   pos 55-74   nro doc vendedor (20, padding 0s)
     *   pos 75-104  apellido y nombre vendedor (30, padding espacios der)
     *   pos 105-119 importe total operación (15, ×100, padding 0s)
     *   pos 120-134 importe conceptos no integran precio neto gravado (15)
     *   pos 135-149 importe operaciones exentas (15)
     *   pos 150-164 importe percepciones / pagos a cuenta IVA (15)
     *   pos 165-179 importe percepciones otros impuestos nacionales (15)
     *   pos 180-194 importe percepciones IIBB (15)
     *   pos 195-209 importe percepciones impuestos municipales (15)
     *   pos 210-224 importe impuestos internos (15)
     *   pos 225-227 código moneda (3, "PES"/"DOL"/...)
     *   pos 228-237 tipo de cambio (10, ×1000000, padding 0s)
     *   pos 238     cantidad alícuotas IVA (1, "1"-"4")
     *   pos 239     código operación (1, espacio default)
     *   pos 240-254 crédito fiscal computable = imp_iva (15)
     *   pos 255-269 otros tributos (15)
     *   pos 270-280 CUIT emisor / corredor (11, ceros si no hay corredor)
     *   pos 281-310 denominación emisor / corredor (30, espacios si no hay)
     *   pos 311-325 IVA comisión (15, ceros si no hay comisión)
     */
    public function lineaCbte(object $f, ?array $alicuotas = null): string
    {
        $alicuotas ??= $this->extraerAlicuotas($f);

        $fecha = Carbon::parse($f->fecha_emision)->format('Ymd');
        $tipo = $this->numpad((string) $f->tipo_comprobante_id, 3);
        $pv   = $this->numpad((string) $f->punto_venta, 5);
        $num  = $this->numpad((string) $f->numero, 20);
        $despacho = str_repeat(' ', 16);
        $docTipo = $this->numpad('80', 2); // CUIT proveedor por default
        $cuit = $this->numpad(preg_replace('/[^0-9]/', '', (string) ($f->cuit_emisor ?? '')), 20);
        $razon = $this->textpad((string) ($f->razon_social_emisor ?? ''), 30);

        $impTotal     = $this->moneda((float) $f->imp_total);
        $impNoGravado = $this->moneda((float) ($f->imp_no_gravado ?? 0));
        $impExento    = $this->moneda((float) ($f->imp_exento ?? 0));
        // Detalle de percepciones: si el modelo no tiene desglose, todos
        // quedan en 0 (el agregado vive en `imp_percepciones`, que F.8001
        // no mapea a un único campo).
        $impPerIva    = $this->moneda((float) ($f->imp_per_iva ?? 0));
        $impPerOtrosNac = $this->moneda((float) ($f->imp_per_otros_nac ?? 0));
        $impPerIibb   = $this->moneda((float) ($f->imp_per_iibb ?? 0));
        $impPerMun    = $this->moneda((float) ($f->imp_per_municipales ?? 0));
        $impIntern    = $this->moneda((float) ($f->imp_internos ?? 0));

        $moneda = 'PES';
        $cotiz  = $this->numpad((string) (int) round((float) ($f->cotizacion ?? 1.0) * 1000000), 10);
        // cantAlic = cantidad real de filas de alícuotas (0 para monotributo/exento).
        $cantAlic = (string) count($alicuotas);
        $codOp = ' ';
        $creditoFiscal = $this->moneda((float) $f->imp_iva);
        $otrosTrib    = $this->moneda((float) ($f->imp_tributos ?? 0));
        $cuitCorredor = str_repeat('0', 11);
        $razonCorredor = str_repeat(' ', 30);
        $ivaComision = $this->moneda(0.0);

        $linea = $fecha.$tipo.$pv.$num.$despacho.$docTipo.$cuit.$razon
            .$impTotal.$impNoGravado.$impExento.$impPerIva.$impPerOtrosNac.$impPerIibb.$impPerMun.$impIntern
            .$moneda.$cotiz.$cantAlic.$codOp.$creditoFiscal.$otrosTrib.$cuitCorredor.$razonCorredor.$ivaComision;

        if (mb_strlen($linea, '8bit') !== 325) {
            $linea = str_pad(substr($linea, 0, 325), 325);
        }
        return $linea;
    }

    /**
     * Línea ALICUOTAS.txt (84 chars).
     *
     *   pos 1-3   tipo cbte (3)
     *   pos 4-8   PV (5)
     *   pos 9-28  número (20)
     *   pos 29-30 doc tipo (2)
     *   pos 31-50 CUIT (20)
     *   pos 51-65 neto gravado de la alícuota (15, ×100)
     *   pos 66-69 código alícuota (4, "0005"=21%, "0003"=10.5%, etc.)
     *   pos 70-84 IVA de la alícuota (15, ×100)
     */
    public function lineaAlicuotas(object $f, array $alic): string
    {
        $tipo = $this->numpad((string) $f->tipo_comprobante_id, 3);
        $pv   = $this->numpad((string) $f->punto_venta, 5);
        $num  = $this->numpad((string) $f->numero, 20);
        $docTipo = $this->numpad('80', 2);
        $cuit = $this->numpad(preg_replace('/[^0-9]/', '', (string) ($f->cuit_emisor ?? '')), 20);
        $neto = $this->moneda($alic['base']);
        $codAlic = $alic['codigo_afip'];
        $iva = $this->moneda($alic['iva']);

        $linea = $tipo.$pv.$num.$docTipo.$cuit.$neto.$codAlic.$iva;
        if (mb_strlen($linea, '8bit') !== 84) {
            $linea = str_pad(substr($linea, 0, 84), 84);
        }
        return $linea;
    }

    /**
     * Devuelve las alícuotas que aplican a una factura. Cada elemento:
     *   ['codigo_afip' => '0005', 'base' => float, 'iva' => float]
     *
     * Si la factura tiene desglose en `erp_factura_compra_iva` (1 fila por
     * alícuota), usa eso. Si no, deduce a partir del importe IVA total y
     * la base imponible (asume 21% como default para mantener simple).
     */
    public function extraerAlicuotas(object $f): array
    {
        $rows = DB::table('erp_factura_compra_iva as fi')
            ->join('erp_alicuotas_iva as a', 'a.id', '=', 'fi.alicuota_iva_id')
            ->where('fi.factura_id', $f->id)
            ->select('fi.base_imponible', 'fi.importe_iva', 'a.codigo_afip', 'a.tasa')
            ->get();

        if ($rows->isNotEmpty()) {
            return $rows->map(fn ($r) => [
                'codigo_afip' => $r->codigo_afip ?? '0005',
                'base' => (float) $r->base_imponible,
                'iva' => (float) $r->importe_iva,
            ])->all();
        }

        // Fallback: si la factura tiene IVA discriminado pero sin desglose,
        // asume 21% sobre el total neto gravado.
        $iva = (float) ($f->imp_iva ?? 0);
        $neto = (float) ($f->imp_neto_gravado ?? 0);
        if ($iva <= 0.01 || $neto <= 0.01) {
            return [];
        }
        $tasa = round($iva / $neto, 4);
        $codigo = match (true) {
            abs($tasa - 0.21)  < 0.005 => '0005',
            abs($tasa - 0.105) < 0.003 => '0003',
            abs($tasa - 0.27)  < 0.005 => '0004',
            abs($tasa - 0.05)  < 0.003 => '0008',
            abs($tasa - 0.025) < 0.002 => '0006',
            default => '0005',
        };
        return [['codigo_afip' => $codigo, 'base' => $neto, 'iva' => $iva]];
    }

    /**
     * Validaciones bloqueantes (RN-F8-2). Devuelve lista de errores; vacía si OK.
     */
    public function validar($facturas): array
    {
        $errores = [];
        $tiposValidos = DB::table('erp_tipos_comprobante')->pluck('id')->all();

        foreach ($facturas as $f) {
            $cuit = preg_replace('/[^0-9]/', '', (string) ($f->cuit_emisor ?? ''));
            if (! $this->cuitValido($cuit)) {
                $errores[] = ['factura_id' => $f->id, 'numero' => $f->numero,
                    'codigo' => 'CUIT_INVALIDO', 'detalle' => "CUIT {$cuit}"];
            }
            if (! in_array($f->tipo_comprobante_id, $tiposValidos, true)) {
                $errores[] = ['factura_id' => $f->id, 'numero' => $f->numero,
                    'codigo' => 'TIPO_NO_CATALOGADO', 'detalle' => "tipo {$f->tipo_comprobante_id}"];
            }

            // Suma de alícuotas vs imp_iva total
            $alicuotas = $this->extraerAlicuotas($f);
            if (! empty($alicuotas)) {
                $sumaIva = array_sum(array_column($alicuotas, 'iva'));
                if (abs($sumaIva - (float) $f->imp_iva) > 0.01) {
                    $errores[] = ['factura_id' => $f->id, 'numero' => $f->numero,
                        'codigo' => 'IVA_DESBALANCEADO',
                        'detalle' => sprintf('suma alícuotas %.2f ≠ imp_iva %.2f', $sumaIva, (float) $f->imp_iva)];
                }
            }
        }
        return $errores;
    }

    private function cargarFacturasTomadas(int $empresaId, int $periodoId)
    {
        return FacturaCompra::query()
            ->where('empresa_id', $empresaId)
            ->where('periodo_id', $periodoId)
            ->where('no_tomada', 0)
            ->whereNull('deleted_at')
            ->orderBy('fecha_emision')
            ->orderBy('id')
            ->get();
    }

    /** Padding numérico a izquierda con ceros. */
    private function numpad(string $val, int $len): string
    {
        return str_pad($val, $len, '0', STR_PAD_LEFT);
    }

    /** Padding texto a derecha con espacios. Trunca si excede longitud. */
    private function textpad(string $val, int $len): string
    {
        $val = mb_substr($val, 0, $len, 'UTF-8');
        // Convertir a latin1 para conteo correcto en caracteres con tildes.
        $val = @mb_convert_encoding($val, self::ENCODING, 'UTF-8') ?: $val;
        return str_pad($val, $len, ' ', STR_PAD_RIGHT);
    }

    /** Importe a 15 chars: ×100, sin decimales, padding 0s izq. */
    private function moneda(float $val): string
    {
        $entero = (int) round($val * 100);
        $abs = abs($entero);
        $signo = $entero < 0 ? '-' : '';
        $padded = str_pad((string) $abs, 15 - strlen($signo), '0', STR_PAD_LEFT);
        return $signo.$padded;
    }

    /** Validación check digit CUIT (estándar AFIP). */
    public function cuitValido(string $cuit): bool
    {
        $cuit = preg_replace('/[^0-9]/', '', $cuit);
        if (strlen($cuit) !== 11) return false;
        $multipliers = [5, 4, 3, 2, 7, 6, 5, 4, 3, 2];
        $sum = 0;
        for ($i = 0; $i < 10; $i++) {
            $sum += ((int) $cuit[$i]) * $multipliers[$i];
        }
        $mod = $sum % 11;
        $check = $mod === 0 ? 0 : ($mod === 1 ? 9 : 11 - $mod);
        return $check === (int) $cuit[10];
    }
}

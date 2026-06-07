<?php

namespace App\Erp\Services\AperturaEstudio;

use App\Erp\Models\Asiento;
use App\Erp\Models\MovimientoAsiento;
use App\Erp\Models\Periodo;
use App\Erp\Services\AsientoService;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * v1.43 — Importer atómico de apertura contable entregada por el estudio.
 *
 * Carga (en una transacción):
 *  - Asiento de apertura al 2026-01-01 con 53 líneas conceptuales del estudio
 *    desglosadas en N líneas reales del ERP (clientes y proveedores por
 *    auxiliar individual; auxiliares "SALDO-APE-*" dummy para cuentas que
 *    requieren un tipo de auxiliar y el estudio no desglosó).
 *  - Auxiliares CLI-{cuit}, PROV-{cuit} (norma v1.36) con saldo inicial
 *    integrado en el propio asiento.
 *  - Bienes de uso históricos (9 ítems) con amortizaciones acumuladas a
 *    2025-12.
 *  - Préstamo ICBC y tarjetas AMEX/ICBC en `erp_prestamos` (préstamo) +
 *    auxiliares (tarjetas) — el saldo ya quedó imputado en el asiento.
 *  - 2 asientos post-apertura (V606071 + W099151) — constitución de planes.
 *
 * Idempotente: si ya hay un asiento `origen='APERTURA_ESTUDIO_V143'`,
 * aborta (a menos que se invoque ::rollback() antes).
 */
class AperturaEstudioImporter
{
    private const ORIGEN_MARKER = 'APERTURA_ESTUDIO_V143';
    private const FECHA_APERTURA = '2026-01-01';
    private const EMPRESA_ID = 1;
    private const TOLERANCIA_CUADRE = 0.50; // tolerancia $0.50 vs total esperado

    private array $datos;
    private int $userId;
    private bool $dryRun;
    private array $manifest = [
        'asientos' => [],
        'auxiliares' => [],
        'af_bienes' => [],
        'af_amortizaciones' => [],
        'prestamos' => [],
        'periodos_reabiertos' => [], // (id => estado_original)
    ];
    private array $stats = [
        'cuentas_creadas' => 0,
        'asiento_apertura_lineas' => 0,
        'clientes_cargados' => 0,
        'proveedores_cargados' => 0,
        'auxiliares_dummy' => 0,
        'bienes_cargados' => 0,
        'amortizaciones_cargadas' => 0,
        'prestamos_cargados' => 0,
        'asientos_post_apertura' => 0,
    ];

    private const MAP_CUENTAS = [
        // codigo_estudio => codigo_erp
        '11101' => '1.1.1.01', '11103' => '1.1.4.04', '11104' => '1.1.2.07',
        '11105' => '1.1.2.01', '11106' => '1.1.2.03', '11107' => '1.1.2.02',
        '11108' => '1.1.2.04', '11301' => '1.1.4.01', '11305' => '1.1.5.01',
        '11402' => '1.1.6.01.21', '11403' => '1.1.6.04', '11407' => '1.1.6.06',
        '11408' => '1.1.6.17', '11410' => '1.1.6.13', '11412' => '1.1.6.12',
        '11413' => '1.1.6.15', '11414' => '1.1.6.10', '11419' => '1.1.6.18',
        '11701' => '1.1.5.06', '12102' => '1.2.1.06', '12105' => '1.2.1.01',
        '12106' => '1.2.2.01', '12107' => '1.2.1.02', '12108' => '1.2.2.02',
        '12113' => '1.2.1.03', '12114' => '1.2.2.03',
        '21101' => '2.1.1.01', '21201' => '2.1.2.01', '21205' => '2.1.2.02',
        '21206' => '2.1.2.03', '21207' => '2.1.2.04', '21208' => '2.1.2.09',
        '21209' => '2.1.2.05', '21210' => '2.1.2.05', '21212' => '2.1.2.03',
        '21213' => '2.1.2.05', '21301' => '2.1.3.01', '21302' => '2.1.3.02',
        '21303' => '2.1.3.09', '21311' => '2.1.3.08',
        '21331' => '2.1.3.13', '21340' => '2.1.3.13', '21341' => '2.1.3.13',
        '21342' => '2.1.3.13', '21343' => '2.1.3.13',
        '21501' => '2.2.1.01', '21516' => '2.1.4.04', '21517' => '2.1.4.04',
        '31101' => '3.1.01', '31102' => '3.1.02', '32101' => '3.1.03',
        '33101' => '3.2.01', '34101' => '3.3.01',
    ];

    /**
     * codigo_estudio => código auxiliar a usar (cuando la cuenta requiere
     * auxiliar pero el saldo viene "agrupado" desde el estudio).
     */
    private const AUXILIAR_POR_LINEA = [
        '21331' => 'PLAN-U957448',
        '21340' => 'PLAN-V030565',
        '21341' => 'PLAN-V155995',
        '21342' => 'PLAN-V245451',
        '21343' => 'PLAN-V354836',
        '21501' => 'PRESTAMO-ICBC-204720603',
        '21516' => 'TC-AMEX',
        '21517' => 'TC-ICBC',
    ];

    /** Códigos estudio que se desglosan por auxiliar (clientes y proveedores). */
    private const LINEAS_DESGLOSADAS = ['11301', '21101'];

    /** Mapeo rubro estudio → categoría AF. */
    private const RUBRO_CATEGORIA = [
        'Rodados' => 'RODADOS',
        'Equipos de computación' => 'INFORMATICA',
        'Muebles y Utiles' => 'MUEBLES',
        'Muebles y Útiles' => 'MUEBLES',
    ];

    public function __construct(
        private readonly AsientoService $asientoService,
    ) {}

    public function run(string $jsonPath, int $userId, bool $dryRun = false): array
    {
        $this->userId = $userId;
        $this->dryRun = $dryRun;

        if (! file_exists($jsonPath)) {
            throw new RuntimeException("JSON no encontrado: {$jsonPath}");
        }
        $this->datos = json_decode(file_get_contents($jsonPath), true, 512, JSON_THROW_ON_ERROR);

        try {
            DB::beginTransaction();
            DB::statement('SET @erp_current_user_id = ?', [$userId]);

            $this->validarPrecondiciones();
            $this->abrirPeriodosNecesarios();
            $this->resolverCuentasYDummies();
            $this->crearAsientoApertura();
            $this->cargarBienesUso();
            $this->cargarPrestamoIcbc();
            $this->cargarAsientosPostApertura();
            $this->validarPostcarga();

            if ($this->dryRun) {
                DB::rollBack();
                $this->cerrarPeriodosReabiertos(true);
                return ['ok' => true, 'dry_run' => true, 'stats' => $this->stats, 'manifest' => $this->manifest];
            }

            DB::commit();
            $this->cerrarPeriodosReabiertos(false);
            $this->guardarManifest();
            return ['ok' => true, 'dry_run' => false, 'stats' => $this->stats, 'manifest' => $this->manifest];
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }

    /** Rollback completo de una carga previa, usando el manifest persistido. */
    public function rollback(): array
    {
        $manifestPath = storage_path('app/apertura_estudio_v143_manifest.json');
        if (! file_exists($manifestPath)) {
            throw new RuntimeException('Manifest no encontrado — no hay carga previa registrada.');
        }
        $m = json_decode(file_get_contents($manifestPath), true);

        return DB::transaction(function () use ($m, $manifestPath) {
            DB::statement('SET @erp_current_user_id = ?', [$this->userId ?? 1]);
            $borrados = ['movimientos' => 0, 'asientos' => 0, 'amort' => 0, 'bienes' => 0, 'prestamos_cuotas' => 0, 'prestamos' => 0, 'auxiliares' => 0];

            foreach ($m['af_amortizaciones'] ?? [] as $id) {
                $borrados['amort'] += DB::table('erp_af_amortizaciones')->where('id', $id)->delete();
            }
            foreach ($m['af_bienes'] ?? [] as $id) {
                $borrados['bienes'] += DB::table('erp_af_bienes')->where('id', $id)->delete();
            }
            foreach ($m['prestamos'] ?? [] as $pid) {
                $borrados['prestamos_cuotas'] += DB::table('erp_prestamos_cuotas')->where('prestamo_id', $pid)->delete();
                $borrados['prestamos'] += DB::table('erp_prestamos')->where('id', $pid)->delete();
            }
            foreach ($m['asientos'] ?? [] as $aid) {
                $borrados['movimientos'] += DB::table('erp_movimientos_asiento')->where('asiento_id', $aid)->delete();
                $borrados['asientos'] += DB::table('erp_asientos')->where('id', $aid)->delete();
            }
            // Auxiliares: solo borrar los que estén vacíos (sin movimientos referenciándolos).
            foreach ($m['auxiliares'] ?? [] as $auxId) {
                $usado = DB::table('erp_movimientos_asiento')->where('auxiliar_id', $auxId)->exists();
                if (! $usado) {
                    $borrados['auxiliares'] += DB::table('erp_auxiliares')->where('id', $auxId)->delete();
                }
            }
            unlink($manifestPath);
            return ['ok' => true, 'borrados' => $borrados];
        });
    }

    // --- pasos --------------------------------------------------------------

    private function validarPrecondiciones(): void
    {
        // 1. Validar suma del asiento.
        $debe = 0; $haber = 0;
        foreach ($this->datos['asiento_apertura'] as $l) {
            $debe += (float) ($l['debe'] ?? 0);
            $haber += (float) ($l['haber'] ?? 0);
        }
        if (abs($debe - $haber) > 0.01) {
            throw new DomainException(sprintf('ASIENTO_DESBALANCEADO_JSON: debe=%.2f haber=%.2f diff=%.2f', $debe, $haber, $debe - $haber));
        }

        // 2. No debe existir un asiento de apertura previo (marker compuesto).
        $existePrevio = DB::table('erp_asientos')
            ->where('empresa_id', self::EMPRESA_ID)
            ->where('origen', 'APERTURA')
            ->where('origen_tabla', 'apertura_estudio_v143')
            ->exists();
        if ($existePrevio && ! $this->dryRun) {
            throw new DomainException('APERTURA_YA_CARGADA: ya existe asiento origen=APERTURA + origen_tabla=apertura_estudio_v143. Usar --rollback primero.');
        }

        // 3. Las 5 cuentas nuevas deben existir (vienen por migración previa).
        foreach (['1.1.6.17','1.1.6.18','1.1.5.06','1.2.1.06','2.1.2.09'] as $c) {
            if (! DB::table('erp_cuentas_contables')->where('codigo', $c)->exists()) {
                throw new DomainException("CUENTA_NUEVA_FALTANTE: {$c} (correr migración v1.43_cuentas_apertura_estudio).");
            }
        }
    }

    private function abrirPeriodosNecesarios(): void
    {
        $fechas = [self::FECHA_APERTURA];
        foreach ($this->datos['asientos_post_apertura'] ?? [] as $a) {
            $fechas[] = $a['fecha'];
        }
        foreach (array_unique($fechas) as $f) {
            $c = Carbon::parse($f);
            $periodo = Periodo::where('anio', $c->year)->where('mes', $c->month)->first();
            if (! $periodo) throw new DomainException("PERIODO_NO_ENCONTRADO: {$c->year}/{$c->month}");
            if (in_array($periodo->estado, ['CERRADO', 'BLOQUEADO'], true)) {
                $this->manifest['periodos_reabiertos'][$periodo->id] = $periodo->estado;
                $periodo->update(['estado' => 'ABIERTO']);
            }
        }
    }

    private function cerrarPeriodosReabiertos(bool $dryRun): void
    {
        foreach ($this->manifest['periodos_reabiertos'] as $periodoId => $estadoOriginal) {
            if ($dryRun) {
                // En dryRun el rollback de transacción ya restauró el estado.
                continue;
            }
            DB::table('erp_periodos')->where('id', $periodoId)->update(['estado' => $estadoOriginal]);
        }
    }

    /**
     * Resuelve los IDs de cuenta del ERP para cada codigo_estudio referenciado,
     * y crea los auxiliares dummy "SALDO-APE-*" necesarios para las cuentas
     * que requieren auxiliar pero el saldo viene agrupado.
     */
    private array $cuentasIdPorCodigo = [];
    private array $auxiliaresDummyPorTipo = [];
    private int $ccFallbackId;

    private function resolverCuentasYDummies(): void
    {
        $codigosErp = array_unique(array_values(self::MAP_CUENTAS));
        $cuentas = DB::table('erp_cuentas_contables')
            ->where('empresa_id', self::EMPRESA_ID)
            ->whereIn('codigo', $codigosErp)
            ->get(['id', 'codigo', 'admite_cc', 'admite_auxiliar', 'tipo_auxiliar']);
        foreach ($cuentas as $c) $this->cuentasIdPorCodigo[$c->codigo] = $c;

        $faltantes = array_diff($codigosErp, array_keys($this->cuentasIdPorCodigo));
        if ($faltantes) {
            throw new DomainException('CUENTAS_FALTANTES: ' . implode(',', $faltantes));
        }

        // CC fallback: CENTRAL (dev) o GENERAL (prod), sino primer activo.
        $cc = DB::table('erp_centros_costo')
            ->where('empresa_id', self::EMPRESA_ID)->where('activo', 1)
            ->whereIn('codigo', ['CENTRAL', 'GENERAL'])->value('id');
        if (! $cc) {
            $cc = DB::table('erp_centros_costo')
                ->where('empresa_id', self::EMPRESA_ID)->where('activo', 1)
                ->orderBy('id')->value('id');
        }
        if (! $cc) throw new DomainException('CC_FALLBACK_NO_ENCONTRADO: ningún centro de costo activo.');
        $this->ccFallbackId = $cc;

        // Crear auxiliares dummy por cada tipo necesario.
        $tipos = ['Cliente', 'Proveedor', 'Bien', 'Empleado', 'Socio'];
        foreach ($tipos as $tipo) {
            $codigo = 'SALDO-APE-' . strtoupper($tipo);
            $aux = DB::table('erp_auxiliares')
                ->where('empresa_id', self::EMPRESA_ID)
                ->where('codigo', $codigo)->first();
            if (! $aux) {
                $id = DB::table('erp_auxiliares')->insertGetId([
                    'empresa_id' => self::EMPRESA_ID,
                    'tipo' => $tipo,
                    'codigo' => $codigo,
                    'nombre' => "Saldo apertura estudio ({$tipo})",
                    'activo' => 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->manifest['auxiliares'][] = $id;
                $this->stats['auxiliares_dummy']++;
                $this->auxiliaresDummyPorTipo[$tipo] = $id;
            } else {
                $this->auxiliaresDummyPorTipo[$tipo] = $aux->id;
            }
        }
    }

    /** Obtiene o crea un auxiliar por código (CLI-/PROV-/PLAN-/TC-/PRESTAMO-). */
    private function obtenerOCrearAuxiliar(string $codigo, string $tipo, string $nombre, ?string $cuit = null, ?int $cuentaDefaultId = null): int
    {
        $aux = DB::table('erp_auxiliares')
            ->where('empresa_id', self::EMPRESA_ID)
            ->where('codigo', $codigo)->first();
        if ($aux) {
            // Si no tenía cuenta default y se sugiere una, actualizar.
            if ($cuentaDefaultId && ! $aux->cuenta_contable_default_id) {
                DB::table('erp_auxiliares')->where('id', $aux->id)->update([
                    'cuenta_contable_default_id' => $cuentaDefaultId,
                    'updated_at' => now(),
                ]);
            }
            return $aux->id;
        }
        $id = DB::table('erp_auxiliares')->insertGetId([
            'empresa_id' => self::EMPRESA_ID,
            'tipo' => $tipo,
            'cuenta_contable_default_id' => $cuentaDefaultId,
            'codigo' => $codigo,
            'nombre' => mb_substr($nombre, 0, 190),
            'cuit' => $cuit,
            'activo' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->manifest['auxiliares'][] = $id;
        return $id;
    }

    private function crearAsientoApertura(): void
    {
        $movimientos = [];

        foreach ($this->datos['asiento_apertura'] as $l) {
            $codEstudio = (string) $l['codigo_estudio'];
            $codErp = self::MAP_CUENTAS[$codEstudio] ?? null;
            if (! $codErp) throw new DomainException("MAPEO_FALTANTE: codigo_estudio {$codEstudio}");
            $cuenta = $this->cuentasIdPorCodigo[$codErp];

            if (in_array($codEstudio, self::LINEAS_DESGLOSADAS, true)) {
                $movimientos = array_merge($movimientos, $this->desglosarLinea($codEstudio, $l, $cuenta));
                continue;
            }

            $auxId = null;
            if ($cuenta->admite_auxiliar) {
                $codigoAuxEspecifico = self::AUXILIAR_POR_LINEA[$codEstudio] ?? null;
                if ($codigoAuxEspecifico) {
                    $tipoAux = $cuenta->tipo_auxiliar ?: $this->tipoAuxDesdeCodigo($codigoAuxEspecifico);
                    $auxId = $this->obtenerOCrearAuxiliar(
                        $codigoAuxEspecifico,
                        $tipoAux,
                        $l['descripcion_estudio'] ?? $codigoAuxEspecifico,
                        cuentaDefaultId: $cuenta->id,
                    );
                } else {
                    $tipo = $cuenta->tipo_auxiliar ?: 'Bien';
                    $auxId = $this->auxiliaresDummyPorTipo[$tipo] ?? $this->auxiliaresDummyPorTipo['Bien'];
                }
            }
            $ccId = $cuenta->admite_cc ? $this->ccFallbackId : null;

            $movimientos[] = [
                'cuenta_id' => $cuenta->id,
                'centro_costo_id' => $ccId,
                'auxiliar_id' => $auxId,
                'debe' => round((float) $l['debe'], 2),
                'haber' => round((float) $l['haber'], 2),
                'glosa' => mb_substr('Apertura — ' . ($l['descripcion_estudio'] ?? $codErp), 0, 250),
            ];
        }

        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => self::EMPRESA_ID,
            'diario_id' => $this->diarioApertura(),
            'fecha' => self::FECHA_APERTURA,
            'glosa' => 'Apertura de cuentas patrimoniales — Modelo estudio contable',
            'origen' => 'APERTURA',
            'origen_id' => 1,
            'origen_tabla' => 'apertura_estudio_v143',
            'observaciones' => 'Carga programática vía apertura:cargar-estudio (v1.43).',
            'usuario_id' => $this->userId,
            'movimientos' => $movimientos,
        ]);
        $this->asientoService->contabilizar($asiento);

        $this->stats['asiento_apertura_lineas'] = count($movimientos);
        $this->manifest['asientos'][] = $asiento->id;
    }

    /**
     * Desglosa una línea agregada (Deudores / Proveedores) en líneas por
     * auxiliar individual.
     *
     * @param  object  $cuenta  fila de erp_cuentas_contables
     * @return array<int,array>
     */
    private function desglosarLinea(string $codEstudio, array $linea, object $cuenta): array
    {
        $movs = [];
        if ($codEstudio === '11301') {
            $items = $this->datos['clientes'] ?? [];
            $tipo = 'Cliente';
            $prefijo = 'CLI-';
        } elseif ($codEstudio === '21101') {
            $items = $this->datos['proveedores'] ?? [];
            $tipo = 'Proveedor';
            $prefijo = 'PROV-';
        } else {
            throw new DomainException("DESGLOSE_INVALIDO: {$codEstudio}");
        }

        $sumaDesglose = 0;
        foreach ($items as $it) {
            $cuit = preg_replace('/[^0-9]/', '', (string) ($it['cuit'] ?? ''));
            $codigo = $prefijo . ($cuit ?: substr(md5($it['razon_social']), 0, 11));
            $auxId = $this->obtenerOCrearAuxiliar(
                $codigo, $tipo, $it['razon_social'] ?? $codigo, $cuit ?: null, $cuenta->id,
            );
            $importe = round((float) $it['saldo_inicial'], 2);
            $sumaDesglose = round($sumaDesglose + $importe, 2);

            // Para cuentas activas (Deudores), debe; para pasivas (Proveedores), haber.
            $debe = (float) ($linea['debe'] ?? 0) > 0 ? $importe : 0.0;
            $haber = (float) ($linea['haber'] ?? 0) > 0 ? $importe : 0.0;

            $movs[] = [
                'cuenta_id' => $cuenta->id,
                'centro_costo_id' => $cuenta->admite_cc ? $this->ccFallbackId : null,
                'auxiliar_id' => $auxId,
                'debe' => $debe,
                'haber' => $haber,
                'glosa' => mb_substr('Apertura — ' . ($it['razon_social'] ?? $codigo), 0, 250),
            ];

            $codEstudio === '11301' ? $this->stats['clientes_cargados']++ : $this->stats['proveedores_cargados']++;
        }

        $esperado = round((float) max($linea['debe'] ?? 0, $linea['haber'] ?? 0), 2);
        if (abs($sumaDesglose - $esperado) > self::TOLERANCIA_CUADRE) {
            throw new DomainException(sprintf(
                'DESGLOSE_NO_CUADRA: %s suma=%.2f esperado=%.2f diff=%.2f',
                $codEstudio, $sumaDesglose, $esperado, $sumaDesglose - $esperado,
            ));
        }

        return $movs;
    }

    /** Enum erp_auxiliares.tipo: Cliente/Proveedor/Distribuidor/Empleado/Socio/Vehiculo/Sucursal/Colocacion/Bien/Organismo. */
    private function tipoAuxDesdeCodigo(string $codigo): string
    {
        if (str_starts_with($codigo, 'PLAN-')) return 'Organismo';      // AFIP/ARCA
        if (str_starts_with($codigo, 'PRESTAMO-')) return 'Organismo';  // Banco
        if (str_starts_with($codigo, 'TC-')) return 'Organismo';        // Banco emisor tarjeta
        return 'Proveedor';
    }

    private function diarioApertura(): int
    {
        return DB::table('erp_diarios')
            ->where('empresa_id', self::EMPRESA_ID)
            ->where('codigo', 'APE')->value('id')
            ?? throw new DomainException('DIARIO_APE_NO_ENCONTRADO');
    }

    private function cargarBienesUso(): void
    {
        $categorias = DB::table('erp_af_categorias')->get()->keyBy('codigo');

        $counter = 1;
        foreach ($this->datos['bienes_uso_historicos'] as $b) {
            $catCodigo = self::RUBRO_CATEGORIA[$b['rubro']] ?? null;
            if (! $catCodigo || ! isset($categorias[$catCodigo])) {
                throw new DomainException("CATEGORIA_AF_NO_MAPEADA: rubro={$b['rubro']}");
            }
            $cat = $categorias[$catCodigo];
            $nroInv = 'APE-V143-' . str_pad((string) $counter++, 4, '0', STR_PAD_LEFT);
            $valorOrigen = round((float) $b['valor_origen_ajustado'], 2);
            $amortAcumInicio = round((float) ($b['amort_acum_inicio'] ?? 0), 2);
            $amortEjercicio = round((float) ($b['amort_ejercicio'] ?? 0), 2);
            $amortAcumCierre = round((float) ($b['amort_acum_cierre'] ?? ($amortAcumInicio + $amortEjercicio)), 2);

            $bienId = DB::table('erp_af_bienes')->insertGetId([
                'empresa_id' => self::EMPRESA_ID,
                'nro_inventario' => $nroInv,
                'categoria_id' => $cat->id,
                'descripcion' => mb_substr(str_replace('|', ' ', (string) $b['descripcion']), 0, 250),
                'fecha_alta' => $b['fecha_incorporacion'],
                'valor_origen' => $valorOrigen,
                'moneda_origen' => 'ARS',
                'valor_residual_cfg' => 0,
                'vida_util_contable_meses' => $cat->vida_util_contable_meses,
                'vida_util_fiscal_meses' => $cat->vida_util_fiscal_meses,
                'centro_costo_id' => $this->ccFallbackId,
                'estado' => 'ALTA',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->manifest['af_bienes'][] = $bienId;
            $this->stats['bienes_cargados']++;

            // Amortización acumulada al 2025-12 (período base anterior a la apertura).
            $amortId = DB::table('erp_af_amortizaciones')->insertGetId([
                'bien_id' => $bienId,
                'periodo_anio' => 2025,
                'periodo_mes' => 12,
                'base_amort_contable' => $valorOrigen,
                'amort_contable_mes' => 0,
                'amort_contable_acum' => $amortAcumCierre,
                'base_amort_fiscal' => $valorOrigen,
                'amort_fiscal_mes' => 0,
                'amort_fiscal_acum' => $amortAcumCierre,
                'generado_at' => now(),
                'created_at' => now(),
            ]);
            $this->manifest['af_amortizaciones'][] = $amortId;
            $this->stats['amortizaciones_cargadas']++;
        }
    }

    private function cargarPrestamoIcbc(): void
    {
        // Préstamo ICBC: saldo va en el asiento, este paso crea el header en
        // erp_prestamos con saldo capital actual. Sin cronograma detallado
        // (queda para v1.44).
        $entry = collect($this->datos['deudas_bancarias'] ?? [])->firstWhere('tipo', 'Préstamo');
        if (! $entry) return;

        $detalle = $this->datos['prestamo_icbc_detalle'] ?? [];
        $auxCodigo = 'PRESTAMO-ICBC-' . ltrim((string) ($detalle['numero_prestamo'] ?? '204720603'), '0');
        $auxId = $this->obtenerOCrearAuxiliar(
            $auxCodigo, 'Acreedor', 'Banco ICBC — Préstamo ' . ($detalle['numero_prestamo'] ?? ''),
            preg_replace('/[^0-9]/', '', (string) ($entry['cuit'] ?? '')) ?: null,
            $this->cuentasIdPorCodigo['2.2.1.01']->id,
        );

        $capital = (float) $entry['importe'];
        $cuotasPendientes = (int) ($detalle['cuotas_pendientes_al_2025_12_31'] ?? 16);
        $tasaMensualPct = (float) ($detalle['tasa_mensual_pct'] ?? 3.083);
        $proximaFecha = $detalle['proxima_cuota_fecha_vencimiento'] ?? '2026-05-07';

        $prestId = DB::table('erp_prestamos')->insertGetId([
            'empresa_id' => self::EMPRESA_ID,
            'tipo' => 'RECIBIDO',
            'contraparte_auxiliar_id' => $auxId,
            'nombre' => 'Préstamo Banco ICBC Nro. ' . ($detalle['numero_prestamo'] ?? '204720603'),
            'capital' => $capital,
            'moneda' => 'ARS',
            'tasa_mensual' => $tasaMensualPct,
            'sistema_amortizacion' => 'FRANCES',
            'plazo_cuotas' => $cuotasPendientes,
            'fecha_otorgamiento' => self::FECHA_APERTURA,
            'fecha_primera_cuota' => $proximaFecha,
            'estado' => 'VIGENTE',
            'cuenta_contable_id' => $this->cuentasIdPorCodigo['2.2.1.01']->id,
            'observaciones' => 'Cargado por v1.43 apertura estudio. Capital pendiente al 2026-01-01. Cronograma completo pendiente (v1.44).',
            'created_at' => now(),
        ]);
        $this->manifest['prestamos'][] = $prestId;
        $this->stats['prestamos_cargados']++;
    }

    private function cargarAsientosPostApertura(): void
    {
        foreach ($this->datos['asientos_post_apertura'] ?? [] as $a) {
            $movs = [];
            foreach ($a['lineas'] as $l) {
                $cuenta = DB::table('erp_cuentas_contables')
                    ->where('empresa_id', self::EMPRESA_ID)
                    ->where('codigo', $l['cuenta_erp'])->first();
                if (! $cuenta) throw new DomainException('CUENTA_NO_ENCONTRADA_POST: ' . $l['cuenta_erp']);

                $auxId = null;
                if ($cuenta->admite_auxiliar) {
                    if (! empty($l['auxiliar'])) {
                        $auxId = $this->obtenerOCrearAuxiliar(
                            $l['auxiliar'], $cuenta->tipo_auxiliar ?: 'Organismo',
                            $l['auxiliar_nombre'] ?? $l['auxiliar'], null, $cuenta->id,
                        );
                    } else {
                        $auxId = $this->auxiliaresDummyPorTipo[$cuenta->tipo_auxiliar ?? 'Proveedor']
                            ?? $this->auxiliaresDummyPorTipo['Proveedor'];
                    }
                }
                $movs[] = [
                    'cuenta_id' => $cuenta->id,
                    'centro_costo_id' => $cuenta->admite_cc ? $this->ccFallbackId : null,
                    'auxiliar_id' => $auxId,
                    'debe' => round((float) $l['debe'], 2),
                    'haber' => round((float) $l['haber'], 2),
                    'glosa' => mb_substr($a['glosa'] ?? $a['concepto'] ?? '', 0, 250),
                ];
            }
            $asiento = $this->asientoService->crearBorrador([
                'empresa_id' => self::EMPRESA_ID,
                'diario_id' => $this->diarioApertura(),
                'fecha' => $a['fecha'],
                'glosa' => $a['glosa'] ?? $a['concepto'],
                'origen' => 'APERTURA',
                'origen_id' => 2,
                'origen_tabla' => 'apertura_estudio_v143',
                'observaciones' => 'Asiento post-apertura (' . $a['id_logico'] . ').',
                'usuario_id' => $this->userId,
                'movimientos' => $movs,
            ]);
            $this->asientoService->contabilizar($asiento);
            $this->manifest['asientos'][] = $asiento->id;
            $this->stats['asientos_post_apertura']++;
        }
    }

    private function validarPostcarga(): void
    {
        $debeEsperado = round(collect($this->datos['asiento_apertura'])->sum('debe'), 2);
        $sumaClientes = round(collect($this->datos['clientes'])->sum('saldo_inicial'), 2);
        $sumaProv = round(collect($this->datos['proveedores'])->sum('saldo_inicial'), 2);

        $asientoId = $this->manifest['asientos'][0];
        $totales = DB::table('erp_asientos')->where('id', $asientoId)->first(['total_debe', 'total_haber']);
        if (abs((float) $totales->total_debe - $debeEsperado) > self::TOLERANCIA_CUADRE) {
            throw new DomainException(sprintf('VALIDACION_TOTAL_DEBE: got=%.2f esperado=%.2f', $totales->total_debe, $debeEsperado));
        }
        if (abs((float) $totales->total_haber - $debeEsperado) > self::TOLERANCIA_CUADRE) {
            throw new DomainException(sprintf('VALIDACION_TOTAL_HABER: got=%.2f esperado=%.2f', $totales->total_haber, $debeEsperado));
        }

        $cuentaCli = $this->cuentasIdPorCodigo['1.1.4.01']->id;
        $sumDebeCli = (float) DB::table('erp_movimientos_asiento')
            ->where('asiento_id', $asientoId)->where('cuenta_id', $cuentaCli)
            ->sum('debe');
        if (abs($sumDebeCli - $sumaClientes) > self::TOLERANCIA_CUADRE) {
            throw new DomainException(sprintf('VALIDACION_SUM_CLIENTES: got=%.2f esperado=%.2f', $sumDebeCli, $sumaClientes));
        }

        $cuentaProv = $this->cuentasIdPorCodigo['2.1.1.01']->id;
        $sumHaberProv = (float) DB::table('erp_movimientos_asiento')
            ->where('asiento_id', $asientoId)->where('cuenta_id', $cuentaProv)
            ->sum('haber');
        if (abs($sumHaberProv - $sumaProv) > self::TOLERANCIA_CUADRE) {
            throw new DomainException(sprintf('VALIDACION_SUM_PROVEEDORES: got=%.2f esperado=%.2f', $sumHaberProv, $sumaProv));
        }
    }

    private function guardarManifest(): void
    {
        $path = storage_path('app/apertura_estudio_v143_manifest.json');
        file_put_contents($path, json_encode([
            'cargado_at' => now()->toIso8601String(),
            'user_id' => $this->userId,
            'stats' => $this->stats,
        ] + $this->manifest, JSON_PRETTY_PRINT));
    }
}

<?php

namespace App\Erp\Services\Integracion;

use App\Erp\Models\Auxiliar;
use App\Erp\Models\CuentaContable;
use App\Erp\Models\VentasCompras\FacturaCompra;
use App\Erp\Services\AsientoService;
use App\Erp\Services\FacturaCompraService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * v1.54 — Sync de facturas de compra DistriApp ↔ ERP.
 *
 * Flujo: DistriApp aprueba → webhook → factura en PENDIENTE_AUTORIZACION_ERP →
 * operador autoriza (asiento vía ContabilizadorFacturas, estado CONTROLADA) →
 * desautorizar = reversa fecha hoy + vuelta a PENDIENTE. Borrados en ambos
 * sentidos con protección si ya hay asiento.
 *
 * "CONTABILIZADA" del spec = CONTROLADA real (estado con asiento generado).
 */
class SyncFacturaCompraDistriAppService
{
    /** Mapeo cliente de la liquidación → cuenta de costo (D-54 recordatorio 4). OCASA antes que OCA. */
    private const CUENTA_COSTO_POR_CLIENTE = [
        'OCASA' => '5.1.1.03',
        'OCA' => '5.1.1.01',
        'URBANO' => '5.1.1.02',
        'LOGINTER' => '5.1.1.04',
    ];

    private const CUENTA_COSTO_OTROS = '5.1.1.05';
    private const CUENTA_DISTRIBUIDORES = '2.1.1.03';

    public function __construct(
        private readonly ContabilizadorFacturas $contabilizador,
        private readonly AsientoService $asientoService,
        private readonly DistriAppNotificaciones $notificaciones,
        private readonly AuditLogger $audit,
    ) {}

    // ------------------------------------------------------------------
    // Bloque A — webhook FACTURA_APROBADA
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload body completo del webhook (§4.2)
     */
    public function sincronizar(array $payload): FacturaCompra
    {
        $daId = trim((string) ($payload['distriapp_factura_id'] ?? ''));
        $fac = $payload['factura'] ?? null;
        if ($daId === '' || ! is_array($fac)) {
            throw new DomainException('DATOS_INCOMPLETOS: faltan distriapp_factura_id o factura.');
        }

        // Idempotencia: mismo distriapp_factura_id no duplica.
        $existente = FacturaCompra::withTrashed()->where('distriapp_factura_id', $daId)->first();
        if ($existente) {
            throw new DomainException("FACTURA_YA_SINCRONIZADA: erp_id={$existente->id} estado={$existente->estado}");
        }

        $emisor = $fac['emisor'] ?? [];
        $cuit = preg_replace('/\D/', '', (string) ($emisor['cuit'] ?? ''));
        if (strlen($cuit) !== 11) {
            throw new DomainException('CUIT_INVALIDO: el CUIT del emisor debe tener 11 dígitos.');
        }

        foreach (['tipo_comprobante', 'punto_venta', 'numero', 'fecha_emision'] as $campo) {
            if (empty($fac[$campo])) {
                throw new DomainException("DATOS_INCOMPLETOS: falta factura.{$campo}.");
            }
        }

        $importes = $fac['importes'] ?? [];
        $total = round((float) ($importes['total'] ?? 0), 2);
        if ($total <= 0) {
            throw new DomainException('DATOS_INCOMPLETOS: factura.importes.total debe ser > 0.');
        }

        // Importes cuadran: neto+iva+no_gravado+exento+tributos == total, y suma items == total.
        $sumaConceptos = round(
            (float) ($importes['neto_gravado'] ?? 0) + (float) ($importes['iva'] ?? 0)
            + (float) ($importes['no_gravado'] ?? 0) + (float) ($importes['exento'] ?? 0)
            + (float) ($importes['tributos'] ?? 0), 2);
        if (abs($sumaConceptos - $total) > 0.01) {
            throw new DomainException("IMPORTES_NO_CUADRAN: conceptos suman {$sumaConceptos} ≠ total {$total}.");
        }
        $items = $fac['items'] ?? [];
        if ($items !== []) {
            $sumaItems = round(array_sum(array_map(fn ($i) => (float) ($i['subtotal'] ?? 0), $items)), 2);
            if (abs($sumaItems - $total) > 0.01) {
                throw new DomainException("IMPORTES_NO_CUADRAN: items suman {$sumaItems} ≠ total {$total}.");
            }
        }

        $tipoComprobanteId = $this->resolverTipoComprobante($fac);

        // Comprobante único: tipo + PV + número + CUIT emisor (con otro distriapp_id o manual).
        $pv = (int) $fac['punto_venta'];
        $nro = (int) $fac['numero'];
        $dup = FacturaCompra::where('tipo_comprobante_id', $tipoComprobanteId)
            ->where('punto_venta', $pv)->where('numero', $nro)
            ->where('cuit_emisor', $cuit)->first();
        if ($dup) {
            throw new DomainException("COMPROBANTE_DUPLICADO: ya existe factura erp_id={$dup->id} con ese tipo+PV+número+CUIT.");
        }

        return DB::transaction(function () use ($payload, $daId, $fac, $emisor, $cuit, $importes, $total, $tipoComprobanteId, $pv, $nro) {
            $auxiliar = $this->buscarOCrearAuxiliar($cuit, $emisor);

            $ivaDesag = collect($fac['iva_desagregado'] ?? []);
            $ivaPor = fn (float $alic) => round((float) (($ivaDesag->firstWhere('alicuota', $alic) ?? [])['importe'] ?? 0), 2);
            $netoPor = fn (float $alic) => round((float) (($ivaDesag->firstWhere('alicuota', $alic) ?? [])['neto'] ?? 0), 2);

            $factura = FacturaCompra::create([
                'empresa_id' => 1,
                'tipo_comprobante_id' => $tipoComprobanteId,
                'punto_venta' => $pv,
                'numero' => $nro,
                'fecha_emision' => $fac['fecha_emision'],
                // v1.56 — período de imputación = mes de emisión, salvo período
                // cerrado → primer día del primer mes siguiente abierto (regla
                // Libro IVA: el día no importa, manda el mes).
                'fecha_imputacion' => $this->resolverFechaImputacion($fac['fecha_emision']),
                'fecha_recepcion' => now()->toDateString(),
                'fecha_vencimiento' => $fac['fecha_vto_pago'] ?? null,
                'auxiliar_id' => $auxiliar->id,
                'cuit_emisor' => $cuit,
                'razon_social_emisor' => (string) ($emisor['razon_social'] ?? $auxiliar->nombre),
                'condicion_iva_id' => $this->resolverCondicionIva((string) ($emisor['condicion_iva'] ?? '')),
                'moneda_id' => 1,
                'imp_neto_gravado' => (float) ($importes['neto_gravado'] ?? 0),
                'imp_no_gravado' => (float) ($importes['no_gravado'] ?? 0),
                'imp_exento' => (float) ($importes['exento'] ?? 0),
                'imp_iva' => (float) ($importes['iva'] ?? 0),
                'imp_otros_tributos' => (float) ($importes['tributos'] ?? 0),
                'imp_iva_21' => $ivaPor(21.0),
                'imp_iva_10_5' => $ivaPor(10.5),
                'imp_iva_27' => $ivaPor(27.0),
                'imp_neto_gravado_21' => $netoPor(21.0),
                'imp_neto_gravado_10_5' => $netoPor(10.5),
                'imp_neto_gravado_27' => $netoPor(27.0),
                'imp_total' => $total,
                'origen' => 'DISTRIAPP',
                'estado' => 'PENDIENTE_AUTORIZACION_ERP',
                'constatacion_estado' => 'PENDIENTE',
                'adjunto_url' => $fac['pdf_url'] ?? null,
                'observaciones' => $this->armarObservacion($payload),
                'periodo_trabajado_texto' => $this->periodoTrabajado($payload),
                // v1.56 — jurisdicción IIBB resuelta en DistriApp por la
                // sucursal del distribuidor (null si la sucursal no tiene
                // mapping: queda visible vacía para completar a mano).
                'jurisdiccion_codigo' => $fac['jurisdiccion_codigo'] ?? null,
                'created_by_user_id' => null,
            ]);

            $factura->forceFill([
                'distriapp_factura_id' => $daId,
                'distriapp_liquidacion_id' => $payload['distriapp_liquidacion_id'] ?? ($payload['liquidacion']['id'] ?? null),
                'sincronizada_desde_distriapp' => 1,
                'sincronizada_en' => now(),
                'sync_payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ])->save();

            $this->log($factura->id, $daId, 'APROBADA', 'DISTRIAPP_A_ERP', $payload, 201);

            return $factura->fresh(['auxiliar', 'tipoComprobante']);
        });
    }

    // ------------------------------------------------------------------
    // Bloque B — autorizar (genera asiento, estado CONTROLADA)
    // ------------------------------------------------------------------

    /** @return array{cuenta_gasto: CuentaContable, cuenta_contrapartida: CuentaContable, cliente: ?string} */
    public function previewCuentas(FacturaCompra $factura): array
    {
        $cliente = $this->clienteLiquidacion($factura);
        $codigoGasto = $this->mapearCuentaCosto($cliente);

        return [
            'cuenta_gasto' => CuentaContable::where('empresa_id', $factura->empresa_id)->where('codigo', $codigoGasto)->firstOrFail(),
            'cuenta_contrapartida' => CuentaContable::where('empresa_id', $factura->empresa_id)->where('codigo', self::CUENTA_DISTRIBUIDORES)->firstOrFail(),
            'cliente' => $cliente,
        ];
    }

    public function autorizar(FacturaCompra $factura, int $usuarioId): FacturaCompra
    {
        if ($factura->estado !== 'PENDIENTE_AUTORIZACION_ERP') {
            throw new DomainException("FACTURA_NO_ESTA_PENDIENTE: estado actual {$factura->estado}.");
        }
        $auxiliar = Auxiliar::findOrFail($factura->auxiliar_id);
        if (empty($auxiliar->tipo)) {
            throw new DomainException('AUXILIAR_SIN_TIPO_DEFINIDO: el auxiliar no tiene tipo.');
        }

        $cuentaGastoId = $this->previewCuentas($factura)['cuenta_gasto']->id;

        // v1.56 — si el período de la imputación se cerró mientras la factura
        // esperaba autorización, correrla al primer mes abierto (el asiento
        // usa fecha_imputacion y rebotaría con PERIODO_BLOQUEADO).
        $fechaImpActual = $factura->fecha_imputacion instanceof \DateTimeInterface
            ? $factura->fecha_imputacion->format('Y-m-d')
            : (string) ($factura->fecha_imputacion ?: $factura->fecha_emision);
        $fechaImpNueva = $this->resolverFechaImputacion($fechaImpActual);

        return DB::transaction(function () use ($factura, $usuarioId, $cuentaGastoId, $fechaImpActual, $fechaImpNueva) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            if ($fechaImpNueva !== $fechaImpActual) {
                $factura->update(['fecha_imputacion' => $fechaImpNueva]);
                $this->log($factura->id, (string) $factura->distriapp_factura_id, 'REIMPUTADA', 'DISTRIAPP_A_ERP',
                    ['de' => $fechaImpActual, 'a' => $fechaImpNueva, 'motivo' => 'PERIODO_CERRADO'], 200, $usuarioId);
            }

            $asiento = $this->contabilizador->contabilizarCompra($factura->id, $factura->empresa_id, $usuarioId, $cuentaGastoId);

            $factura->update([
                'estado' => FacturaCompraService::ESTADO_CONTROLADA,
                'asiento_id' => $asiento->id,
                'controlada_by_user_id' => $usuarioId,
                'controlada_at' => now(),
            ]);

            $this->log($factura->id, (string) $factura->distriapp_factura_id, 'AUTORIZADA', 'DISTRIAPP_A_ERP', null, 200, $usuarioId);
            $this->audit->logEvento(
                accion: 'FACTURA_COMPRA_SYNC_AUTORIZADA',
                modulo: 'compras',
                descripcion: sprintf('Factura sincronizada #%d (%s) autorizada · asiento #%d · $%.2f',
                    $factura->id, $factura->distriapp_factura_id, $asiento->numero, (float) $factura->imp_total),
                empresaId: $factura->empresa_id,
            );

            return $factura->fresh(['auxiliar', 'asiento']);
        });
    }

    // ------------------------------------------------------------------
    // Bloque C — webhook FACTURA_BORRADA (DistriApp → ERP)
    // ------------------------------------------------------------------

    /**
     * @return array{status:int, body:array<string,mixed>}
     */
    public function borrarDesdeDistriApp(array $payload): array
    {
        $daId = trim((string) ($payload['distriapp_factura_id'] ?? ''));
        $factura = FacturaCompra::where('distriapp_factura_id', $daId)->first();

        // Idempotente: no existe (o ya borrada) → 200.
        if (! $factura) {
            $this->log(null, $daId, 'BORRADA', 'DISTRIAPP_A_ERP', $payload, 200);

            return ['status' => 200, 'body' => ['ok' => true, 'mensaje' => 'Factura inexistente o ya borrada en ERP (idempotente).']];
        }

        if ($factura->estado !== 'PENDIENTE_AUTORIZACION_ERP') {
            $this->log($factura->id, $daId, 'ERROR', 'DISTRIAPP_A_ERP', $payload, 409, null, 'FACTURA_YA_AUTORIZADA');

            return ['status' => 409, 'body' => [
                'error' => 'FACTURA_YA_AUTORIZADA',
                'detalle' => 'La factura ya fue autorizada y contabilizada en el ERP. Debe desautorizarse desde el ERP antes de borrar.',
                'erp_factura_compra_id' => $factura->id,
                'estado_erp' => $factura->estado,
                'asiento_id' => $factura->asiento_id,
                'url_ver_en_erp' => 'https://erp.distriapp.com.ar/erp/compras/facturas-de-compra',
            ]];
        }

        DB::transaction(function () use ($factura, $daId, $payload) {
            $this->log($factura->id, $daId, 'BORRADA', 'DISTRIAPP_A_ERP', $payload, 200);
            $factura->delete(); // soft delete
        });

        return ['status' => 200, 'body' => ['ok' => true, 'erp_factura_compra_id' => $factura->id, 'mensaje' => 'Factura borrada en ERP.']];
    }

    // ------------------------------------------------------------------
    // Bloque D — desautorizar (reversa + vuelta a PENDIENTE)
    // ------------------------------------------------------------------

    public function desautorizar(FacturaCompra $factura, string $motivo, int $usuarioId): FacturaCompra
    {
        if (! $factura->sincronizada_desde_distriapp) {
            throw new DomainException('FACTURA_NO_SINCRONIZADA: desautorizar aplica solo a facturas sincronizadas desde DistriApp.');
        }
        if ($factura->estado !== FacturaCompraService::ESTADO_CONTROLADA || ! $factura->asiento_id) {
            throw new DomainException("FACTURA_NO_AUTORIZADA: estado actual {$factura->estado} — solo se desautoriza una factura autorizada con asiento.");
        }
        if (mb_strlen(trim($motivo)) < 10) {
            throw new DomainException('MOTIVO_CORTO: mínimo 10 caracteres.');
        }
        $tieneOp = DB::table('erp_op_items as i')
            ->join('erp_ordenes_pago as op', 'op.id', '=', 'i.op_id')
            ->where('i.tipo_item', 'FACTURA_COMPRA')
            ->where('i.comprobante_id', $factura->id)
            ->whereNotIn('op.estado', ['ANULADA', 'RECHAZADA'])
            ->exists();
        if ($tieneOp) {
            throw new DomainException('FACTURA_CON_PAGOS: tiene órdenes de pago aplicadas — desaplicá los pagos antes de desautorizar.');
        }
        if ($factura->estado === 'ANULADA_POR_NC') {
            throw new DomainException('FACTURA_CON_NC: está anulada por NC.');
        }

        return DB::transaction(function () use ($factura, $motivo, $usuarioId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            // AsientoService::anular genera la reversa D/H espejo con fecha de
            // HOY (lección v1.51 — no tocar períodos anteriores).
            $asiento = \App\Erp\Models\Asiento::findOrFail($factura->asiento_id);
            $this->asientoService->anular($asiento, $usuarioId, 'Desautorización factura sync DistriApp: '.trim($motivo));

            $factura->update([
                'estado' => 'PENDIENTE_AUTORIZACION_ERP',
                'asiento_id' => null,
                'controlada_by_user_id' => null,
                'controlada_at' => null,
            ]);

            $this->log($factura->id, (string) $factura->distriapp_factura_id, 'DESAUTORIZADA', 'DISTRIAPP_A_ERP', ['motivo' => $motivo], 200, $usuarioId);
            $this->audit->logEvento(
                accion: 'FACTURA_COMPRA_SYNC_DESAUTORIZADA',
                modulo: 'compras',
                descripcion: sprintf('Factura sincronizada #%d (%s) desautorizada · motivo: %s',
                    $factura->id, $factura->distriapp_factura_id, trim($motivo)),
                empresaId: $factura->empresa_id,
            );

            // Webhook reverso best-effort (no bloquea la operación).
            $this->notificaciones->notificarDesvinculacion($factura, 'DESAUTORIZADA_EN_ERP', trim($motivo));

            return $factura->fresh(['auxiliar']);
        });
    }

    // ------------------------------------------------------------------
    // Bloque E — borrar desde el ERP (con webhook reverso)
    // ------------------------------------------------------------------

    public function borrarDesdeErp(FacturaCompra $factura, int $usuarioId): void
    {
        if ($factura->estado !== 'PENDIENTE_AUTORIZACION_ERP') {
            throw new DomainException("FACTURA_NO_BORRABLE: estado actual {$factura->estado} — si está autorizada, primero desautorizá.");
        }

        DB::transaction(function () use ($factura, $usuarioId) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);
            $this->log($factura->id, (string) $factura->distriapp_factura_id, 'BORRADA', 'ERP_A_DISTRIAPP', null, 200, $usuarioId);
            $factura->delete();
        });

        if ($factura->sincronizada_desde_distriapp) {
            $this->notificaciones->notificarDesvinculacion($factura, 'BORRADA_EN_ERP', 'Borrada desde el ERP');
        }
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Matching de auxiliar por CUIT normalizado (lección v1.36):
     * 1) CUIT exacto tipo Distribuidor, después Proveedor.
     * 2) Sin CUIT match: nombre normalizado exacto entre Distribuidores sin CUIT
     *    (los DA-DIST-* de plataforma suelen venir sin CUIT) → adopta y completa.
     * 3) Crea nuevo tipo Distribuidor (cuenta default 2.1.1.03).
     */
    private function buscarOCrearAuxiliar(string $cuit, array $emisor): Auxiliar
    {
        $porCuit = Auxiliar::where('cuit', $cuit)
            ->whereIn('tipo', ['Distribuidor', 'Proveedor'])
            ->orderByRaw("FIELD(tipo,'Distribuidor','Proveedor')")
            ->first();
        if ($porCuit) {
            return $porCuit;
        }

        $nombre = trim((string) ($emisor['razon_social'] ?? ''));
        if ($nombre !== '') {
            $normalizado = mb_strtoupper($nombre);
            $candidatos = Auxiliar::where('tipo', 'Distribuidor')
                ->whereNull('cuit')
                ->where('razon_social_normalizada', $normalizado)
                ->get();
            if ($candidatos->count() === 1) {
                $adoptado = $candidatos->first();
                $adoptado->update([
                    'cuit' => $cuit,
                    'condicion_iva_id' => $this->resolverCondicionIva((string) ($emisor['condicion_iva'] ?? '')) ?: $adoptado->condicion_iva_id,
                ]);

                return $adoptado->fresh();
            }
        }

        $cuentaDefault = CuentaContable::where('empresa_id', 1)->where('codigo', self::CUENTA_DISTRIBUIDORES)->first();

        return Auxiliar::create([
            'empresa_id' => 1,
            'tipo' => 'Distribuidor',
            'codigo' => 'DIST-'.$cuit,
            'nombre' => $nombre !== '' ? $nombre : 'Distribuidor CUIT '.$cuit,
            'cuit' => $cuit,
            'cuenta_contable_default_id' => $cuentaDefault?->id,
            'condicion_iva_id' => $this->resolverCondicionIva((string) ($emisor['condicion_iva'] ?? '')),
            'activo' => 1,
        ]);
    }

    /** Letra/código AFIP del webhook → erp_tipos_comprobante.id (= código AFIP). */
    private function resolverTipoComprobante(array $fac): int
    {
        if (! empty($fac['cbte_tipo_afip'])) {
            $id = (int) $fac['cbte_tipo_afip'];
            if (DB::table('erp_tipos_comprobante')->where('id', $id)->exists()) {
                return $id;
            }
            throw new DomainException("DATOS_INCOMPLETOS: cbte_tipo_afip {$id} no existe en el catálogo.");
        }
        $letra = strtoupper(trim((string) ($fac['tipo_comprobante'] ?? '')));
        $id = match ($letra) {
            'A' => 1, 'B' => 6, 'C' => 11,
            default => throw new DomainException("DATOS_INCOMPLETOS: tipo_comprobante '{$letra}' no reconocido (mandar cbte_tipo_afip)."),
        };

        return $id;
    }

    private function resolverCondicionIva(string $texto): ?int
    {
        $t = mb_strtoupper($texto);

        return match (true) {
            str_contains($t, 'MONOTRIBUTO') => 6,
            str_contains($t, 'INSCRIPTO') || $t === 'RI' => 1,
            str_contains($t, 'EXENTO') => 4,
            str_contains($t, 'CONSUMIDOR') => 5,
            str_contains($t, 'NO ALCANZADO') || str_contains($t, 'NO CATEGORIZADO') => 15,
            default => 6, // distribuidores son monotributistas en la práctica
        };
    }

    private function mapearCuentaCosto(?string $cliente): string
    {
        $nombre = mb_strtoupper((string) $cliente);
        foreach (self::CUENTA_COSTO_POR_CLIENTE as $match => $codigo) {
            if ($nombre !== '' && str_contains($nombre, $match)) {
                return $codigo;
            }
        }

        return self::CUENTA_COSTO_OTROS;
    }

    private function clienteLiquidacion(FacturaCompra $factura): ?string
    {
        $payload = is_string($factura->sync_payload_json)
            ? json_decode($factura->sync_payload_json, true)
            : $factura->sync_payload_json;

        return $payload['liquidacion']['cliente'] ?? null;
    }

    private function armarObservacion(array $payload): ?string
    {
        $liq = $payload['liquidacion'] ?? null;
        if (! $liq) {
            return null;
        }

        return sprintf('DistriApp — Liquidación #%s · %s · %s/%s%s',
            $liq['id'] ?? '?', $liq['cliente'] ?? 'cliente s/d',
            $liq['mes'] ?? '?', $liq['año'] ?? $liq['anio'] ?? '?',
            isset($liq['quincena']) ? ' Q'.$liq['quincena'] : '');
    }

    private function periodoTrabajado(array $payload): ?string
    {
        $liq = $payload['liquidacion'] ?? null;
        if (! $liq || empty($liq['mes'])) {
            return null;
        }
        $anio = $liq['año'] ?? $liq['anio'] ?? null;
        if (! $anio) {
            return null;
        }
        $base = sprintf('%04d-%02d', (int) $anio, (int) $liq['mes']);

        return isset($liq['quincena']) ? $base.'-Q'.$liq['quincena'] : $base;
    }

    /**
     * v1.56 — período de imputación: mes de la fecha dada, salvo que ese
     * período esté CERRADO/BLOQUEADO → primer día del primer mes siguiente
     * ABIERTO. Sin fila en erp_periodos se considera abierto (los períodos
     * futuros pueden no estar generados todavía).
     */
    private function resolverFechaImputacion(string $fecha): string
    {
        $base = Carbon::parse($fecha)->startOfMonth();
        for ($i = 0; $i <= 12; $i++) {
            $mes = $base->copy()->addMonths($i);
            $estado = DB::table('erp_periodos')
                ->where('anio', $mes->year)->where('mes', $mes->month)
                ->value('estado');
            if ($estado === null || $estado === 'ABIERTO') {
                return $i === 0 ? $fecha : $mes->format('Y-m-01');
            }
        }

        throw new DomainException(
            "SIN_PERIODO_ABIERTO: no hay período fiscal abierto en los 12 meses posteriores a {$fecha}."
        );
    }

    private function log(?int $facturaId, string $daId, string $evento, string $direccion, ?array $payload, ?int $codigo, ?int $usuarioId = null, ?string $respuestaBody = null): void
    {
        DB::table('erp_facturas_compra_sync_log')->insert([
            'factura_compra_id' => $facturaId,
            'distriapp_factura_id' => $daId,
            'evento' => $evento,
            'direccion' => $direccion,
            'payload' => $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null,
            'respuesta_codigo' => $codigo,
            'respuesta_body' => $respuestaBody,
            'procesado_at' => now(),
            'procesado_por' => $usuarioId,
        ]);
    }
}

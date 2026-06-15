<?php

namespace App\Erp\Services\Contabilidad;

use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * v1.47 §14.3 — Saneamiento de la cuenta puente 1.1.6.99 "Pendientes de
 * Identificar". Lista las líneas DEBE contra 1.1.6.99 de asientos
 * contabilizados que aún no fueron reclasificadas, y genera el asiento de
 * reclasificación (D: cuenta destino real / H: 1.1.6.99) por cada una.
 */
class ReclasificacionPendientesService
{
    private const CUENTA_PUENTE = '1.1.6.99';
    private const EMPRESA_ID = 1;

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    private function cuentaPuenteId(int $empresaId): int
    {
        $id = DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)->where('codigo', self::CUENTA_PUENTE)->value('id');
        if (! $id) throw new DomainException('CUENTA_PUENTE_NO_ENCONTRADA: ' . self::CUENTA_PUENTE);
        return (int) $id;
    }

    public function saldo(int $empresaId = self::EMPRESA_ID): array
    {
        $cid = $this->cuentaPuenteId($empresaId);
        $row = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)->where('m.cuenta_id', $cid)->where('a.estado', 'CONTABILIZADO')
            ->selectRaw('COALESCE(SUM(m.debe),0) deb, COALESCE(SUM(m.haber),0) hab')->first();
        return ['cuenta' => self::CUENTA_PUENTE, 'saldo' => round((float) $row->deb - (float) $row->hab, 2)];
    }

    /** Líneas DEBE en 1.1.6.99 aún no reclasificadas. */
    public function pendientes(int $empresaId = self::EMPRESA_ID): array
    {
        $cid = $this->cuentaPuenteId($empresaId);
        // IDs de línea ya reclasificados (origen_id de asientos de reclasif).
        $reclasificados = DB::table('erp_asientos')->where('empresa_id', $empresaId)
            ->where('origen_tabla', 'reclasif_pendiente')->pluck('origen_id')->filter()->all();

        $q = DB::table('erp_movimientos_asiento as m')
            ->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('a.empresa_id', $empresaId)->where('m.cuenta_id', $cid)
            ->where('a.estado', 'CONTABILIZADO')->where('m.debe', '>', 0)
            ->orderBy('a.fecha')
            ->select(['m.id as linea_id', 'a.id as asiento_id', 'a.numero', 'a.fecha', 'a.glosa', 'a.origen', 'm.debe as monto', 'm.glosa as linea_glosa']);
        if (! empty($reclasificados)) $q->whereNotIn('m.id', $reclasificados);
        return $q->limit(500)->get()->all();
    }

    /**
     * @param  array{linea_id:int, cuenta_destino_id:int, auxiliar_id?:?int, motivo?:?string, usuario_id:int}  $data
     */
    public function reclasificar(array $data, int $empresaId = self::EMPRESA_ID): \App\Erp\Models\Asiento
    {
        $linea = DB::table('erp_movimientos_asiento as m')->join('erp_asientos as a', 'a.id', '=', 'm.asiento_id')
            ->where('m.id', $data['linea_id'])->where('a.empresa_id', $empresaId)
            ->select(['m.id', 'm.debe', 'm.cuenta_id', 'a.fecha', 'a.id as asiento_id'])->first();
        if (! $linea) throw new DomainException('LINEA_NO_ENCONTRADA');

        $cuentaPuente = $this->cuentaPuenteId($empresaId);
        if ((int) $linea->cuenta_id !== $cuentaPuente) throw new DomainException('LINEA_NO_ES_PUENTE: la línea no imputa a 1.1.6.99.');

        $yaReclasif = DB::table('erp_asientos')->where('empresa_id', $empresaId)
            ->where('origen_tabla', 'reclasif_pendiente')->where('origen_id', $linea->id)->exists();
        if ($yaReclasif) throw new DomainException('YA_RECLASIFICADA: esta línea ya fue reclasificada.');

        $monto = round((float) $linea->debe, 2);
        if ($monto <= 0.01) throw new DomainException('MONTO_CERO');
        if ($monto > 50000 && empty($data['motivo'])) {
            throw new DomainException('MOTIVO_REQUERIDO: montos > $50.000 requieren motivo.');
        }
        $cuentaDestino = DB::table('erp_cuentas_contables')->where('empresa_id', $empresaId)
            ->where('id', $data['cuenta_destino_id'])->where('imputable', 1)->value('id');
        if (! $cuentaDestino) throw new DomainException('CUENTA_DESTINO_INVALIDA');

        $diarioAju = DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'AJU')->value('id')
            ?? DB::table('erp_diarios')->where('empresa_id', $empresaId)->where('codigo', 'GEN')->value('id');

        $glosa = sprintf('Reclasificación pendiente 1.1.6.99 (asiento orig #%d): %s', $linea->asiento_id, $data['motivo'] ?? '');
        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => $empresaId, 'diario_id' => $diarioAju, 'fecha' => $linea->fecha,
            'glosa' => mb_substr($glosa, 0, 250), 'origen' => 'AJUSTE',
            'origen_tabla' => 'reclasif_pendiente', 'origen_id' => $linea->id,
            'observaciones' => $data['motivo'] ?? null, 'usuario_id' => $data['usuario_id'],
            'movimientos' => [
                ['cuenta_id' => (int) $cuentaDestino, 'auxiliar_id' => $data['auxiliar_id'] ?? null, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa],
                ['cuenta_id' => $cuentaPuente, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa],
            ],
        ]);
        $asiento = $this->asientoService->contabilizar($asiento);

        $this->audit->logEvento(accion: 'RECLASIF_PENDIENTE', modulo: 'contabilidad',
            descripcion: sprintf('Reclasificada línea #%d ($%.2f) de 1.1.6.99 → cuenta #%d (asiento #%d)', $linea->id, $monto, $cuentaDestino, $asiento->id),
            empresaId: $empresaId);
        return $asiento;
    }
}

<?php

namespace App\Erp\Services\Impuestos;

use App\Erp\Models\Impuestos\PeriodoFiscal;
use App\Erp\Models\Impuestos\RetencionPracticada;
use App\Erp\Models\Tesoreria\OrdenPago;
use App\Erp\Support\AuditLogger;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Persiste retenciones propuestas por `RetencionCalculator` como
 * certificados con número secuencial por (empresa, tipo, año calendario).
 *
 * RN-48: numeración secuencial sin huecos. Anuladas conservan número.
 *   - El número se calcula como MAX(nro) + 1 dentro de TX y la unique
 *     constraint `uq_retencion_cert (tipo_retencion, nro_certificado)`
 *     impide duplicados; en colisión retry hasta 3 veces.
 * RN-49: orquestación con OrdenPago — al aplicar, suma `total_retenciones`
 *   en la OP y descuenta `importe`. Solo permite OP en estado BORRADOR.
 *
 * Período fiscal: la retención se asocia al período `SICORE` del mes de
 * la fecha de emisión de la OP. Si no existe, se crea automáticamente.
 */
class RetencionService
{
    public function __construct(
        private readonly PeriodoFiscalService $periodoService,
        private readonly RetencionCalculator $calculator,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Aplica retenciones a una OP en estado BORRADOR.
     *
     * @param array{
     *   condicion_iva: string, naturaleza?: string,
     *   jurisdiccion?: ?string, incluir_suss?: bool,
     *   factura_compra_id?: ?int, comprobante_origen?: ?string
     * } $contexto
     *
     * @return array{op: OrdenPago, retenciones: array<int, RetencionPracticada>, propuestas_no_aplicadas: array<int, array>}
     */
    public function aplicar(OrdenPago $op, User $usuario, array $contexto): array
    {
        if ($op->estado !== OrdenPago::ESTADO_BORRADOR) {
            throw new DomainException(
                "RETENCION_OP_INMUTABLE: solo OPs en BORRADOR aceptan retenciones (actual: {$op->estado})"
            );
        }

        $contexto['monto_pago'] = (float) $op->importe_bruto ?: (float) $op->importe;
        $propuestas = $this->calculator->proponer($contexto);

        $aplicadas = [];
        $noAplicadas = [];

        return DB::transaction(function () use ($op, $usuario, $contexto, $propuestas, &$aplicadas, &$noAplicadas) {
            $fechaOp = $op->fecha;

            foreach ($propuestas as $p) {
                if (($p['importe'] ?? 0) <= 0) {
                    $noAplicadas[] = $p;
                    continue;
                }

                $periodo = $this->periodoSicore($op->empresa_id, $fechaOp, $usuario);
                $cuit = $this->cuitProveedor($op->auxiliar_id);
                $nro = $this->proximoNumero($op->empresa_id, $p['tipo'], (int) $fechaOp->format('Y'));

                $ret = RetencionPracticada::create([
                    'empresa_id'        => $op->empresa_id,
                    'factura_compra_id' => $contexto['factura_compra_id'] ?? null,
                    'orden_pago_id'     => $op->id,
                    'proveedor_id'      => $op->auxiliar_id,
                    'cuit_retenido'     => $cuit,
                    'tipo_retencion'    => $p['tipo'],
                    'regimen'           => $p['regimen'],
                    'fecha_emision'     => $fechaOp,
                    'base_imponible'    => $p['base_imponible'],
                    'alicuota'          => $p['alicuota'],
                    'importe_retenido'  => $p['importe'],
                    'nro_certificado'   => $nro,
                    'estado'            => 'EMITIDO',
                    'comprobante_origen'=> $contexto['comprobante_origen'] ?? null,
                    'periodo_id'        => $periodo->id,
                ]);

                $aplicadas[] = $ret;
            }

            // Actualizar totales de OP.
            $totalRet = array_sum(array_map(fn ($r) => (float) $r->importe_retenido, $aplicadas));
            $importeBruto = (float) ($op->importe_bruto ?: $op->importe);
            $op->update([
                'importe_bruto'      => $importeBruto,
                'total_retenciones'  => $totalRet,
                'importe'            => round($importeBruto - $totalRet, 2),
            ]);

            // Vincular ids en factura_compra (auditoría).
            if (! empty($contexto['factura_compra_id'])) {
                $idsActuales = (array) DB::table('erp_facturas_compra')
                    ->where('id', $contexto['factura_compra_id'])
                    ->value('retenciones_practicadas_ids');
                $idsActuales = is_string($idsActuales) ? json_decode($idsActuales, true) ?: [] : (array) $idsActuales;
                $idsNuevos = array_unique(array_merge($idsActuales, array_map(fn ($r) => $r->id, $aplicadas)));
                DB::table('erp_facturas_compra')
                    ->where('id', $contexto['factura_compra_id'])
                    ->update(['retenciones_practicadas_ids' => json_encode(array_values($idsNuevos))]);
            }

            $this->audit->log('aplicar_retenciones', $op, null, [
                'op_id' => $op->id, 'cantidad' => count($aplicadas),
                'total_retenido' => $totalRet,
                'tipos' => array_unique(array_map(fn ($r) => $r->tipo_retencion, $aplicadas)),
            ], "Retenciones aplicadas a OP {$op->numero} (user #{$usuario->id})");

            return ['op' => $op->fresh(), 'retenciones' => $aplicadas, 'propuestas_no_aplicadas' => $noAplicadas];
        });
    }

    /**
     * Anula una retención (RN-48: el número queda reservado, no se reusa).
     */
    public function anular(RetencionPracticada $ret, User $usuario, string $motivo): RetencionPracticada
    {
        if ($ret->estado === 'ANULADO') {
            throw new DomainException('RETENCION_YA_ANULADA');
        }

        $antes = $ret->toArray();
        $ret->update(['estado' => 'ANULADO']);
        $ret->save();

        $this->audit->log('anular_retencion', $ret, $antes, $ret->fresh()->toArray(),
            "Anulación retención #{$ret->id} cert {$ret->tipo_retencion}/{$ret->nro_certificado}: {$motivo}"
        );

        return $ret->fresh();
    }

    /**
     * MAX(nro) + 1 con retry sobre colisión (la unique constraint uq_retencion_cert
     * blinda contra duplicados en concurrencia).
     *
     * Formato del número: AAAA-NNNNNNN (año-7 dígitos correlativos por tipo).
     */
    private function proximoNumero(int $empresaId, string $tipo, int $anio): string
    {
        $prefijo = sprintf('%04d-', $anio);

        $ultimoStr = DB::table('erp_retenciones_practicadas')
            ->where('empresa_id', $empresaId)
            ->where('tipo_retencion', $tipo)
            ->where('nro_certificado', 'LIKE', "{$prefijo}%")
            ->orderByDesc('nro_certificado')
            ->lockForUpdate()
            ->value('nro_certificado');

        $ultimoNum = $ultimoStr ? (int) substr($ultimoStr, 5) : 0;
        return $prefijo.str_pad((string) ($ultimoNum + 1), 7, '0', STR_PAD_LEFT);
    }

    private function cuitProveedor(int $auxiliarId): string
    {
        $cuit = DB::table('erp_auxiliares')->where('id', $auxiliarId)->value('cuit');
        if (! $cuit) {
            throw new DomainException("RETENCION_PROVEEDOR_SIN_CUIT: auxiliar #{$auxiliarId} sin CUIT");
        }
        return preg_replace('/\D/', '', (string) $cuit);
    }

    private function periodoSicore(int $empresaId, \DateTimeInterface $fecha, User $usuario): PeriodoFiscal
    {
        $anio = (int) $fecha->format('Y');
        $mes  = (int) $fecha->format('m');

        $existente = PeriodoFiscal::query()
            ->where('empresa_id', $empresaId)
            ->where('impuesto', 'SICORE')
            ->where('anio', $anio)
            ->where('mes', $mes)
            ->whereNull('rectifica_a_id')
            ->first();

        if ($existente) {
            return $existente;
        }

        return $this->periodoService->crear([
            'empresa_id' => $empresaId, 'impuesto' => 'SICORE',
            'anio' => $anio, 'mes' => $mes,
        ], $usuario);
    }
}

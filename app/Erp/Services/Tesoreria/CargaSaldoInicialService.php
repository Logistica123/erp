<?php

namespace App\Erp\Services\Tesoreria;

use App\Erp\Models\CuentaContable;
use App\Erp\Models\Tesoreria\Caja;
use App\Erp\Models\Tesoreria\CargaSaldoInicial;
use App\Erp\Models\Tesoreria\CuentaBancaria;
use App\Erp\Services\AsientoService;
use App\Erp\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * v1.52 — Carga de Saldo Inicial (Cajas y Bancos).
 *
 * Genera un asiento D cuenta destino / H contrapartida (default 3.3.01) y
 * deja trazabilidad en erp_cargas_saldo_inicial. Si la cuenta destino mapea
 * unívocamente a una caja física (erp_cajas) o cuenta bancaria, refresca su
 * saldo_actual materializado — clave para que el arqueo (v1.42) vea el
 * teórico correcto. La validación de período abierto la hace AsientoService.
 */
class CargaSaldoInicialService
{
    public const CODIGO_CONTRAPARTIDA_DEFAULT = '3.3.01';

    public const MOTIVOS = [
        'APERTURA_EJERCICIO' => 'Apertura de ejercicio',
        'PUESTA_MARCHA_MODULO' => 'Puesta en marcha del módulo',
        'REGULARIZACION_ESTUDIO' => 'Regularización con estudio contable',
        'OTRO' => 'Otro',
    ];

    public function __construct(
        private readonly AsientoService $asientoService,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param array{
     *   cuenta_contable_destino_id:int,
     *   monto:float|string,
     *   fecha:string,
     *   motivo_tipo:string,
     *   motivo_observacion?:?string,
     *   cuenta_contable_contrapartida_id?:?int,
     *   usuario_id:int,
     * } $data
     */
    public function crear(array $data): CargaSaldoInicial
    {
        $monto = round((float) $data['monto'], 2);
        if ($monto <= 0) {
            throw new DomainException('MONTO_INVALIDO: el monto debe ser mayor a cero.');
        }

        $destino = CuentaContable::findOrFail($data['cuenta_contable_destino_id']);
        if ($destino->tipo !== 'A' || $destino->rubro_ec !== 'Caja y Bancos' || ! $destino->imputable || ! $destino->activo) {
            throw new DomainException("CUENTA_DESTINO_INVALIDA: {$destino->codigo} no es una cuenta imputable de Caja y Bancos.");
        }

        $contrapartida = isset($data['cuenta_contable_contrapartida_id'])
            ? CuentaContable::findOrFail($data['cuenta_contable_contrapartida_id'])
            : CuentaContable::where('empresa_id', $destino->empresa_id)
                ->where('codigo', self::CODIGO_CONTRAPARTIDA_DEFAULT)->firstOrFail();
        if (! $contrapartida->imputable || ! $contrapartida->activo) {
            throw new DomainException("CONTRAPARTIDA_INVALIDA: {$contrapartida->codigo} no es imputable o está inactiva.");
        }
        if ($contrapartida->admite_auxiliar) {
            throw new DomainException("CONTRAPARTIDA_REQUIERE_AUXILIAR: {$contrapartida->codigo} exige auxiliar {$contrapartida->tipo_auxiliar} — elegí una cuenta patrimonial sin auxiliar (ej. ".self::CODIGO_CONTRAPARTIDA_DEFAULT.').');
        }

        $motivoTipo = $data['motivo_tipo'];
        if (! isset(self::MOTIVOS[$motivoTipo])) {
            throw new DomainException("MOTIVO_INVALIDO: {$motivoTipo}");
        }
        $obs = trim((string) ($data['motivo_observacion'] ?? ''));
        if ($motivoTipo === 'OTRO' && mb_strlen($obs) < 10) {
            throw new DomainException('MOTIVO_OBSERVACION_REQUERIDA: con motivo "Otro" la observación es obligatoria (mín 10 caracteres).');
        }

        // Match unívoco cuenta contable → caja física / cuenta bancaria para
        // refrescar el saldo materializado. Si N entidades comparten la cuenta
        // (caso 1.1.1.03 con 4 bancos), no se toca ningún saldo operativo.
        $caja = $this->cajaUnica($destino->id);
        $banco = $caja ? null : $this->bancoUnico($destino->id);

        $glosa = 'Carga inicial saldo — '.self::MOTIVOS[$motivoTipo].($obs !== '' ? " ({$obs})" : '');

        return DB::transaction(function () use ($data, $destino, $contrapartida, $monto, $glosa, $motivoTipo, $obs, $caja, $banco) {
            DB::statement('SET @erp_current_user_id = ?', [$data['usuario_id']]);

            $asiento = $this->crearAsiento(
                empresaId: $destino->empresa_id,
                fecha: $data['fecha'],
                glosa: $glosa,
                usuarioId: $data['usuario_id'],
                movimientos: [
                    ['cuenta_id' => $destino->id, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa],
                    ['cuenta_id' => $contrapartida->id, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa],
                ],
            );

            $carga = CargaSaldoInicial::create([
                'empresa_id' => $destino->empresa_id,
                'cuenta_contable_destino_id' => $destino->id,
                'cuenta_contable_contrapartida_id' => $contrapartida->id,
                'caja_id' => $caja?->id,
                'cuenta_bancaria_id' => $banco?->id,
                'monto' => $monto,
                'fecha' => $data['fecha'],
                'motivo_tipo' => $motivoTipo,
                'motivo_observacion' => $obs !== '' ? $obs : null,
                'asiento_id' => $asiento->id,
                'estado' => 'ACTIVO',
                'created_by' => $data['usuario_id'],
            ]);

            $caja?->increment('saldo_actual', $monto);
            $banco?->increment('saldo_actual', $monto);

            $this->audit->logEvento(
                accion: 'CARGA_SALDO_INICIAL_CREADA',
                modulo: 'tesoreria',
                descripcion: sprintf('Carga inicial #%d · %s %s · $%.2f al %s · contrapartida %s · asiento #%d',
                    $carga->id, $destino->codigo, $destino->nombre, $monto, $data['fecha'], $contrapartida->codigo, $asiento->numero),
                empresaId: $destino->empresa_id,
            );

            return $carga->fresh(['cuentaDestino', 'cuentaContrapartida', 'asiento', 'caja', 'cuentaBancaria']);
        });
    }

    public function revertir(CargaSaldoInicial $carga, string $motivo, int $usuarioId): CargaSaldoInicial
    {
        if ($carga->estado !== 'ACTIVO') {
            throw new DomainException("CARGA_YA_REVERTIDA: la carga #{$carga->id} está {$carga->estado}.");
        }
        if (mb_strlen(trim($motivo)) < 10) {
            throw new DomainException('MOTIVO_REVERSA_CORTO: mínimo 10 caracteres.');
        }

        $monto = (float) $carga->monto;
        $glosa = sprintf('Reversa carga inicial #%d (asiento #%d): %s', $carga->id, $carga->asiento->numero, trim($motivo));

        return DB::transaction(function () use ($carga, $motivo, $usuarioId, $monto, $glosa) {
            DB::statement('SET @erp_current_user_id = ?', [$usuarioId]);

            // Reversa D/H espejo con fecha de hoy (no la de la carga, para no
            // pisar períodos ya cerrados).
            $reversa = $this->crearAsiento(
                empresaId: $carga->empresa_id,
                fecha: now()->toDateString(),
                glosa: $glosa,
                usuarioId: $usuarioId,
                movimientos: [
                    ['cuenta_id' => $carga->cuenta_contable_contrapartida_id, 'debe' => $monto, 'haber' => 0, 'glosa' => $glosa],
                    ['cuenta_id' => $carga->cuenta_contable_destino_id, 'debe' => 0, 'haber' => $monto, 'glosa' => $glosa],
                ],
            );

            $carga->update([
                'estado' => 'REVERTIDO',
                'asiento_reversa_id' => $reversa->id,
                'motivo_reversa' => trim($motivo),
                'revertido_at' => now(),
                'revertido_by' => $usuarioId,
            ]);

            $carga->caja?->decrement('saldo_actual', $monto);
            $carga->cuentaBancaria?->decrement('saldo_actual', $monto);

            $this->audit->logEvento(
                accion: 'CARGA_SALDO_INICIAL_REVERTIDA',
                modulo: 'tesoreria',
                descripcion: sprintf('Carga inicial #%d revertida · $%.2f · reversa asiento #%d · motivo: %s',
                    $carga->id, $monto, $reversa->numero, trim($motivo)),
                empresaId: $carga->empresa_id,
            );

            return $carga->fresh(['cuentaDestino', 'cuentaContrapartida', 'asiento', 'asientoReversa']);
        });
    }

    /**
     * Cuentas elegibles como destino (D-52-1) + entidad operativa asociada.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cuentasDestino(int $empresaId = 1): array
    {
        $cuentas = CuentaContable::where('empresa_id', $empresaId)
            ->where('tipo', 'A')->where('rubro_ec', 'Caja y Bancos')
            ->where('imputable', 1)->where('activo', 1)
            ->orderBy('codigo')
            ->get(['id', 'codigo', 'nombre']);

        return $cuentas->map(function (CuentaContable $c) {
            $caja = $this->cajaUnica($c->id);
            $banco = $caja ? null : $this->bancoUnico($c->id);

            return [
                'id' => $c->id,
                'codigo' => $c->codigo,
                'nombre' => $c->nombre,
                'entidad' => $caja ? "Caja: {$caja->nombre}" : ($banco ? "Banco: {$banco->nombre}" : null),
                'saldo_operativo' => $caja?->saldo_actual ?? $banco?->saldo_actual,
            ];
        })->all();
    }

    /** Caja física activa que usa esta cuenta contable, solo si el match es unívoco. */
    private function cajaUnica(int $cuentaContableId): ?Caja
    {
        $cajas = Caja::where('cuenta_contable_id', $cuentaContableId)->where('activo', 1)->get();

        return $cajas->count() === 1 ? $cajas->first() : null;
    }

    /** Cuenta bancaria activa que usa esta cuenta contable, solo si el match es unívoco. */
    private function bancoUnico(int $cuentaContableId): ?CuentaBancaria
    {
        $bancos = CuentaBancaria::where('cuenta_contable_id', $cuentaContableId)->where('activo', 1)->get();

        return $bancos->count() === 1 ? $bancos->first() : null;
    }

    /**
     * Asiento en diario APE origen APERTURA. AsientoService valida ejercicio y
     * período abierto (EJERCICIO_NO_ENCONTRADO / PERIODO_BLOQUEADO).
     *
     * @param array<int, array<string, mixed>> $movimientos
     */
    private function crearAsiento(int $empresaId, string $fecha, string $glosa, int $usuarioId, array $movimientos): \App\Erp\Models\Asiento
    {
        $ccGeneral = DB::table('erp_centros_costo')
            ->where('empresa_id', $empresaId)->where('codigo', 'GENERAL')->value('id');
        foreach ($movimientos as &$m) {
            $cuenta = CuentaContable::find($m['cuenta_id']);
            if ($cuenta && $cuenta->admite_cc && $ccGeneral) {
                $m['centro_costo_id'] = (int) $ccGeneral;
            }
        }

        $diarioApe = DB::table('erp_diarios')
            ->where('empresa_id', $empresaId)->where('codigo', 'APE')->value('id');

        $asiento = $this->asientoService->crearBorrador([
            'empresa_id' => $empresaId,
            'diario_id' => $diarioApe,
            'fecha' => $fecha,
            'glosa' => $glosa,
            'origen' => 'APERTURA',
            'origen_tabla' => 'erp_cargas_saldo_inicial',
            'usuario_id' => $usuarioId,
            'movimientos' => $movimientos,
        ]);

        return $this->asientoService->contabilizar($asiento);
    }
}

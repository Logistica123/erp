<?php

namespace App\Erp\Services\Tesoreria;

/**
 * v1.27 Sprint B — Inferencia del `tipo_operativo` para un movimiento
 * bancario en base al banco + concepto + código de operación.
 *
 * Por ahora con reglas hardcoded (rápido + cubre los casos comunes). Si se
 * vuelve insuficiente, migrar a una tabla `erp_extracto_regex_tipo` con
 * regex configurable por banco (queda para v1.28+).
 */
class ExtractoTipoService
{
    /**
     * @return string uno de TRANSFERENCIA_RECIBIDA, TRANSFERENCIA_ENVIADA,
     *                PAGO_SERVICIO, COMISION_BANCARIA, IMPUESTO_DEBITO_CREDITO,
     *                DEPOSITO, EXTRACCION, INTERES_GANADO, OTRO.
     */
    public function inferir(?string $bancoCodigo, ?string $codigoOp, string $concepto, float $debito = 0, float $credito = 0): string
    {
        $c = mb_strtoupper(trim($concepto));

        // Patrones genéricos (aplican a cualquier banco).
        $patterns = [
            'COMISION_BANCARIA' => [
                'COMISION', 'COM ', 'COM\.', 'CUSTODIA', 'MANTENIMIENTO',
                'CARGO POR', 'CARGOS', 'COSTO POR', 'COMIS',
            ],
            'IMPUESTO_DEBITO_CREDITO' => [
                'IMP\. DEBITO', 'IMP\. CREDITO', 'IMPUESTO DEBITO', 'IMPUESTO CREDITO',
                'IMP\.LEY 25\.413', 'LEY 25\.413', 'IIBB', 'INGRESOS BRUTOS',
                'PERCEPCION', 'PERCEP', 'RETENCION',
            ],
            'INTERES_GANADO' => [
                'INTERES.*GANADO', 'INT\. GANADO', 'INT GANADO',
                'INTERES.*REMUN', 'RENDIMIENTO',
            ],
            'TRANSFERENCIA_RECIBIDA' => [
                'TRANSF.*RECIB', 'TRANSFERENCIA RECIBIDA', 'TRA RECIBIDA',
                'CREDITO INMEDIATO', 'CRED INMEDIATO', 'DEPOSITO TRANSFERENCIA',
                'CVU', 'CBU\b.*ENT',
            ],
            'TRANSFERENCIA_ENVIADA' => [
                'TRANSF.*ENVIADA', 'TRANSFERENCIA ENVIADA', 'TRA ENVIADA',
                'DEBITO INMEDIATO', 'DEB INMEDIATO', 'TRA INTERBANCARIA',
                'TRANSFERENCIA INMEDIATA',
            ],
            'PAGO_SERVICIO' => [
                'PAGO DE SERVICIO', 'PAGO SERVICIO', 'PAGO LINK', 'PAGOMISCUENTAS',
                'PAGO IMPUESTO', 'PAGO TARJETA', 'DPEC', 'EDENOR', 'EDESUR',
                'METROGAS', 'CABLEVISION', 'PERSONAL', 'CLARO',
                'TELECOM', 'AYSA',
            ],
            'DEPOSITO' => [
                'DEPOSITO EFECTIVO', 'DEPOSITO CHEQUE', 'DEP EFECTIVO',
            ],
            'EXTRACCION' => [
                'EXTRACCION', 'CAJERO AUTOMATICO', 'ATM ',
            ],
        ];

        foreach ($patterns as $tipo => $regs) {
            foreach ($regs as $regex) {
                if (preg_match("/{$regex}/i", $c)) {
                    return $tipo;
                }
            }
        }

        // Heurística por signo en bancos sin patrón claro.
        // Si es solo un crédito chico (<$10k) probablemente sea interés ganado.
        // No-op por seguridad — queda en OTRO.

        return 'OTRO';
    }
}

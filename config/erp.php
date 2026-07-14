<?php

/**
 * Item 8 (auditoría 2026-07-12) — autorización fina obligatoria.
 */
return [

    // Modo del enforcement de permisos finos (middleware erp.permiso):
    //   'enforce' → sin permiso = 403 (default: seguro para entornos nuevos).
    //   'log'     → evalúa y loguea 'permiso.denegado-simulado' SIN bloquear.
    //               Modo observación para rollout en prod (plan 2A).
    'permisos_modo' => env('ERP_PERMISOS_MODO', 'enforce'),

    // Bypass del gate fino para el rol super_admin. En prod SIEMPRE true
    // (imposible auto-bloquearse por un hueco de matriz). Se apaga SOLO en
    // dev para el test de robustez de la matriz (pre-deploy).
    'superadmin_bypass' => (bool) env('ERP_SUPERADMIN_BYPASS', true),

    // ── Módulo Sueldos (workstream 2026-07-13, decisiones P1-P9 Matías) ──
    'sueldos' => [
        // G-01: el efectivo se entrega redondeado hacia ARRIBA a este
        // múltiplo (billetes). 0 = sin redondeo.
        'redondeo_efectivo' => (int) env('ERP_SUELDOS_REDONDEO_EFECTIVO', 500),
        // Cuenta contable (código) donde se imputa la diferencia de
        // redondeo — default 5.4.09 Faltante de Caja (gasto), como los
        // ajustes de arqueo. La define Sebastián si quiere otra.
        'cuenta_dif_redondeo' => env('ERP_SUELDOS_CUENTA_DIF_REDONDEO', '5.4.09'),
        // G-14 (P3): valor hora = básico / divisor. Excel: (básico/30)/8 = 240.
        'divisor_valor_hora' => (float) env('ERP_SUELDOS_DIVISOR_HORA', 240),
        // G-02 (Bloque 2, Q-07): meses del semestre para el MAX del SAC.
        'sac_meses_calculo' => (int) env('ERP_SUELDOS_SAC_MESES', 6),
    ],
];

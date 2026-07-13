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
];

<?php

use App\Erp\Http\Controllers\ArcaController;
use App\Erp\Http\Controllers\AsientosController;
use App\Erp\Http\Controllers\AuditoriaController;
use App\Erp\Http\Controllers\AuthController;
use App\Erp\Http\Controllers\AuxiliaresController;
use App\Erp\Http\Controllers\BalanceController;
use App\Erp\Http\Controllers\CajaController;
use App\Erp\Http\Controllers\ConfigController;
use App\Erp\Http\Controllers\CobrosController;
use App\Erp\Http\Controllers\CotizacionesController;
use App\Erp\Http\Controllers\EcheqController;
use App\Erp\Http\Controllers\ExtractosController;
use App\Erp\Http\Controllers\LibroDiarioController;
use App\Erp\Http\Controllers\RevaluacionController;
use App\Erp\Http\Controllers\RolesPermisosController;
use App\Erp\Http\Controllers\TransferenciasInternasController;
use App\Erp\Http\Controllers\UsuariosController;
use App\Erp\Http\Controllers\CatalogosController;
use App\Erp\Http\Controllers\CuentasContablesController;
use App\Erp\Http\Controllers\EjerciciosController;
use App\Erp\Http\Controllers\EstadosContablesController;
use App\Erp\Http\Controllers\HealthController;
use App\Erp\Http\Controllers\LibroMayorController;
use App\Erp\Http\Controllers\MovimientosBancariosController;
use App\Erp\Http\Controllers\OrdenesPagoController;
use App\Erp\Http\Controllers\PeriodosController;
use App\Erp\Http\Controllers\ReportesTesoreriaController;
use App\Erp\Http\Controllers\ReportesVentasComprasController;
use App\Erp\Http\Controllers\SesionesController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/erp')->group(function () {
    // Endpoints públicos
    Route::get('/health', HealthController::class)->name('erp.health');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('erp.auth.login');

    // Pre-MFA (token con ability "mfa:challenge")
    Route::middleware('auth:sanctum')
        ->post('/auth/mfa/verify', [AuthController::class, 'verifyMfa'])
        ->name('erp.auth.mfa.verify');

    // Sesiones ERP (SPEC_01 §5.1) — independientes del token Sanctum.
    // El token Sanctum autentica al user; el UUID de sesión lleva el estado
    // MFA (mfa_verificado_at) y el timeout de 8h propio del ERP.
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/sesiones', [SesionesController::class, 'store'])->name('erp.sesiones.store');
        Route::post('/sesiones/mfa', [SesionesController::class, 'mfa'])->name('erp.sesiones.mfa');
        Route::delete('/sesiones', [SesionesController::class, 'destroy'])->name('erp.sesiones.destroy');
    });

    // Endpoints autenticados con Sanctum + ErpAuth
    Route::middleware(['auth:sanctum', 'erp.auth'])->group(function () {
        Route::get('/auth/me', [AuthController::class, 'me'])->name('erp.auth.me');
        Route::post('/auth/logout', [AuthController::class, 'logout'])->name('erp.auth.logout');
        Route::post('/auth/mfa/setup', [AuthController::class, 'setupMfa'])->name('erp.auth.mfa.setup');
        Route::post('/auth/mfa/enable', [AuthController::class, 'enableMfa'])->name('erp.auth.mfa.enable');

        // Catálogos de lectura
        Route::get('/empresas/actual', [CatalogosController::class, 'empresaActual'])->name('erp.empresas.actual');
        Route::get('/diarios', [CatalogosController::class, 'diarios'])->name('erp.diarios');
        Route::get('/ejercicios', [CatalogosController::class, 'ejercicios'])->name('erp.ejercicios');
        Route::get('/periodos', [CatalogosController::class, 'periodos'])->name('erp.periodos');
        Route::get('/periodos/abierto', [CatalogosController::class, 'periodoAbierto'])->name('erp.periodos.abierto');
        Route::get('/monedas', [CatalogosController::class, 'monedas'])->name('erp.monedas');
        Route::get('/centros-costo', [CatalogosController::class, 'centrosCosto'])->name('erp.centros-costo');
        Route::get('/auxiliares', [CatalogosController::class, 'auxiliares'])->name('erp.auxiliares');
        Route::get('/auxiliares/buscar', [AuxiliaresController::class, 'buscar'])->name('erp.auxiliares.buscar');
        Route::post('/auxiliares', [AuxiliaresController::class, 'store'])->name('erp.auxiliares.store');
        Route::get('/auxiliares/{id}/saldo', [AuxiliaresController::class, 'saldo'])
            ->whereNumber('id')->name('erp.auxiliares.saldo');
        Route::get('/bancos', [CatalogosController::class, 'bancos'])->name('erp.bancos');
        Route::get('/cuentas-bancarias', [CatalogosController::class, 'cuentasBancarias'])->name('erp.cuentas-bancarias');
        Route::get('/cajas', [CatalogosController::class, 'cajas'])->name('erp.cajas');
        Route::get('/medios-pago', [CatalogosController::class, 'mediosPago'])->name('erp.medios-pago');

        // Contabilidad — catálogo de cuentas
        Route::get('/cuentas', [CuentasContablesController::class, 'index'])->name('erp.cuentas.index');
        Route::get('/cuentas/{id}', [CuentasContablesController::class, 'show'])
            ->whereNumber('id')
            ->name('erp.cuentas.show');

        // Contabilidad — asientos
        Route::get('/asientos', [AsientosController::class, 'index'])->name('erp.asientos.index');
        Route::post('/asientos', [AsientosController::class, 'store'])->name('erp.asientos.store');
        Route::get('/asientos/{id}', [AsientosController::class, 'show'])
            ->whereNumber('id')->name('erp.asientos.show');
        Route::post('/asientos/{id}/contabilizar', [AsientosController::class, 'contabilizar'])
            ->whereNumber('id')->name('erp.asientos.contabilizar');
        Route::post('/asientos/{id}/anular', [AsientosController::class, 'anular'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.asientos.anular');
        Route::delete('/asientos/{id}', [AsientosController::class, 'destroy'])
            ->whereNumber('id')->name('erp.asientos.destroy');

        // Libro diario (json|csv|html)
        Route::get('/libro-diario', [LibroDiarioController::class, 'index'])->name('erp.libro-diario');

        // Libro mayor
        Route::get('/libro-mayor', [LibroMayorController::class, 'index'])->name('erp.libro-mayor');

        // Balance Sumas y Saldos
        Route::get('/balance-sumas-saldos', [BalanceController::class, 'sumasSaldos'])
            ->name('erp.balance-ss');

        // Cierre / apertura de período — sensibles: exigen MFA fresco (<15 min)
        Route::post('/periodos/{id}/cerrar', [PeriodosController::class, 'cerrar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.periodos.cerrar');
        Route::post('/periodos/{id}/bloquear', [PeriodosController::class, 'bloquear'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.periodos.bloquear');
        Route::post('/periodos/{id}/desbloquear', [PeriodosController::class, 'desbloquear'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.periodos.desbloquear');
        Route::post('/periodos/{id}/reabrir', [PeriodosController::class, 'reabrir'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.periodos.reabrir');

        // Cierre / apertura de ejercicio (asiento refundición automático al cerrar)
        Route::post('/ejercicios/{id}/cerrar', [EjerciciosController::class, 'cerrar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.ejercicios.cerrar');
        Route::post('/ejercicios/{id}/reabrir', [EjerciciosController::class, 'reabrir'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.ejercicios.reabrir');

        // Estados contables (FACPCE)
        Route::get('/estados-contables/situacion-patrimonial',
            [EstadosContablesController::class, 'situacionPatrimonial'])->name('erp.ec.sp');
        Route::get('/estados-contables/resultados',
            [EstadosContablesController::class, 'resultados'])->name('erp.ec.er');

        // Tesorería — extractos bancarios (SPEC 02 §6.2)
        Route::post('/extractos/importar', [ExtractosController::class, 'importar'])
            ->name('erp.extractos.importar');
        Route::get('/extractos', [ExtractosController::class, 'index'])->name('erp.extractos.index');
        Route::get('/extractos/{id}/movimientos', [ExtractosController::class, 'movimientos'])
            ->whereNumber('id')->name('erp.extractos.movimientos');
        Route::delete('/extractos/{id}', [ExtractosController::class, 'destroy'])
            ->whereNumber('id')->name('erp.extractos.destroy');

        // Tesorería — movimientos bancarios + conciliación (SPEC 02 §6.3, RN-14/21/26)
        Route::get('/movimientos-bancarios', [MovimientosBancariosController::class, 'index'])->name('erp.mov-banc.index');
        Route::post('/movimientos-bancarios', [MovimientosBancariosController::class, 'store'])->name('erp.mov-banc.store');
        Route::post('/movimientos-bancarios/autoconciliar', [MovimientosBancariosController::class, 'autoconciliar'])
            ->name('erp.mov-banc.autoconciliar');
        Route::patch('/movimientos-bancarios/{id}/etiquetar', [MovimientosBancariosController::class, 'etiquetar'])
            ->whereNumber('id')->name('erp.mov-banc.etiquetar');
        Route::post('/movimientos-bancarios/{id}/conciliar', [MovimientosBancariosController::class, 'conciliar'])
            ->whereNumber('id')->name('erp.mov-banc.conciliar');
        Route::post('/movimientos-bancarios/{id}/desconciliar', [MovimientosBancariosController::class, 'desconciliar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.mov-banc.desconciliar');
        Route::post('/movimientos-bancarios/{id}/ignorar', [MovimientosBancariosController::class, 'ignorar'])
            ->whereNumber('id')->name('erp.mov-banc.ignorar');

        // Tesorería — reportes (SPEC 02 §6.9)
        Route::get('/reportes/saldos', [ReportesTesoreriaController::class, 'saldos'])
            ->name('erp.reportes.saldos');
        Route::get('/reportes/flujo-caja', [ReportesTesoreriaController::class, 'flujoCaja'])
            ->name('erp.reportes.flujo-caja');
        Route::get('/reportes/pendientes-conciliar', [ReportesTesoreriaController::class, 'pendientesConciliar'])
            ->name('erp.reportes.pendientes');
        Route::get('/reportes/echeq-en-cartera', [ReportesTesoreriaController::class, 'echeqEnCartera'])
            ->name('erp.reportes.echeq-cartera');

        // Tesorería — caja física (SPEC 02 §6.8, RN-16/22/23)
        Route::get('/caja/movimientos', [CajaController::class, 'movimientos'])->name('erp.caja.movimientos');
        Route::get('/caja/arqueos', [CajaController::class, 'arqueos'])->name('erp.caja.arqueos');
        Route::post('/caja/arqueo', [CajaController::class, 'registrarArqueo'])->name('erp.caja.arqueo');
        Route::get('/caja/fechas-sin-arqueo', [CajaController::class, 'fechasSinArqueo'])
            ->name('erp.caja.fechas-sin-arqueo');

        // Tesorería — transferencias internas (SPEC 02 §6.7, RN-20)
        Route::get('/transferencias-internas', [TransferenciasInternasController::class, 'index'])
            ->name('erp.ti.index');
        Route::post('/transferencias-internas', [TransferenciasInternasController::class, 'store'])
            ->name('erp.ti.store');
        Route::post('/transferencias-internas/{id}/contabilizar', [TransferenciasInternasController::class, 'contabilizar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.ti.contabilizar');
        Route::post('/transferencias-internas/{id}/anular', [TransferenciasInternasController::class, 'anular'])
            ->whereNumber('id')->name('erp.ti.anular');

        // Tesorería — cobros (SPEC 02 §6.6)
        Route::get('/cobros', [CobrosController::class, 'index'])->name('erp.cobros.index');
        Route::post('/cobros', [CobrosController::class, 'store'])->name('erp.cobros.store');
        Route::get('/cobros/{id}', [CobrosController::class, 'show'])
            ->whereNumber('id')->name('erp.cobros.show');
        Route::post('/cobros/{id}/anular', [CobrosController::class, 'anular'])
            ->whereNumber('id')->name('erp.cobros.anular');

        // Tesorería — eCheq (SPEC 02 §6.4)
        Route::get('/echeq', [EcheqController::class, 'index'])->name('erp.echeq.index');
        Route::get('/echeq/{id}', [EcheqController::class, 'show'])
            ->whereNumber('id')->name('erp.echeq.show');
        Route::post('/echeq/{id}/depositar', [EcheqController::class, 'depositar'])
            ->whereNumber('id')->name('erp.echeq.depositar');
        Route::post('/echeq/{id}/acreditar', [EcheqController::class, 'acreditar'])
            ->whereNumber('id')->name('erp.echeq.acreditar');
        Route::post('/echeq/{id}/rechazar', [EcheqController::class, 'rechazar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.echeq.rechazar');
        Route::post('/echeq/{id}/anular', [EcheqController::class, 'anular'])
            ->whereNumber('id')->name('erp.echeq.anular');

        // Tesorería — órdenes de pago (SPEC 02 §6.5)
        Route::get('/ordenes-pago', [OrdenesPagoController::class, 'index'])->name('erp.op.index');
        Route::post('/ordenes-pago', [OrdenesPagoController::class, 'store'])->name('erp.op.store');
        Route::get('/ordenes-pago/{id}', [OrdenesPagoController::class, 'show'])
            ->whereNumber('id')->name('erp.op.show');
        Route::patch('/ordenes-pago/{id}', [OrdenesPagoController::class, 'update'])
            ->whereNumber('id')->name('erp.op.update');
        Route::post('/ordenes-pago/{id}/cargar-banco', [OrdenesPagoController::class, 'cargarBanco'])
            ->whereNumber('id')->name('erp.op.cargar-banco');
        Route::post('/ordenes-pago/{id}/liberar', [OrdenesPagoController::class, 'liberar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.op.liberar');
        Route::post('/ordenes-pago/{id}/rechazar', [OrdenesPagoController::class, 'rechazar'])
            ->whereNumber('id')->name('erp.op.rechazar');
        Route::post('/ordenes-pago/{id}/pagar', [OrdenesPagoController::class, 'pagar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.op.pagar');
        Route::post('/ordenes-pago/{id}/anular', [OrdenesPagoController::class, 'anular'])
            ->whereNumber('id')->name('erp.op.anular');

        // Administración — usuarios, roles, permisos, config, cotizaciones
        Route::get('/usuarios', [UsuariosController::class, 'index'])->name('erp.usuarios.index');
        Route::post('/usuarios', [UsuariosController::class, 'store'])
            ->middleware('erp.mfa.fresh')->name('erp.usuarios.store');
        Route::patch('/usuarios/{id}/roles', [UsuariosController::class, 'updateRoles'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.usuarios.roles');

        Route::get('/roles', [RolesPermisosController::class, 'rolesIndex'])->name('erp.roles.index');
        Route::get('/permisos', [RolesPermisosController::class, 'permisosIndex'])->name('erp.permisos.index');
        Route::get('/mi-permisos', [RolesPermisosController::class, 'misPermisos'])->name('erp.mi-permisos');

        Route::get('/config', [ConfigController::class, 'index'])->name('erp.config.index');
        Route::patch('/config/{clave}', [ConfigController::class, 'update'])
            ->middleware('erp.mfa.fresh')->name('erp.config.update');

        Route::get('/cotizaciones', [CotizacionesController::class, 'index'])->name('erp.cotizaciones.index');
        Route::post('/cotizaciones', [CotizacionesController::class, 'store'])->name('erp.cotizaciones.store');
        Route::post('/cotizaciones/sync-bcra', [CotizacionesController::class, 'syncBcra'])
            ->name('erp.cotizaciones.sync-bcra');

        // Revaluación USD mensual (RN-11)
        Route::post('/revaluacion/ejecutar', [RevaluacionController::class, 'ejecutar'])
            ->middleware('erp.mfa.fresh')
            ->name('erp.revaluacion.ejecutar');

        // Auditoría (log inmutable con hash-chain)
        Route::get('/auditoria', [AuditoriaController::class, 'index'])->name('erp.auditoria.index');
        Route::get('/auditoria/verificar-cadena', [AuditoriaController::class, 'verificarCadena'])
            ->name('erp.auditoria.verificar-cadena');
        Route::get('/auditoria/verificar-integridad-asientos', [AuditoriaController::class, 'verificarIntegridadAsientos'])
            ->name('erp.auditoria.verificar-integridad-asientos');

        // Dashboard
        Route::get('/dashboard/stats', [\App\Erp\Http\Controllers\DashboardController::class, 'stats'])
            ->name('erp.dashboard.stats');

        // Libro IVA (SPEC 03 §6.1 — consulta + import ARCA RN-29/30)
        Route::get('/libro-iva/ventas', [\App\Erp\Http\Controllers\LibroIvaController::class, 'ventas'])
            ->name('erp.libro-iva.ventas');
        Route::post('/libro-iva/importar', [\App\Erp\Http\Controllers\LibroIvaController::class, 'importar'])
            ->name('erp.libro-iva.importar');
        Route::get('/libro-iva/importaciones', [\App\Erp\Http\Controllers\LibroIvaController::class, 'importaciones'])
            ->name('erp.libro-iva.importaciones');
        Route::get('/libro-iva/importaciones/{id}/detalle', [\App\Erp\Http\Controllers\LibroIvaController::class, 'importacionDetalle'])
            ->whereNumber('id')->name('erp.libro-iva.importacion-detalle');
        Route::post('/libro-iva/importaciones/{id}/conciliar-masivo', [\App\Erp\Http\Controllers\LibroIvaController::class, 'conciliarMasivo'])
            ->whereNumber('id')->name('erp.libro-iva.conciliar-masivo');

        // Facturación (venta)
        Route::get('/facturas-venta/catalogos', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'catalogosEmision'])
            ->name('erp.fv.catalogos');
        Route::get('/facturas-venta', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'index'])
            ->name('erp.fv.index');
        Route::post('/facturas-venta/emitir', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'emitir'])
            ->name('erp.fv.emitir');
        Route::post('/facturas-venta/{id}/cobrar', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'cobrar'])
            ->whereNumber('id')->name('erp.fv.cobrar');
        Route::post('/facturas-venta/{id}/controlar', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'controlar'])
            ->whereNumber('id')->name('erp.fv.controlar');
        Route::post('/facturas-venta/{id}/rechazar', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'rechazar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.fv.rechazar');
        Route::post('/facturas-venta/{id}/anular', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'anular'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.fv.anular');
        Route::post('/facturas-venta/{id}/fce-aceptada', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'fceAceptada'])
            ->whereNumber('id')->name('erp.fv.fce-aceptada');
        Route::post('/facturas-venta/{id}/fce-rechazada', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'fceRechazada'])
            ->whereNumber('id')->name('erp.fv.fce-rechazada');

        // Reportes Ventas/Compras (SPEC 03 §6.5)
        Route::get('/reportes/libro-iva-compras', [ReportesVentasComprasController::class, 'libroIvaCompras'])
            ->name('erp.reportes.libro-iva-compras');
        Route::get('/reportes/pendientes-control', [ReportesVentasComprasController::class, 'pendientesControl'])
            ->name('erp.reportes.pendientes-control');
        Route::get('/reportes/antiguedad-saldos', [ReportesVentasComprasController::class, 'antiguedadSaldos'])
            ->name('erp.reportes.aging-clientes');
        Route::get('/reportes/antiguedad-proveedores', [ReportesVentasComprasController::class, 'antiguedadProveedores'])
            ->name('erp.reportes.aging-proveedores');
        Route::get('/reportes/fce-estados', [ReportesVentasComprasController::class, 'fceEstados'])
            ->name('erp.reportes.fce-estados');

        // ARCA — emisión (fachada sobre factura venta) — SPEC 03 §6.6
        Route::get('/facturas-venta/{id}/emision-status', [ArcaController::class, 'emisionStatus'])
            ->whereNumber('id')->name('erp.fv.emision-status');
        Route::get('/facturas-venta/{id}/cae', [ArcaController::class, 'cae'])
            ->whereNumber('id')->name('erp.fv.cae');
        Route::post('/facturas-venta/{id}/reintentar-emision', [ArcaController::class, 'reintentarEmision'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.fv.reintentar');

        // ARCA — padrones, constatación, Mis Comprobantes, PV AFIP (SPEC 03 §6.6)
        Route::post('/padrones/consultar', [ArcaController::class, 'padronConsultar'])->name('erp.arca.padron.consultar');
        Route::post('/padrones/refrescar/{cuit}', [ArcaController::class, 'padronRefrescar'])->name('erp.arca.padron.refrescar');
        Route::post('/comprobantes/constatar', [ArcaController::class, 'comprobantesConstatar'])->name('erp.arca.comp.constatar');
        Route::post('/facturas-compra/{id}/constatar', [ArcaController::class, 'constatarFactura'])
            ->whereNumber('id')->name('erp.arca.fc.constatar');
        Route::post('/mis-comprobantes/ejecutar', [ArcaController::class, 'misComprobantesEjecutar'])
            ->name('erp.arca.mc.ejecutar');
        Route::get('/mis-comprobantes/runs', [ArcaController::class, 'misComprobantesRuns'])
            ->name('erp.arca.mc.runs');
        Route::get('/puntos-venta/afip', [ArcaController::class, 'puntosVentaAfip'])
            ->name('erp.arca.pv.sync');
        Route::get('/facturas-venta/{id}', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'show'])
            ->whereNumber('id')->name('erp.fv.show');

        // Facturación (compra) — SPEC 03 §6.3
        Route::get('/facturas-compra', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'index'])
            ->name('erp.fc.index');
        Route::post('/facturas-compra', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'store'])
            ->name('erp.fc.store');
        Route::get('/facturas-compra/{id}', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'show'])
            ->whereNumber('id')->name('erp.fc.show');
        Route::patch('/facturas-compra/{id}', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'update'])
            ->whereNumber('id')->name('erp.fc.update');
        Route::post('/facturas-compra/{id}/controlar', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'controlar'])
            ->whereNumber('id')->name('erp.fc.controlar');
        Route::post('/facturas-compra/{id}/observar', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'observar'])
            ->whereNumber('id')->name('erp.fc.observar');
        Route::post('/facturas-compra/{id}/rechazar', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'rechazar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.fc.rechazar');
        Route::post('/facturas-compra/{id}/nc', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'registrarNc'])
            ->whereNumber('id')->name('erp.fc.nc');

        // Integración DistriApp (SPEC 07)
        Route::prefix('integracion/distriapp')->group(function () {
            Route::get('/clientes', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'clientes'])
                ->name('erp.integ.da.clientes');
            Route::get('/distribuidores', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'distribuidores'])
                ->name('erp.integ.da.distribuidores');
            Route::post('/sync-clientes', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'syncClientes'])
                ->name('erp.integ.da.sync-clientes');
            Route::post('/sync-distribuidores', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'syncDistribuidores'])
                ->name('erp.integ.da.sync-distribuidores');
            Route::get('/facturas', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'facturas'])
                ->name('erp.integ.da.facturas');
            Route::post('/sync-facturas', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'syncFacturas'])
                ->name('erp.integ.da.sync-facturas');
            Route::get('/liquidaciones-distrib', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'liquidacionesDistrib'])
                ->name('erp.integ.da.liqs-distrib');
            Route::post('/contabilizar-facturas', [\App\Erp\Http\Controllers\Integracion\DistriAppController::class, 'contabilizarFacturas'])
                ->name('erp.integ.da.contab-facturas');
        });

        // ====================================================================
        // SPEC 05 H1 — Impuestos: períodos fiscales + Libro IVA Digital F.8001
        // ====================================================================
        Route::prefix('impuestos')->group(function () {
            // Períodos fiscales (§6.1)
            Route::get('/periodos',  [\App\Erp\Http\Controllers\Impuestos\PeriodosFiscalesController::class, 'index'])
                ->name('erp.imp.periodos.index');
            Route::post('/periodos', [\App\Erp\Http\Controllers\Impuestos\PeriodosFiscalesController::class, 'store'])
                ->name('erp.imp.periodos.store');
            Route::get('/periodos/{id}', [\App\Erp\Http\Controllers\Impuestos\PeriodosFiscalesController::class, 'show'])
                ->whereNumber('id')->name('erp.imp.periodos.show');
            Route::patch('/periodos/{id}', [\App\Erp\Http\Controllers\Impuestos\PeriodosFiscalesController::class, 'update'])
                ->whereNumber('id')->name('erp.imp.periodos.update');
            Route::post('/periodos/{id}/rectificativa', [\App\Erp\Http\Controllers\Impuestos\PeriodosFiscalesController::class, 'rectificar'])
                ->whereNumber('id')->name('erp.imp.periodos.rectificar');

            // Libro IVA Digital F.8001 (§6.2)
            Route::get('/libro-iva/{periodo_id}', [\App\Erp\Http\Controllers\Impuestos\LibroIvaDigitalController::class, 'show'])
                ->whereNumber('periodo_id')->name('erp.imp.libro-iva.show');
            Route::post('/libro-iva/{periodo_id}/armar', [\App\Erp\Http\Controllers\Impuestos\LibroIvaDigitalController::class, 'armar'])
                ->whereNumber('periodo_id')->name('erp.imp.libro-iva.armar');
            Route::post('/libro-iva/{periodo_id}/validar', [\App\Erp\Http\Controllers\Impuestos\LibroIvaDigitalController::class, 'validar'])
                ->whereNumber('periodo_id')->name('erp.imp.libro-iva.validar');
            Route::post('/libro-iva/{periodo_id}/generar-f8001', [\App\Erp\Http\Controllers\Impuestos\LibroIvaDigitalController::class, 'generar'])
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.libro-iva.generar');
            Route::get('/libro-iva/{periodo_id}/descargar', [\App\Erp\Http\Controllers\Impuestos\LibroIvaDigitalController::class, 'descargar'])
                ->whereNumber('periodo_id')->name('erp.imp.libro-iva.descargar');

            // DDJJ IVA F.2002 (§6.3, H2)
            Route::get('/iva/{periodo_id}', [\App\Erp\Http\Controllers\Impuestos\IvaDdjjController::class, 'show'])
                ->whereNumber('periodo_id')->name('erp.imp.iva.show');
            Route::post('/iva/{periodo_id}/calcular', [\App\Erp\Http\Controllers\Impuestos\IvaDdjjController::class, 'calcular'])
                ->whereNumber('periodo_id')->name('erp.imp.iva.calcular');
            Route::post('/iva/{periodo_id}/generar-f2002', [\App\Erp\Http\Controllers\Impuestos\IvaDdjjController::class, 'generar'])
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.iva.generar');
            Route::get('/iva/{periodo_id}/descargar', [\App\Erp\Http\Controllers\Impuestos\IvaDdjjController::class, 'descargar'])
                ->whereNumber('periodo_id')->name('erp.imp.iva.descargar');
            Route::post('/iva/{periodo_id}/generar-op', [\App\Erp\Http\Controllers\Impuestos\IvaDdjjController::class, 'generarOp'])
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.iva.generar-op');

            // SICORE / SIRE retenciones (§6.4, H3)
            Route::get('/sicore/{periodo_id}', [\App\Erp\Http\Controllers\Impuestos\SicoreController::class, 'show'])
                ->whereNumber('periodo_id')->name('erp.imp.sicore.show');
            Route::post('/sicore/{periodo_id}/generar', [\App\Erp\Http\Controllers\Impuestos\SicoreController::class, 'generar'])
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.sicore.generar');
            Route::get('/sicore/{periodo_id}/descargar', [\App\Erp\Http\Controllers\Impuestos\SicoreController::class, 'descargar'])
                ->whereNumber('periodo_id')->name('erp.imp.sicore.descargar');
            Route::post('/sicore/aplicar/{op_id}', [\App\Erp\Http\Controllers\Impuestos\SicoreController::class, 'aplicarOp'])
                ->whereNumber('op_id')->middleware('erp.mfa.fresh')->name('erp.imp.sicore.aplicar');
            Route::get('/sicore/certificados/{retencion_id}', [\App\Erp\Http\Controllers\Impuestos\SicoreController::class, 'certificadoHtml'])
                ->whereNumber('retencion_id')->name('erp.imp.sicore.cert.html');
            Route::post('/sicore/certificados/{retencion_id}/anular', [\App\Erp\Http\Controllers\Impuestos\SicoreController::class, 'anularCertificado'])
                ->whereNumber('retencion_id')->middleware('erp.mfa.fresh')->name('erp.imp.sicore.cert.anular');

            // IIBB Convenio Multilateral (§6.5, H4)
            Route::get('/iibb/cm/{periodo_id}', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'showCm'])
                ->whereNumber('periodo_id')->name('erp.imp.iibb.cm.show');
            Route::post('/iibb/cm/{periodo_id}/calcular', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'calcularCm'])
                ->whereNumber('periodo_id')->name('erp.imp.iibb.cm.calcular');
            Route::post('/iibb/cm/{periodo_id}/generar-sifere', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'generarCm'])
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.iibb.cm.generar');

            // CM05 coeficientes anuales
            Route::post('/iibb/cm05/{periodo_id}/calcular-coeficientes', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'calcularCoeficientesCm05'])
                ->whereNumber('periodo_id')->name('erp.imp.iibb.cm05.calcular');
            Route::post('/iibb/cm05/coeficientes/{id}/ajustar', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'ajustarCoeficiente'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.imp.iibb.cm05.ajustar');
            Route::post('/iibb/cm05/{anio}/aprobar', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'aprobarCoeficientesCm05'])
                ->whereNumber('anio')->middleware('erp.mfa.fresh')->name('erp.imp.iibb.cm05.aprobar');
            Route::get('/iibb/cm05/{anio}/coeficientes', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'listarCoeficientes'])
                ->whereNumber('anio')->name('erp.imp.iibb.cm05.list');

            // ARCIBA (CABA)
            Route::get('/iibb/caba/{periodo_id}', fn (\Illuminate\Http\Request $r, int $periodo_id) =>
                app(\App\Erp\Http\Controllers\Impuestos\IibbController::class)->showLocal($periodo_id, 'IIBB_CABA', $r))
                ->whereNumber('periodo_id')->name('erp.imp.iibb.caba.show');
            Route::post('/iibb/caba/{periodo_id}/calcular', fn (\Illuminate\Http\Request $r, int $periodo_id) =>
                app(\App\Erp\Http\Controllers\Impuestos\IibbController::class)->calcularLocal($periodo_id, 'IIBB_CABA', $r))
                ->whereNumber('periodo_id')->name('erp.imp.iibb.caba.calcular');
            Route::post('/iibb/caba/{periodo_id}/generar', fn (\Illuminate\Http\Request $r, int $periodo_id) =>
                app(\App\Erp\Http\Controllers\Impuestos\IibbController::class)->generarLocal($periodo_id, 'IIBB_CABA', $r))
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.iibb.caba.generar');

            // ARBA (PBA)
            Route::get('/iibb/pba/{periodo_id}', fn (\Illuminate\Http\Request $r, int $periodo_id) =>
                app(\App\Erp\Http\Controllers\Impuestos\IibbController::class)->showLocal($periodo_id, 'IIBB_PBA', $r))
                ->whereNumber('periodo_id')->name('erp.imp.iibb.pba.show');
            Route::post('/iibb/pba/{periodo_id}/calcular', fn (\Illuminate\Http\Request $r, int $periodo_id) =>
                app(\App\Erp\Http\Controllers\Impuestos\IibbController::class)->calcularLocal($periodo_id, 'IIBB_PBA', $r))
                ->whereNumber('periodo_id')->name('erp.imp.iibb.pba.calcular');
            Route::post('/iibb/pba/{periodo_id}/generar', fn (\Illuminate\Http\Request $r, int $periodo_id) =>
                app(\App\Erp\Http\Controllers\Impuestos\IibbController::class)->generarLocal($periodo_id, 'IIBB_PBA', $r))
                ->whereNumber('periodo_id')->middleware('erp.mfa.fresh')->name('erp.imp.iibb.pba.generar');

            Route::get('/iibb/{periodo_id}/descargar', [\App\Erp\Http\Controllers\Impuestos\IibbController::class, 'descargar'])
                ->whereNumber('periodo_id')->name('erp.imp.iibb.descargar');

            // Ganancias F.713 + anticipos (§6.6, H5)
            Route::get('/ganancias/anticipos', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'listarAnticipos'])
                ->name('erp.imp.gan.anticipos.list');
            Route::post('/ganancias/anticipos/{id}/pagar', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'pagarAnticipo'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.imp.gan.anticipos.pagar');

            Route::get('/ganancias/{ejercicio_id}', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'show'])
                ->whereNumber('ejercicio_id')->name('erp.imp.gan.show');
            Route::post('/ganancias/{ejercicio_id}/calcular', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'calcular'])
                ->whereNumber('ejercicio_id')->name('erp.imp.gan.calcular');
            Route::post('/ganancias/{ejercicio_id}/agregar-ajuste', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'agregarAjuste'])
                ->whereNumber('ejercicio_id')->middleware('erp.mfa.fresh')->name('erp.imp.gan.ajuste');
            Route::post('/ganancias/{ejercicio_id}/generar-f713', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'generar'])
                ->whereNumber('ejercicio_id')->middleware('erp.mfa.fresh')->name('erp.imp.gan.generar');
            Route::get('/ganancias/{ejercicio_id}/descargar', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'descargar'])
                ->whereNumber('ejercicio_id')->name('erp.imp.gan.descargar');
            Route::post('/ganancias/{ejercicio_id}/generar-anticipos', [\App\Erp\Http\Controllers\Impuestos\GananciasController::class, 'generarAnticipos'])
                ->whereNumber('ejercicio_id')->middleware('erp.mfa.fresh')->name('erp.imp.gan.generar-anticipos');

            // BP F.2000 + CRUD socios (§6.7, H6)
            Route::get('/bp/socios', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'listSocios'])
                ->name('erp.imp.bp.socios.list');
            Route::post('/bp/socios', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'storeSocio'])
                ->middleware('erp.mfa.fresh')->name('erp.imp.bp.socios.store');
            Route::patch('/bp/socios/{id}', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'updateSocio'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.imp.bp.socios.update');
            Route::delete('/bp/socios/{id}', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'destroySocio'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.imp.bp.socios.destroy');

            Route::get('/bp/{ejercicio_id}', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'show'])
                ->whereNumber('ejercicio_id')->name('erp.imp.bp.show');
            Route::post('/bp/{ejercicio_id}/calcular', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'calcular'])
                ->whereNumber('ejercicio_id')->name('erp.imp.bp.calcular');
            Route::post('/bp/{ejercicio_id}/generar-f2000', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'generar'])
                ->whereNumber('ejercicio_id')->middleware('erp.mfa.fresh')->name('erp.imp.bp.generar');
            Route::get('/bp/{ejercicio_id}/descargar', [\App\Erp\Http\Controllers\Impuestos\BpController::class, 'descargar'])
                ->whereNumber('ejercicio_id')->name('erp.imp.bp.descargar');
        });

        // ====================================================================
        // SPEC 05 H7 — Reportes contables y gerenciales (§6.8)
        // ====================================================================
        Route::prefix('reportes')->group(function () {
            Route::get('/mayor', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'mayor'])
                ->name('erp.reportes.mayor');
            Route::get('/diario', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'diario'])
                ->name('erp.reportes.diario');
            Route::get('/sumas-y-saldos', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'sumasYSaldos'])
                ->name('erp.reportes.sumas-y-saldos');
            Route::get('/libro-iva-interno', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'libroIvaInterno'])
                ->name('erp.reportes.libro-iva-interno');
            Route::get('/cc-clientes', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'ccClientes'])
                ->name('erp.reportes.cc-clientes');
            Route::get('/cc-proveedores', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'ccProveedores'])
                ->name('erp.reportes.cc-proveedores');
            Route::get('/aging', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'aging'])
                ->name('erp.reportes.aging');
            Route::get('/comparativo', [\App\Erp\Http\Controllers\ReportesContablesController::class, 'comparativo'])
                ->name('erp.reportes.comparativo');
        });

        // ====================================================================
        // SPEC 05 H8 — Estados Contables profesionales (§6.9)
        // ====================================================================
        Route::prefix('eecc')->group(function () {
            Route::get('/{ejercicio_id}/preview', [\App\Erp\Http\Controllers\Eecc\EecCController::class, 'preview'])
                ->whereNumber('ejercicio_id')->name('erp.eecc.preview');
            Route::post('/{ejercicio_id}/generar', [\App\Erp\Http\Controllers\Eecc\EecCController::class, 'generar'])
                ->whereNumber('ejercicio_id')->middleware('erp.mfa.fresh')->name('erp.eecc.generar');
            Route::get('/{ejercicio_id}/descargar', [\App\Erp\Http\Controllers\Eecc\EecCController::class, 'descargar'])
                ->whereNumber('ejercicio_id')->name('erp.eecc.descargar');
            Route::get('/{ejercicio_id}/notas', [\App\Erp\Http\Controllers\Eecc\EecCController::class, 'notas'])
                ->whereNumber('ejercicio_id')->name('erp.eecc.notas');
            Route::patch('/{ejercicio_id}/notas/{numero}', [\App\Erp\Http\Controllers\Eecc\EecCController::class, 'editarNota'])
                ->whereNumber('ejercicio_id')->whereNumber('numero')
                ->middleware('erp.mfa.fresh')->name('erp.eecc.notas.editar');
            Route::get('/{ejercicio_id}/emisiones', [\App\Erp\Http\Controllers\Eecc\EecCController::class, 'emisiones'])
                ->whereNumber('ejercicio_id')->name('erp.eecc.emisiones');
        });

        // ====================================================================
        // SPEC 06 I1 — Activos Fijos: categorías + bienes (alta + activar
        //   desde factura). Amortizaciones, mejoras, bajas y reportes vienen
        //   en bloques siguientes (I2 + I3).
        // ====================================================================
        Route::prefix('af')->group(function () {
            // Categorías
            Route::get('/categorias',       [\App\Erp\Http\Controllers\Af\AfCategoriasController::class, 'index'])
                ->name('erp.af.cats.index');
            Route::post('/categorias',      [\App\Erp\Http\Controllers\Af\AfCategoriasController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.af.cats.store');
            Route::get('/categorias/{id}',  [\App\Erp\Http\Controllers\Af\AfCategoriasController::class, 'show'])
                ->whereNumber('id')->name('erp.af.cats.show');
            Route::put('/categorias/{id}',  [\App\Erp\Http\Controllers\Af\AfCategoriasController::class, 'update'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.cats.update');
            Route::delete('/categorias/{id}', [\App\Erp\Http\Controllers\Af\AfCategoriasController::class, 'destroy'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.cats.destroy');

            // Bienes
            Route::get('/bienes',           [\App\Erp\Http\Controllers\Af\AfBienesController::class, 'index'])
                ->name('erp.af.bienes.index');
            Route::post('/bienes',          [\App\Erp\Http\Controllers\Af\AfBienesController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.af.bienes.store');
            Route::post('/bienes/activar-desde-factura', [\App\Erp\Http\Controllers\Af\AfBienesController::class, 'activarDesdeFactura'])
                ->middleware('erp.mfa.fresh')->name('erp.af.bienes.activar');
            Route::get('/bienes/{id}',      [\App\Erp\Http\Controllers\Af\AfBienesController::class, 'show'])
                ->whereNumber('id')->name('erp.af.bienes.show');
            Route::put('/bienes/{id}',      [\App\Erp\Http\Controllers\Af\AfBienesController::class, 'update'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.bienes.update');
            Route::get('/bienes/{id}/movimientos', [\App\Erp\Http\Controllers\Af\AfBienesController::class, 'movimientos'])
                ->whereNumber('id')->name('erp.af.bienes.movimientos');

            // I2: Amortizaciones + movimientos contables (mejora/revalúo/baja)
            Route::post('/amortizaciones/generar', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'generar'])
                ->middleware('erp.mfa.fresh')->name('erp.af.amort.generar');
            Route::get('/amortizaciones', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'listar'])
                ->name('erp.af.amort.listar');
            Route::get('/bienes/{id}/amortizaciones', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'porBien'])
                ->whereNumber('id')->name('erp.af.bienes.amort');

            Route::post('/bienes/{id}/mejora', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'mejora'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.bienes.mejora');
            Route::post('/bienes/{id}/revaluo', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'revaluo'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.bienes.revaluo');
            Route::post('/bienes/{id}/baja', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'baja'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.bienes.baja');

            Route::post('/movimientos/{id}/vincular-asiento', [\App\Erp\Http\Controllers\Af\AfAmortizacionesController::class, 'vincularAsiento'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.af.movs.vincular');

            // I3: Reportes + reexpresión RT 6
            Route::get('/reportes/listado',          [\App\Erp\Http\Controllers\Af\AfReportesController::class, 'listado'])
                ->name('erp.af.rep.listado');
            Route::get('/reportes/anexo-bienes-uso', [\App\Erp\Http\Controllers\Af\AfReportesController::class, 'anexoBienesUso'])
                ->name('erp.af.rep.anexo');
            Route::get('/reportes/altas-bajas',      [\App\Erp\Http\Controllers\Af\AfReportesController::class, 'altasBajas'])
                ->name('erp.af.rep.altasbajas');
            Route::get('/reportes/amortizaciones',   [\App\Erp\Http\Controllers\Af\AfReportesController::class, 'amortContVsFiscal'])
                ->name('erp.af.rep.amort');

            Route::post('/reexpresiones/generar',    [\App\Erp\Http\Controllers\Af\AfReportesController::class, 'generarReexpresion'])
                ->middleware('erp.mfa.fresh')->name('erp.af.reexp.generar');
            Route::get('/reexpresiones',             [\App\Erp\Http\Controllers\Af\AfReportesController::class, 'listarReexpresiones'])
                ->name('erp.af.reexp.list');
        });

        // ====================================================================
        // SPEC 06 I4 — Presupuestos: CRUD + reforecast + variaciones (§6.5/§6.6)
        // ====================================================================
        Route::prefix('presupuestos')->group(function () {
            Route::get('/',         [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'index'])
                ->name('erp.presup.index');
            Route::post('/',        [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.presup.store');
            Route::get('/{id}',     [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'show'])
                ->whereNumber('id')->name('erp.presup.show');
            Route::put('/{id}',     [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'update'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.presup.update');
            Route::post('/{id}/aprobar',    [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'aprobar'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.presup.aprobar');
            Route::post('/{id}/vigente',    [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'vigente'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.presup.vigente');
            Route::post('/{id}/descartar',  [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'descartar'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.presup.descartar');
            Route::post('/{id}/reforecast', [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'reforecast'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.presup.reforecast');

            Route::post('/{id}/items',         [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'bulkItems'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.presup.items.bulk');
            Route::get('/{id}/items',          [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'listItems'])
                ->whereNumber('id')->name('erp.presup.items.list');
            Route::delete('/{id}/items/{itemId}', [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'deleteItem'])
                ->whereNumber('id')->whereNumber('itemId')->middleware('erp.mfa.fresh')->name('erp.presup.items.del');

            Route::get('/{id}/variaciones',          [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'variaciones'])
                ->whereNumber('id')->name('erp.presup.variaciones');
            Route::get('/{id}/variaciones/resumen',  [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'variacionesResumen'])
                ->whereNumber('id')->name('erp.presup.variaciones.resumen');
            Route::get('/{id}/ejecucion',            [\App\Erp\Http\Controllers\Presupuesto\PresupuestosController::class, 'ejecucion'])
                ->whereNumber('id')->name('erp.presup.ejecucion');
        });
    });
});

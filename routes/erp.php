<?php

use App\Erp\Http\Controllers\AsientosController;
use App\Erp\Http\Controllers\AuthController;
use App\Erp\Http\Controllers\BalanceController;
use App\Erp\Http\Controllers\CatalogosController;
use App\Erp\Http\Controllers\CuentasContablesController;
use App\Erp\Http\Controllers\EjerciciosController;
use App\Erp\Http\Controllers\EstadosContablesController;
use App\Erp\Http\Controllers\HealthController;
use App\Erp\Http\Controllers\LibroMayorController;
use App\Erp\Http\Controllers\MovimientosBancariosController;
use App\Erp\Http\Controllers\OrdenesPagoController;
use App\Erp\Http\Controllers\PeriodosController;
use Illuminate\Support\Facades\Route;

Route::prefix('api/erp')->group(function () {
    // Endpoints públicos
    Route::get('/health', HealthController::class)->name('erp.health');
    Route::post('/auth/login', [AuthController::class, 'login'])->name('erp.auth.login');

    // Pre-MFA (token con ability "mfa:challenge")
    Route::middleware('auth:sanctum')
        ->post('/auth/mfa/verify', [AuthController::class, 'verifyMfa'])
        ->name('erp.auth.mfa.verify');

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
            ->whereNumber('id')->name('erp.asientos.anular');
        Route::delete('/asientos/{id}', [AsientosController::class, 'destroy'])
            ->whereNumber('id')->name('erp.asientos.destroy');

        // Libro mayor
        Route::get('/libro-mayor', [LibroMayorController::class, 'index'])->name('erp.libro-mayor');

        // Balance Sumas y Saldos
        Route::get('/balance-sumas-saldos', [BalanceController::class, 'sumasSaldos'])
            ->name('erp.balance-ss');

        // Cierre / apertura de período
        Route::post('/periodos/{id}/cerrar', [PeriodosController::class, 'cerrar'])
            ->whereNumber('id')->name('erp.periodos.cerrar');
        Route::post('/periodos/{id}/reabrir', [PeriodosController::class, 'reabrir'])
            ->whereNumber('id')->name('erp.periodos.reabrir');

        // Cierre / apertura de ejercicio (asiento refundición automático al cerrar)
        Route::post('/ejercicios/{id}/cerrar', [EjerciciosController::class, 'cerrar'])
            ->whereNumber('id')->name('erp.ejercicios.cerrar');
        Route::post('/ejercicios/{id}/reabrir', [EjerciciosController::class, 'reabrir'])
            ->whereNumber('id')->name('erp.ejercicios.reabrir');

        // Estados contables (FACPCE)
        Route::get('/estados-contables/situacion-patrimonial',
            [EstadosContablesController::class, 'situacionPatrimonial'])->name('erp.ec.sp');
        Route::get('/estados-contables/resultados',
            [EstadosContablesController::class, 'resultados'])->name('erp.ec.er');

        // Tesorería — movimientos bancarios
        Route::get('/movimientos-bancarios', [MovimientosBancariosController::class, 'index'])->name('erp.mov-banc.index');
        Route::post('/movimientos-bancarios', [MovimientosBancariosController::class, 'store'])->name('erp.mov-banc.store');
        Route::post('/movimientos-bancarios/{id}/conciliar', [MovimientosBancariosController::class, 'conciliar'])
            ->whereNumber('id')->name('erp.mov-banc.conciliar');
        Route::post('/movimientos-bancarios/{id}/ignorar', [MovimientosBancariosController::class, 'ignorar'])
            ->whereNumber('id')->name('erp.mov-banc.ignorar');

        // Tesorería — órdenes de pago
        Route::get('/ordenes-pago', [OrdenesPagoController::class, 'index'])->name('erp.op.index');
        Route::post('/ordenes-pago', [OrdenesPagoController::class, 'store'])->name('erp.op.store');
        Route::post('/ordenes-pago/{id}/pagar', [OrdenesPagoController::class, 'pagar'])
            ->whereNumber('id')->name('erp.op.pagar');
        Route::post('/ordenes-pago/{id}/anular', [OrdenesPagoController::class, 'anular'])
            ->whereNumber('id')->name('erp.op.anular');

        // Facturación (venta)
        Route::get('/facturas-venta', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'index'])
            ->name('erp.fv.index');
        Route::get('/facturas-venta/{id}', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'show'])
            ->whereNumber('id')->name('erp.fv.show');

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
    });
});

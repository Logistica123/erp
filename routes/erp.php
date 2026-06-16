<?php

use App\Erp\Http\Controllers\ArcaController;
use App\Erp\Http\Controllers\AliasContraparteController;
use App\Erp\Http\Controllers\LibroIvaComprasExportController;
use App\Erp\Http\Controllers\LibroIvaComprasImportController;
use App\Erp\Http\Controllers\LibroIvaVentasImportController;
use App\Erp\Http\Controllers\AsientosController;
use App\Erp\Http\Controllers\AuditoriaController;
use App\Erp\Http\Controllers\AuthController;
use App\Erp\Http\Controllers\AuxiliaresController;
use App\Erp\Http\Controllers\ConciliacionReglasController;
use App\Erp\Http\Controllers\BalanceController;
use App\Erp\Http\Controllers\CajaController;
use App\Erp\Http\Controllers\ConfigController;
use App\Erp\Http\Controllers\CobrosController;
use App\Erp\Http\Controllers\FacturasManualController;
use App\Erp\Http\Controllers\ImputacionesNcController;
use App\Erp\Http\Controllers\CotizacionesController;
use App\Erp\Http\Controllers\EcheqController;
use App\Erp\Http\Controllers\ConciliacionExtrasController;
use App\Erp\Http\Controllers\ExtractosController;
use App\Erp\Http\Controllers\LibroDiarioController;
use App\Erp\Http\Controllers\RevaluacionController;
use App\Erp\Http\Controllers\ConfiguracionIvaMapeoController;
use App\Erp\Http\Controllers\RolesPermisosController;
use App\Erp\Http\Controllers\TransferenciasInternasController;
use App\Erp\Http\Controllers\UsuariosController;
use App\Erp\Http\Controllers\CatalogosController;
use App\Erp\Http\Controllers\CentrosCostoController;
use App\Erp\Http\Controllers\CuentasContablesController;
use App\Erp\Http\Controllers\EjerciciosController;
use App\Erp\Http\Controllers\EstadosContablesController;
use App\Erp\Http\Controllers\HealthController;
use App\Erp\Http\Controllers\LibroMayorController;
use App\Erp\Http\Controllers\MovimientosBancariosController;
use App\Erp\Http\Controllers\OrdenesPagoController;
use App\Erp\Http\Controllers\PeriodosController;
use App\Erp\Http\Controllers\ReportesTesoreriaController;
use App\Erp\Http\Controllers\ReportesV14Controller;
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
        // Catálogo simple (compat con SelectField).
        Route::get('/centros-costo', [CatalogosController::class, 'centrosCosto'])->name('erp.centros-costo');
        // v1.14 ampliación — ABM completo (listado paginado/filtros + CRUD).
        Route::get('/centros-costo/abm', [CentrosCostoController::class, 'index'])->name('erp.cc.abm.index');
        Route::post('/centros-costo', [CentrosCostoController::class, 'store'])->name('erp.cc.store');
        Route::put('/centros-costo/{id}', [CentrosCostoController::class, 'update'])
            ->whereNumber('id')->name('erp.cc.update');
        Route::delete('/centros-costo/{id}', [CentrosCostoController::class, 'destroy'])
            ->whereNumber('id')->name('erp.cc.destroy');
        Route::post('/centros-costo/{id}/reactivar', [CentrosCostoController::class, 'reactivar'])
            ->whereNumber('id')->name('erp.cc.reactivar');
        Route::get('/auxiliares', [CatalogosController::class, 'auxiliares'])->name('erp.auxiliares');
        // v1.24.1 — endpoint que faltaba desde v1.17, lo llama FacturaCompraManualPage.
        Route::get('/tipos-comprobante', [CatalogosController::class, 'tiposComprobante'])
            ->name('erp.tipos-comprobante');
        Route::get('/auxiliares/buscar', [AuxiliaresController::class, 'buscar'])->name('erp.auxiliares.buscar');
        // v1.17 helper: lookup por CUIT.
        Route::get('/auxiliares/by-cuit/{cuit}', [AuxiliaresController::class, 'byCuit'])
            ->where('cuit', '[0-9]{11}')->name('erp.auxiliares.by-cuit');
        Route::post('/auxiliares', [AuxiliaresController::class, 'store'])->name('erp.auxiliares.store');
        Route::patch('/auxiliares/{id}', [AuxiliaresController::class, 'update'])
            ->whereNumber('id')->name('erp.auxiliares.update');
        Route::get('/auxiliares/{id}/saldo', [AuxiliaresController::class, 'saldo'])
            ->whereNumber('id')->name('erp.auxiliares.saldo');
        // v1.15 ampliación (CC-09) — baja cliente con opción de desactivar CC.
        Route::get('/auxiliares/{id}/cc-asociado', [AuxiliaresController::class, 'ccAsociado'])
            ->whereNumber('id')->name('erp.auxiliares.cc-asociado');
        Route::post('/auxiliares/{id}/desactivar', [AuxiliaresController::class, 'desactivar'])
            ->whereNumber('id')->name('erp.auxiliares.desactivar');
        Route::get('/bancos', [CatalogosController::class, 'bancos'])->name('erp.bancos');
        Route::get('/cuentas-bancarias', [CatalogosController::class, 'cuentasBancarias'])->name('erp.cuentas-bancarias');
        // v1.18 Sprint T — bimoneda.
        Route::get('/cuentas-bancarias/{id}/monedas-aceptadas', [CatalogosController::class, 'monedasAceptadas'])
            ->whereNumber('id')->name('erp.cuentas-bancarias.monedas');
        Route::get('/cajas', [CatalogosController::class, 'cajas'])->name('erp.cajas');
        Route::get('/medios-pago', [CatalogosController::class, 'mediosPago'])->name('erp.medios-pago');

        // Contabilidad — catálogo de cuentas
        Route::get('/cuentas', [CuentasContablesController::class, 'index'])->name('erp.cuentas.index');
        // ADDENDUM v1.15 Sprint L — exportar CSV (debe ir antes de /{id} para no shadowear).
        Route::get('/cuentas/exportar', [CuentasContablesController::class, 'exportar'])
            ->name('erp.cuentas.exportar');
        // ADDENDUM v1.15 Sprint M+ — endpoint del SelectorCuentaContable.
        Route::get('/cuentas/imputables', [CuentasContablesController::class, 'imputables'])
            ->name('erp.cuentas.imputables');
        Route::post('/cuentas', [CuentasContablesController::class, 'store'])->name('erp.cuentas.store');
        Route::delete('/cuentas/{id}', [CuentasContablesController::class, 'destroy'])
            ->whereNumber('id')->name('erp.cuentas.destroy');
        // v1.15 Sprint L+
        Route::post('/cuentas/{id}/reactivar', [CuentasContablesController::class, 'reactivar'])
            ->whereNumber('id')->name('erp.cuentas.reactivar');
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
        // Hard-delete con audit inmutable (super_admin + MFA fresh + motivo).
        Route::delete('/asientos/{id}/definitivo', [AsientosController::class, 'eliminarDefinitivo'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.asientos.eliminar-definitivo');

        // Plantillas/modelos de asiento (asientos repetitivos: sueldos, etc).
        Route::get('/asiento-plantillas', [\App\Erp\Http\Controllers\AsientoPlantillasController::class, 'index'])
            ->name('erp.asiento-plantillas.index');
        Route::post('/asiento-plantillas', [\App\Erp\Http\Controllers\AsientoPlantillasController::class, 'store'])
            ->name('erp.asiento-plantillas.store');
        Route::get('/asiento-plantillas/{id}', [\App\Erp\Http\Controllers\AsientoPlantillasController::class, 'show'])
            ->whereNumber('id')->name('erp.asiento-plantillas.show');
        Route::delete('/asiento-plantillas/{id}', [\App\Erp\Http\Controllers\AsientoPlantillasController::class, 'destroy'])
            ->whereNumber('id')->name('erp.asiento-plantillas.destroy');

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
        // v1.27 Sprint A — conciliación directa para COMISION/IMPUESTO/INTERES.
        Route::post('/movimientos-bancarios/{id}/conciliar-directo', [MovimientosBancariosController::class, 'conciliarDirecto'])
            ->whereNumber('id')->name('erp.mov-banc.conciliar-directo');
        // v1.27 Sprint C — sugerencias + conciliación contra factura.
        Route::get('/movimientos-bancarios/{id}/sugerencias', [MovimientosBancariosController::class, 'sugerencias'])
            ->whereNumber('id')->name('erp.mov-banc.sugerencias');
        Route::post('/movimientos-bancarios/{id}/conciliar-multiple', [MovimientosBancariosController::class, 'conciliarMultiple'])
            ->whereNumber('id')->name('erp.mov-banc.conciliar-multiple');

        // v1.48 — conciliación extras (Bloques D/E/F/G).
        Route::get('/conciliacion/motivos', [ConciliacionExtrasController::class, 'motivos'])
            ->name('erp.concil.motivos');
        Route::get('/conciliacion/transferencias-internas-pendientes', [ConciliacionExtrasController::class, 'transferenciasInternasPendientes'])
            ->name('erp.concil.transf-internas');
        Route::post('/conciliacion/transferencias-internas/{movId}/emparejar', [ConciliacionExtrasController::class, 'emparejarTransferenciaInterna'])
            ->whereNumber('movId')->name('erp.concil.transf-emparejar');
        Route::post('/conciliacion/transferencias-internas/{movId}/descartar', [ConciliacionExtrasController::class, 'descartarTransferenciaInterna'])
            ->whereNumber('movId')->name('erp.concil.transf-descartar');
        Route::get('/compras/pendientes-facturar', [ConciliacionExtrasController::class, 'pendientesFacturar'])
            ->name('erp.concil.pendientes-facturar');
        Route::get('/compras/pendientes-facturar/export', [ConciliacionExtrasController::class, 'exportPendientesFacturar'])
            ->name('erp.concil.pendientes-facturar.export');
        Route::patch('/compras/pendientes-facturar/{movId}/asociar-nc', [ConciliacionExtrasController::class, 'asociarNc'])
            ->whereNumber('movId')->name('erp.concil.pendientes-asociar-nc');
        Route::patch('/compras/pendientes-facturar/{movId}/anular', [ConciliacionExtrasController::class, 'anularPendiente'])
            ->whereNumber('movId')->name('erp.concil.pendientes-anular');
        Route::get('/contabilidad/conciliaciones-con-diferencia', [ConciliacionExtrasController::class, 'conciliacionesConDiferencia'])
            ->name('erp.concil.con-diferencia');
        Route::get('/contabilidad/conciliaciones-con-diferencia/export', [ConciliacionExtrasController::class, 'exportConDiferencia'])
            ->name('erp.concil.con-diferencia.export');
        Route::post('/movimientos-bancarios/{id}/conciliar-factura', [MovimientosBancariosController::class, 'conciliarFactura'])
            ->whereNumber('id')->name('erp.mov-banc.conciliar-factura');
        // v1.27 §15 — Búsqueda de auxiliares + facturas pendientes para modal manual.
        Route::get('/movimientos-bancarios/buscar-auxiliar', [MovimientosBancariosController::class, 'buscarAuxiliares'])
            ->name('erp.mov-banc.buscar-auxiliar');
        Route::get('/movimientos-bancarios/facturas-pendientes', [MovimientosBancariosController::class, 'facturasPendientesAuxiliar'])
            ->name('erp.mov-banc.facturas-pendientes');
        // v1.27 §16 — Borrar bulk + confirmar auto-etiquetados.
        Route::delete('/movimientos-bancarios/bulk', [MovimientosBancariosController::class, 'borrarBulk'])
            ->name('erp.mov-banc.borrar-bulk');
        Route::post('/movimientos-bancarios/confirmar-auto-etiquetados', [MovimientosBancariosController::class, 'confirmarAutoEtiquetados'])
            ->name('erp.mov-banc.confirmar-auto');
        Route::post('/movimientos-bancarios/{id}/desconciliar', [MovimientosBancariosController::class, 'desconciliar'])
            ->middleware('erp.mfa.fresh')
            ->whereNumber('id')->name('erp.mov-banc.desconciliar');
        Route::post('/movimientos-bancarios/{id}/ignorar', [MovimientosBancariosController::class, 'ignorar'])
            ->whereNumber('id')->name('erp.mov-banc.ignorar');

        // SPEC Conciliación CM-4 — batch + preview matching + reglas + aliases
        Route::post('/movimientos-bancarios/batch', [MovimientosBancariosController::class, 'batch'])
            ->name('erp.mov-banc.batch');
        Route::get('/movimientos-bancarios/{id}/match-preview', [MovimientosBancariosController::class, 'matchPreview'])
            ->whereNumber('id')->name('erp.mov-banc.match-preview');

        Route::get('/conciliacion-reglas', [ConciliacionReglasController::class, 'index'])->name('erp.conc-reglas.index');
        Route::post('/conciliacion-reglas', [ConciliacionReglasController::class, 'store'])->name('erp.conc-reglas.store');
        Route::get('/conciliacion-reglas/{id}', [ConciliacionReglasController::class, 'show'])
            ->whereNumber('id')->name('erp.conc-reglas.show');
        Route::patch('/conciliacion-reglas/{id}', [ConciliacionReglasController::class, 'update'])
            ->whereNumber('id')->name('erp.conc-reglas.update');
        Route::delete('/conciliacion-reglas/{id}', [ConciliacionReglasController::class, 'destroy'])
            ->whereNumber('id')->name('erp.conc-reglas.destroy');
        Route::post('/conciliacion-reglas/{id}/probar', [ConciliacionReglasController::class, 'probar'])
            ->whereNumber('id')->name('erp.conc-reglas.probar');

        // v1.45 — Imputaciones automáticas con extractor CUIT (MATCH_AUTO).
        Route::get('/conciliacion/imputaciones-pendientes', [\App\Erp\Http\Controllers\ImputacionesAutoController::class, 'pendientes'])
            ->name('erp.conc.imput.pendientes');
        Route::patch('/conciliacion/{mov}/modificar', [\App\Erp\Http\Controllers\ImputacionesAutoController::class, 'modificar'])
            ->whereNumber('mov')->name('erp.conc.imput.modificar');
        Route::post('/conciliacion/{mov}/confirmar', [\App\Erp\Http\Controllers\ImputacionesAutoController::class, 'confirmar'])
            ->whereNumber('mov')->name('erp.conc.imput.confirmar');
        Route::post('/conciliacion/{mov}/revertir', [\App\Erp\Http\Controllers\ImputacionesAutoController::class, 'revertir'])
            ->whereNumber('mov')->name('erp.conc.imput.revertir');
        Route::get('/conciliacion/{mov}/audit', [\App\Erp\Http\Controllers\ImputacionesAutoController::class, 'audit'])
            ->whereNumber('mov')->name('erp.conc.imput.audit');

        // v1.45 §9 — Reclasificación Imp Ley 25413.
        Route::get('/contabilidad/iiddycc/saldo-acumulado', [\App\Erp\Http\Controllers\ReclasificacionIiddyccController::class, 'saldo'])
            ->name('erp.iiddycc.saldo');
        Route::post('/contabilidad/iiddycc/reclasificar', [\App\Erp\Http\Controllers\ReclasificacionIiddyccController::class, 'reclasificar'])
            ->name('erp.iiddycc.reclasificar');

        // v1.47 §15 — Conciliación en lote N:M.
        Route::get('/conciliacion/lotes/candidatos', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'candidatos'])
            ->name('erp.conc.lotes.candidatos');
        Route::get('/conciliacion/lotes', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'index'])
            ->name('erp.conc.lotes.index');
        Route::post('/conciliacion/lotes', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'store'])
            ->name('erp.conc.lotes.store');
        Route::get('/conciliacion/lotes/{id}', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'show'])
            ->whereNumber('id')->name('erp.conc.lotes.show');
        Route::post('/conciliacion/lotes/{id}/confirmar', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'confirmar'])
            ->whereNumber('id')->name('erp.conc.lotes.confirmar');
        Route::post('/conciliacion/lotes/{id}/revertir', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'revertir'])
            ->whereNumber('id')->name('erp.conc.lotes.revertir');
        Route::delete('/conciliacion/lotes/{id}', [\App\Erp\Http\Controllers\ConciliacionLotesController::class, 'destroy'])
            ->whereNumber('id')->name('erp.conc.lotes.destroy');

        // v1.47 §14.3 — Saneamiento cuenta puente 1.1.6.99.
        Route::get('/contabilidad/pendientes-identificar', [\App\Erp\Http\Controllers\ReclasificacionPendientesController::class, 'index'])
            ->name('erp.pendientes.index');
        Route::get('/contabilidad/pendientes-identificar/saldo', [\App\Erp\Http\Controllers\ReclasificacionPendientesController::class, 'saldo'])
            ->name('erp.pendientes.saldo');
        Route::post('/contabilidad/pendientes-identificar/reclasificar', [\App\Erp\Http\Controllers\ReclasificacionPendientesController::class, 'reclasificar'])
            ->name('erp.pendientes.reclasificar');

        Route::get('/alias-contraparte', [AliasContraparteController::class, 'index'])->name('erp.alias.index');
        Route::post('/alias-contraparte', [AliasContraparteController::class, 'store'])->name('erp.alias.store');
        Route::patch('/alias-contraparte/{id}', [AliasContraparteController::class, 'update'])
            ->whereNumber('id')->name('erp.alias.update');
        Route::delete('/alias-contraparte/{id}', [AliasContraparteController::class, 'destroy'])
            ->whereNumber('id')->name('erp.alias.destroy');

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
        // v1.42 Fase A — arqueos con autorización + grilla + operadores.
        Route::get('/caja/arqueos-pendientes', [CajaController::class, 'arqueosPendientes'])
            ->name('erp.caja.arqueos-pendientes');
        Route::post('/caja/arqueos/{id}/autorizar', [CajaController::class, 'autorizarArqueo'])
            ->whereNumber('id')->name('erp.caja.arqueo.autorizar');
        Route::get('/caja/denominaciones-catalogo', [CajaController::class, 'denominacionesCatalogo'])
            ->name('erp.caja.denominaciones-catalogo');
        Route::get('/users-lookup', [\App\Erp\Http\Controllers\CajaOperadoresController::class, 'usersLookup'])
            ->name('erp.users-lookup');
        Route::get('/caja/operadores', [\App\Erp\Http\Controllers\CajaOperadoresController::class, 'index'])
            ->name('erp.caja.operadores.index');
        Route::post('/caja/operadores', [\App\Erp\Http\Controllers\CajaOperadoresController::class, 'store'])
            ->name('erp.caja.operadores.store');
        Route::delete('/caja/operadores/{id}', [\App\Erp\Http\Controllers\CajaOperadoresController::class, 'destroy'])
            ->whereNumber('id')->name('erp.caja.operadores.destroy');

        // v1.42 Fase B — Flujo de Fondos
        Route::get('/flujo-fondos/escenarios', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'escenariosIndex'])
            ->name('erp.flujo.escenarios.index');
        Route::post('/flujo-fondos/escenarios', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'escenariosStore'])
            ->name('erp.flujo.escenarios.store');
        Route::post('/flujo-fondos/escenarios/{id}/clonar', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'escenariosClonar'])
            ->whereNumber('id')->name('erp.flujo.escenarios.clonar');
        Route::get('/flujo-fondos/{id}/matriz', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'matriz'])
            ->whereNumber('id')->name('erp.flujo.matriz');
        Route::patch('/flujo-fondos/{id}/celda', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'overrideCelda'])
            ->whereNumber('id')->name('erp.flujo.celda.override');
        Route::post('/flujo-fondos/{id}/recalcular', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'recalcular'])
            ->whereNumber('id')->name('erp.flujo.recalcular');
        Route::get('/flujo-fondos/{id}/drill', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'drill'])
            ->whereNumber('id')->name('erp.flujo.drill');
        Route::get('/flujo-fondos/categorias', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'categoriasIndex'])
            ->name('erp.flujo.categorias.index');
        Route::get('/flujo-fondos/calendario-cobros', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'calendarioCobrosIndex'])
            ->name('erp.flujo.calendario-cobros.index');
        Route::post('/flujo-fondos/calendario-cobros', [\App\Erp\Http\Controllers\FlujoFondosController::class, 'calendarioCobrosUpsert'])
            ->name('erp.flujo.calendario-cobros.upsert');

        // v1.42 Fase C — Inversiones
        Route::get('/inversiones', [\App\Erp\Http\Controllers\InversionesController::class, 'index'])
            ->name('erp.inversiones.index');
        Route::post('/inversiones', [\App\Erp\Http\Controllers\InversionesController::class, 'store'])
            ->name('erp.inversiones.store');
        Route::get('/inversiones/{id}', [\App\Erp\Http\Controllers\InversionesController::class, 'show'])
            ->whereNumber('id')->name('erp.inversiones.show');
        Route::get('/inversiones/{id}/movimientos', [\App\Erp\Http\Controllers\InversionesController::class, 'movimientos'])
            ->whereNumber('id')->name('erp.inversiones.movimientos');
        Route::post('/inversiones/{id}/movimientos', [\App\Erp\Http\Controllers\InversionesController::class, 'registrarMovimiento'])
            ->whereNumber('id')->name('erp.inversiones.movimientos.store');

        // v1.42 Fase D — Préstamos
        Route::get('/prestamos', [\App\Erp\Http\Controllers\PrestamosController::class, 'index'])
            ->name('erp.prestamos.index');
        Route::post('/prestamos', [\App\Erp\Http\Controllers\PrestamosController::class, 'store'])
            ->name('erp.prestamos.store');
        Route::get('/prestamos/{id}', [\App\Erp\Http\Controllers\PrestamosController::class, 'show'])
            ->whereNumber('id')->name('erp.prestamos.show');
        Route::post('/prestamos/{id}/cuotas/{cuotaId}/pagar', [\App\Erp\Http\Controllers\PrestamosController::class, 'pagarCuota'])
            ->whereNumber('id')->whereNumber('cuotaId')->name('erp.prestamos.pagar');
        Route::post('/prestamos/{id}/cancelar', [\App\Erp\Http\Controllers\PrestamosController::class, 'cancelar'])
            ->whereNumber('id')->name('erp.prestamos.cancelar');

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
        // ADDENDUM v1.15 Sprint O — items-cobrables (debe ir antes de /{id}).
        Route::get('/cobros/items-cobrables', [CobrosController::class, 'itemsCobrables'])
            ->name('erp.cobros.items-cobrables');
        Route::get('/cobros', [CobrosController::class, 'index'])->name('erp.cobros.index');
        Route::post('/cobros', [CobrosController::class, 'store'])->name('erp.cobros.store');
        Route::get('/cobros/{id}', [CobrosController::class, 'show'])
            ->whereNumber('id')->name('erp.cobros.show');
        Route::post('/cobros/{id}/anular', [CobrosController::class, 'anular'])
            ->whereNumber('id')->name('erp.cobros.anular');

        // v1.31 — Recibos (cobranza unificada con NC + retenciones).
        Route::get('/tesoreria/recibos', [\App\Erp\Http\Controllers\RecibosController::class, 'index'])
            ->name('erp.recibos.index');
        Route::post('/tesoreria/recibos/auto-imputar-nc', [\App\Erp\Http\Controllers\RecibosController::class, 'autoImputarNc'])
            ->name('erp.recibos.auto-imputar-nc');
        Route::post('/tesoreria/recibos', [\App\Erp\Http\Controllers\RecibosController::class, 'store'])
            ->name('erp.recibos.store');
        Route::get('/tesoreria/recibos/{id}', [\App\Erp\Http\Controllers\RecibosController::class, 'show'])
            ->whereNumber('id')->name('erp.recibos.show');
        // v1.32 — Editar un borrador (delete + reinsert imputaciones / NC /
        // retenciones, recalcula totales).
        Route::patch('/tesoreria/recibos/{id}', [\App\Erp\Http\Controllers\RecibosController::class, 'update'])
            ->whereNumber('id')->name('erp.recibos.update');
        Route::post('/tesoreria/recibos/{id}/emitir', [\App\Erp\Http\Controllers\RecibosController::class, 'emitir'])
            ->whereNumber('id')->name('erp.recibos.emitir');
        Route::post('/tesoreria/recibos/{id}/anular', [\App\Erp\Http\Controllers\RecibosController::class, 'anular'])
            ->whereNumber('id')->name('erp.recibos.anular');
        Route::get('/clientes/{id}/notas-credito-libres', [\App\Erp\Http\Controllers\RecibosController::class, 'ncLibresCliente'])
            ->whereNumber('id')->name('erp.clientes.nc-libres');

        // v1.32 — Endpoints rediseño Recibos modelo DistriApp.
        Route::get('/clientes/para-recibos', [\App\Erp\Http\Controllers\RecibosController::class, 'clientesParaRecibos'])
            ->name('erp.clientes.para-recibos');
        Route::get('/clientes/{id}/facturas-imputables-recibo', [\App\Erp\Http\Controllers\RecibosController::class, 'facturasImputablesCliente'])
            ->whereNumber('id')->name('erp.clientes.facturas-imputables');
        Route::get('/tesoreria/recibos/proximo-numero', [\App\Erp\Http\Controllers\RecibosController::class, 'proximoNumero'])
            ->name('erp.recibos.proximo-numero');

        // Cheques recibidos (papel) — capturados al emitir recibo con medio CHEQUES_CARTERA.
        Route::get('/tesoreria/cheques-recibidos', [\App\Erp\Http\Controllers\ChequesRecibidosController::class, 'index'])
            ->name('erp.cheques-recibidos.index');
        Route::get('/tesoreria/cheques-recibidos/alertas', [\App\Erp\Http\Controllers\ChequesRecibidosController::class, 'alertas'])
            ->name('erp.cheques-recibidos.alertas');
        Route::post('/tesoreria/cheques-recibidos/{id}/depositar', [\App\Erp\Http\Controllers\ChequesRecibidosController::class, 'depositar'])
            ->whereNumber('id')->name('erp.cheques-recibidos.depositar');
        Route::post('/tesoreria/cheques-recibidos/{id}/cobrar', [\App\Erp\Http\Controllers\ChequesRecibidosController::class, 'cobrar'])
            ->whereNumber('id')->name('erp.cheques-recibidos.cobrar');
        Route::post('/tesoreria/cheques-recibidos/{id}/rechazar', [\App\Erp\Http\Controllers\ChequesRecibidosController::class, 'rechazar'])
            ->whereNumber('id')->name('erp.cheques-recibidos.rechazar');
        Route::post('/tesoreria/cheques-recibidos/marcar-vencidos', [\App\Erp\Http\Controllers\ChequesRecibidosController::class, 'marcarVencidos'])
            ->name('erp.cheques-recibidos.marcar-vencidos');

        // ADDENDUM v1.15 Sprint O — Imputación de Notas de Crédito.
        Route::get('/imputaciones-nc', [ImputacionesNcController::class, 'index'])
            ->name('erp.imputaciones-nc.index');
        Route::post('/imputaciones-nc', [ImputacionesNcController::class, 'store'])
            ->name('erp.imputaciones-nc.store');
        Route::delete('/imputaciones-nc/{id}', [ImputacionesNcController::class, 'destroy'])
            ->whereNumber('id')->name('erp.imputaciones-nc.destroy');

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
        // v1.35 — endpoints nuevos (antes de {id} para evitar colisión).
        Route::get('/ordenes-pago/tipos', [OrdenesPagoController::class, 'tipos'])->name('erp.op.tipos');
        Route::get('/ordenes-pago/sync/estado', [OrdenesPagoController::class, 'syncEstado'])->name('erp.op.sync-estado');
        Route::post('/ordenes-pago/sync', [OrdenesPagoController::class, 'sync'])->name('erp.op.sync');
        Route::post('/ordenes-pago/local', [OrdenesPagoController::class, 'storeLocal'])->name('erp.op.store-local');
        Route::post('/ordenes-pago', [OrdenesPagoController::class, 'store'])->name('erp.op.store');
        Route::get('/ordenes-pago/{id}', [OrdenesPagoController::class, 'show'])
            ->whereNumber('id')->name('erp.op.show');
        Route::get('/ordenes-pago/{id}/audit', [OrdenesPagoController::class, 'auditList'])
            ->whereNumber('id')->name('erp.op.audit');
        Route::post('/ordenes-pago/{id}/registrar-pago', [OrdenesPagoController::class, 'registrarPago'])
            ->whereNumber('id')->name('erp.op.registrar-pago');
        Route::post('/ordenes-pago/{id}/contabilizar', [OrdenesPagoController::class, 'contabilizar'])
            ->whereNumber('id')->name('erp.op.contabilizar');
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

        // v1.24 — ABM del mapeo concepto AFIP → cuenta contable (importer Libro IVA).
        Route::get('/contabilidad/iva-mapeo', [ConfiguracionIvaMapeoController::class, 'index'])
            ->name('erp.contabilidad.iva-mapeo.index');
        Route::put('/contabilidad/iva-mapeo/{concepto}', [ConfiguracionIvaMapeoController::class, 'update'])
            ->name('erp.contabilidad.iva-mapeo.update');

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
        Route::get('/auditoria/{id}', [AuditoriaController::class, 'show'])
            ->whereNumber('id')->name('erp.auditoria.show');
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

        // ADDENDUM v1.9 — Import enriquecido del Libro IVA Compras
        Route::post('/libro-iva-compras/import/preview', [LibroIvaComprasImportController::class, 'preview'])
            ->name('erp.livc.preview');
        Route::post('/libro-iva-compras/import/confirmar', [LibroIvaComprasImportController::class, 'confirmar'])
            ->name('erp.livc.confirmar');
        Route::get('/libro-iva-compras/imports', [LibroIvaComprasImportController::class, 'imports'])
            ->name('erp.livc.imports');
        // v1.19 — descarga CSV de errores del import.
        Route::get('/libro-iva-compras/imports/{id}/errores.csv', [LibroIvaComprasImportController::class, 'descargarErrores'])
            ->whereNumber('id')->name('erp.livc.import-errores-csv');
        Route::get('/libro-iva-compras/imports/{id}', [LibroIvaComprasImportController::class, 'importDetalle'])
            ->whereNumber('id')->name('erp.livc.import-detalle');
        // v1.20 — borrar upload (super_admin solamente, sin facturas vinculadas).
        Route::delete('/libro-iva-compras/imports/{id}', [LibroIvaComprasImportController::class, 'destroy'])
            ->whereNumber('id')->name('erp.livc.import-destroy');
        Route::get('/libro-iva-compras/no-tomadas', [LibroIvaComprasImportController::class, 'noTomadas'])
            ->name('erp.livc.no-tomadas');
        Route::post('/libro-iva-compras/no-tomadas/tomar', [LibroIvaComprasImportController::class, 'tomarFacturas'])
            ->name('erp.livc.no-tomadas.tomar');
        Route::post('/libro-iva-compras/destomar', [LibroIvaComprasImportController::class, 'destomarFacturas'])
            ->name('erp.livc.destomar');

        // v1.45 — Importador del Libro IVA Ventas (espejo del v1.9 compras).
        Route::post('/libro-iva-ventas/import/preview', [LibroIvaVentasImportController::class, 'preview'])
            ->name('erp.livv.preview');
        Route::post('/libro-iva-ventas/import/confirmar', [LibroIvaVentasImportController::class, 'confirmar'])
            ->name('erp.livv.confirmar');
        // v1.30 — modo "Control": comparar archivo AFIP vs sistema (no inserta).
        Route::post('/libro-iva-ventas/import/control', [LibroIvaVentasImportController::class, 'controlar'])
            ->name('erp.livv.control');
        Route::post('/libro-iva-ventas/import/control/importar-faltantes', [LibroIvaVentasImportController::class, 'importarFaltantes'])
            ->name('erp.livv.control.importar-faltantes');
        Route::get('/libro-iva-ventas/imports', [LibroIvaVentasImportController::class, 'imports'])
            ->name('erp.livv.imports');
        Route::get('/libro-iva-ventas/imports/{id}/errores.csv', [LibroIvaVentasImportController::class, 'descargarErrores'])
            ->whereNumber('id')->name('erp.livv.import-errores-csv');
        Route::get('/libro-iva-ventas/imports/{id}', [LibroIvaVentasImportController::class, 'importDetalle'])
            ->whereNumber('id')->name('erp.livv.import-detalle');
        Route::delete('/libro-iva-ventas/imports/{id}', [LibroIvaVentasImportController::class, 'destroy'])
            ->whereNumber('id')->name('erp.livv.import-destroy');

        // ADDENDUM v1.11 — Generador F.8001 Libro IVA Digital Compras
        Route::post('/libro-iva-compras/{periodoId}/exportar-f8001', [LibroIvaComprasExportController::class, 'exportar'])
            ->whereNumber('periodoId')->name('erp.livc.exportar');
        Route::get('/libro-iva-compras/exports', [LibroIvaComprasExportController::class, 'index'])
            ->name('erp.livc.exports');
        Route::get('/libro-iva-compras/exports/{id}', [LibroIvaComprasExportController::class, 'show'])
            ->whereNumber('id')->name('erp.livc.export-detalle');
        Route::get('/libro-iva-compras/exports/{id}/cbte', [LibroIvaComprasExportController::class, 'descargarCbte'])
            ->whereNumber('id')->name('erp.livc.export-cbte');
        Route::get('/libro-iva-compras/exports/{id}/alicuotas', [LibroIvaComprasExportController::class, 'descargarAlicuotas'])
            ->whereNumber('id')->name('erp.livc.export-alicuotas');
        Route::post('/libro-iva-compras/exports/{id}/marcar-enviado', [LibroIvaComprasExportController::class, 'marcarEnviado'])
            ->whereNumber('id')->name('erp.livc.export-enviado');
        Route::post('/libro-iva-compras/exports/{id}/comparar-liber', [LibroIvaComprasExportController::class, 'compararLiber'])
            ->whereNumber('id')->name('erp.livc.export-comparar');

        // Facturación (venta)
        // v1.27 — edición de período trabajado de facturas de venta.
        Route::get('/facturas-venta/periodos-trabajados', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'periodosTrabajadosDistinct'])
            ->name('erp.facturas-venta.periodos-trabajados');
        Route::patch('/facturas-venta/periodos-trabajados', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'patchPeriodosTrabajadosBulk'])
            ->name('erp.facturas-venta.periodos-trabajados.bulk');
        // Borrado masivo (excepto WSFE_ERP). Antes del {id} para no colisionar.
        Route::post('/facturas-venta/borrar-masivo', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'borrarMasivo'])
            ->name('erp.fv.borrar-masivo');
        // v1.29 — DELETE factura venta con permisos condicionales.
        Route::delete('/facturas-venta/{id}', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'destroy'])
            ->whereNumber('id')->name('erp.fv.destroy');
        // v1.29 — ABM permisos temporales (super_admin only).
        Route::get('/admin/permisos-temporales', [\App\Erp\Http\Controllers\PermisosTemporalesController::class, 'index'])
            ->name('erp.admin.permisos-temp.index');
        Route::post('/admin/permisos-temporales', [\App\Erp\Http\Controllers\PermisosTemporalesController::class, 'store'])
            ->name('erp.admin.permisos-temp.store');
        Route::delete('/admin/permisos-temporales/{id}', [\App\Erp\Http\Controllers\PermisosTemporalesController::class, 'destroy'])
            ->whereNumber('id')->name('erp.admin.permisos-temp.destroy');
        Route::patch('/facturas-venta/{id}/periodo-trabajado', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'patchPeriodoTrabajado'])
            ->whereNumber('id')->name('erp.facturas-venta.periodo-trabajado');
        // v1.37 — cambiar categoria FACTURA ⇄ EFECTIVO.
        Route::patch('/facturas-venta/{id}/categoria', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'patchCategoria'])
            ->whereNumber('id')->name('erp.facturas-venta.categoria');
        Route::patch('/facturas-compra/{id}/categoria', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'patchCategoria'])
            ->whereNumber('id')->name('erp.facturas-compra.categoria');
        // v1.51 — Reparto de base imponible IIBB por jurisdicción.
        Route::get('/facturas-venta/{id}/jurisdicciones', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'jurisdicciones'])
            ->whereNumber('id')->name('erp.facturas-venta.jurisdicciones');
        Route::put('/facturas-venta/{id}/jurisdicciones', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'putJurisdicciones'])
            ->whereNumber('id')->name('erp.facturas-venta.jurisdicciones.put');
        Route::get('/facturas-venta/catalogos', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'catalogosEmision'])
            ->name('erp.fv.catalogos');
        Route::get('/facturas-venta', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'index'])
            ->name('erp.fv.index');
        // v1.27 Sprint D §14 — export XLSX (espejo v1.49 compras).
        Route::get('/facturas-venta/export.xlsx', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'exportXlsx'])
            ->name('erp.fv.export-xlsx');
        Route::post('/facturas-venta/emitir', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'emitir'])
            ->name('erp.fv.emitir');
        // v1.17 — Carga manual (sin emitir contra ARCA).
        Route::post('/facturas-venta/manual', [FacturasManualController::class, 'ventaStore'])
            ->name('erp.fv.manual');
        Route::post('/facturas-compra/manual', [FacturasManualController::class, 'compraStore'])
            ->name('erp.fc.manual');
        // v1.39 — descarga del PDF adjunto (AFIP original).
        Route::get('/facturas-venta/{id}/pdf', [FacturasManualController::class, 'descargarPdfVenta'])
            ->whereNumber('id')->name('erp.fv.pdf');
        // v1.41 — extracción de datos desde PDF AFIP (autofill del form).
        Route::post('/facturas-venta/pdf-extract', [FacturasManualController::class, 'extraerDesdePdfVenta'])
            ->name('erp.fv.pdf-extract');
        // v1.17 — Verificación opcional contra ARCA (WSCDC + padrón).
        Route::post('/facturas/{tipo}/{id}/verificar-arca', [FacturasManualController::class, 'verificarArca'])
            ->whereIn('tipo', ['venta', 'compra'])
            ->whereNumber('id')
            ->name('erp.facturas.verificar-arca');
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

        // ADDENDUM v1.14 — Reportes por CC / Jurisdicción
        Route::get('/reportes/ventas-por-cliente', [ReportesV14Controller::class, 'ventasPorCliente'])
            ->name('erp.reportes.ventas-por-cliente');
        Route::get('/reportes/gastos-por-cliente', [ReportesV14Controller::class, 'gastosPorCliente'])
            ->name('erp.reportes.gastos-por-cliente');
        Route::get('/reportes/margen-por-cliente', [ReportesV14Controller::class, 'margenPorCliente'])
            ->name('erp.reportes.margen-por-cliente');
        Route::get('/reportes/ventas-por-jurisdiccion', [ReportesV14Controller::class, 'ventasPorJurisdiccion'])
            ->name('erp.reportes.ventas-por-jurisdiccion');
        Route::get('/reportes/gastos-por-jurisdiccion', [ReportesV14Controller::class, 'gastosPorJurisdiccion'])
            ->name('erp.reportes.gastos-por-jurisdiccion');

        // v1.37 — Reporte de saldos consolidados (deudores ventas + deuda compras
        // + aging + top deudores/acreedores + drill-down por auxiliar).
        Route::get('/reportes/saldos-consolidados',
            [\App\Erp\Http\Controllers\Reportes\SaldosConsolidadosController::class, 'index'])
            ->name('erp.reportes.saldos-consolidados');
        Route::get('/reportes/saldos-consolidados/auxiliar/{id}',
            [\App\Erp\Http\Controllers\Reportes\SaldosConsolidadosController::class, 'auxiliar'])
            ->whereNumber('id')->name('erp.reportes.saldos-consolidados.auxiliar');
        // v1.37 Fase 2.4 — exports XLSX y PDF.
        Route::get('/reportes/saldos-consolidados/export/xlsx',
            [\App\Erp\Http\Controllers\Reportes\SaldosConsolidadosController::class, 'exportXlsx'])
            ->name('erp.reportes.saldos-consolidados.xlsx');
        Route::get('/reportes/saldos-consolidados/export/pdf',
            [\App\Erp\Http\Controllers\Reportes\SaldosConsolidadosController::class, 'exportPdf'])
            ->name('erp.reportes.saldos-consolidados.pdf');

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
        Route::get('/arca/estado', [ArcaController::class, 'estado'])->name('erp.arca.estado');
        Route::post('/arca/puntos-venta/sincronizar', [ArcaController::class, 'puntosVentaAfip'])
            ->name('erp.arca.puntos-venta.sincronizar');

        // v1.44 — Control facturas (PDF → WSCDC + APOC) — módulo paralelo de control.
        Route::post('/control-facturas/extraer', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'extraer'])
            ->name('erp.control-facturas.extraer');
        Route::post('/control-facturas/validar', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'validar'])
            ->name('erp.control-facturas.validar');
        Route::get('/control-facturas/alertas', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'alertas'])
            ->name('erp.control-facturas.alertas');
        Route::patch('/control-facturas/alertas/{id}/leer', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'marcarAlertaLeida'])
            ->whereNumber('id')->name('erp.control-facturas.alertas.leer');
        Route::get('/control-facturas', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'index'])
            ->name('erp.control-facturas.index');
        Route::get('/control-facturas/{id}', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'show'])
            ->whereNumber('id')->name('erp.control-facturas.show');
        Route::patch('/control-facturas/{id}/seguimiento', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'actualizarSeguimiento'])
            ->whereNumber('id')->name('erp.control-facturas.seguimiento');
        Route::delete('/control-facturas/{id}', [\App\Erp\Http\Controllers\ControlFacturasController::class, 'destroy'])
            ->whereNumber('id')->name('erp.control-facturas.destroy');
        Route::get('/mis-comprobantes/runs', [ArcaController::class, 'misComprobantesRuns'])
            ->name('erp.arca.mc.runs');
        Route::get('/puntos-venta/afip', [ArcaController::class, 'puntosVentaAfip'])
            ->name('erp.arca.pv.sync');
        Route::get('/facturas-venta/{id}', [\App\Erp\Http\Controllers\FacturasVentaController::class, 'show'])
            ->whereNumber('id')->name('erp.fv.show');

        // Facturación (compra) — SPEC 03 §6.3
        // v1.22 §13 — borrado masivo de facturas de compra (super_admin).
        Route::post('/facturas-compra/borrar-masivo', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'borrarMasivo'])
            ->name('erp.facturas-compra.borrar-masivo');
        // v1.27 — edición de período trabajado (distinct + individual + bulk).
        Route::get('/facturas-compra/periodos-trabajados', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'periodosTrabajadosDistinct'])
            ->name('erp.facturas-compra.periodos-trabajados');
        Route::patch('/facturas-compra/periodos-trabajados', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'patchPeriodosTrabajadosBulk'])
            ->name('erp.facturas-compra.periodos-trabajados.bulk');
        // v1.40 — PATCH OP + fecha de pago (referencial, ambos opcionales).
        Route::patch('/facturas-compra/{id}/pago-info', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'patchPagoInfo'])
            ->whereNumber('id')->name('erp.fc.pago-info');
        Route::patch('/facturas-compra/{id}/periodo-trabajado', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'patchPeriodoTrabajado'])
            ->whereNumber('id')->name('erp.facturas-compra.periodo-trabajado');
        Route::get('/facturas-compra', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'index'])
            ->name('erp.fc.index');
        // v1.49 — export XLSX del listado con los mismos filtros aplicados.
        Route::get('/facturas-compra/export.xlsx', [\App\Erp\Http\Controllers\FacturasCompraController::class, 'exportXlsx'])
            ->name('erp.fc.export-xlsx');
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

        // ====================================================================
        // SPEC 08 8B — Sueldos: catálogos + padrón empleados + básicos +
        //   composición + comisiones (CRUD).
        // ====================================================================
        Route::prefix('sueldos')->group(function () {
            // Catálogos
            Route::get('/convenios',          [\App\Erp\Http\Controllers\Sueldos\CatalogosSueldosController::class, 'convenios'])
                ->name('erp.sueldos.convenios');
            Route::get('/categorias',         [\App\Erp\Http\Controllers\Sueldos\CatalogosSueldosController::class, 'categorias'])
                ->name('erp.sueldos.categorias.index');
            Route::post('/categorias',        [\App\Erp\Http\Controllers\Sueldos\CatalogosSueldosController::class, 'categoriaStore'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.categorias.store');
            Route::put('/categorias/{id}',    [\App\Erp\Http\Controllers\Sueldos\CatalogosSueldosController::class, 'categoriaUpdate'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.categorias.update');
            Route::get('/conceptos',          [\App\Erp\Http\Controllers\Sueldos\CatalogosSueldosController::class, 'conceptos'])
                ->name('erp.sueldos.conceptos.index');
            Route::put('/conceptos/{id}',     [\App\Erp\Http\Controllers\Sueldos\CatalogosSueldosController::class, 'conceptoUpdate'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.conceptos.update');

            // Empleados (padrón)
            Route::get('/empleados',          [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'index'])
                ->name('erp.sueldos.empleados.index');
            Route::post('/empleados',         [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.empleados.store');
            Route::get('/empleados/{id}',     [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'show'])
                ->whereNumber('id')->name('erp.sueldos.empleados.show');
            Route::put('/empleados/{id}',     [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'update'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.empleados.update');

            // Básicos historizados (RN-103: sin overlap)
            Route::get('/empleados/{id}/basicos',  [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'basicosListar'])
                ->whereNumber('id')->name('erp.sueldos.empleados.basicos.index');
            Route::post('/empleados/{id}/basicos', [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'basicoStore'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.empleados.basicos.store');

            // Composición porcentual (RN-102: suma 100)
            Route::get('/empleados/{id}/composiciones',  [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'composicionesListar'])
                ->whereNumber('id')->name('erp.sueldos.empleados.compos.index');
            Route::post('/empleados/{id}/composiciones', [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'composicionStore'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.empleados.compos.store');

            // Esquemas de comisión
            Route::get('/empleados/{id}/comisiones',  [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'comisionesListar'])
                ->whereNumber('id')->name('erp.sueldos.empleados.coms.index');
            Route::post('/empleados/{id}/comisiones', [\App\Erp\Http\Controllers\Sueldos\EmpleadosController::class, 'comisionStore'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.empleados.coms.store');

            // ---- 8C: Novedades / Ausencias / CC / Préstamos -----------------
            // Novedades del mes
            Route::get('/novedades',          [\App\Erp\Http\Controllers\Sueldos\NovedadesController::class, 'index'])
                ->name('erp.sueldos.novedades.index');
            Route::post('/novedades',         [\App\Erp\Http\Controllers\Sueldos\NovedadesController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.novedades.store');
            Route::post('/novedades/bulk',    [\App\Erp\Http\Controllers\Sueldos\NovedadesController::class, 'bulk'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.novedades.bulk');
            Route::delete('/novedades/{id}',  [\App\Erp\Http\Controllers\Sueldos\NovedadesController::class, 'destroy'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.novedades.destroy');

            // Ausencias
            Route::get('/ausencias',          [\App\Erp\Http\Controllers\Sueldos\AusenciasController::class, 'index'])
                ->name('erp.sueldos.ausencias.index');
            Route::post('/ausencias',         [\App\Erp\Http\Controllers\Sueldos\AusenciasController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.ausencias.store');
            Route::put('/ausencias/{id}',     [\App\Erp\Http\Controllers\Sueldos\AusenciasController::class, 'update'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.ausencias.update');
            Route::delete('/ausencias/{id}',  [\App\Erp\Http\Controllers\Sueldos\AusenciasController::class, 'destroy'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.ausencias.destroy');

            // CC empleado + movimientos
            Route::get('/cc',                       [\App\Erp\Http\Controllers\Sueldos\CCController::class, 'index'])
                ->name('erp.sueldos.cc.index');
            Route::post('/cc',                      [\App\Erp\Http\Controllers\Sueldos\CCController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.cc.store');
            Route::get('/cc/{id}/movimientos',      [\App\Erp\Http\Controllers\Sueldos\CCController::class, 'movimientos'])
                ->whereNumber('id')->name('erp.sueldos.cc.movs.index');
            Route::post('/cc/{id}/movimientos',     [\App\Erp\Http\Controllers\Sueldos\CCController::class, 'movimientoStore'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.cc.movs.store');

            // Préstamos
            Route::get('/prestamos',          [\App\Erp\Http\Controllers\Sueldos\PrestamosController::class, 'index'])
                ->name('erp.sueldos.prestamos.index');
            Route::post('/prestamos',         [\App\Erp\Http\Controllers\Sueldos\PrestamosController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.prestamos.store');
            Route::get('/prestamos/{id}',     [\App\Erp\Http\Controllers\Sueldos\PrestamosController::class, 'show'])
                ->whereNumber('id')->name('erp.sueldos.prestamos.show');

            // ---- 8D: Liquidaciones (cabecera + máquina de estados) -----------
            Route::get('/liquidaciones',                          [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'index'])
                ->name('erp.sueldos.liquidaciones.index');
            Route::post('/liquidaciones',                         [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'store'])
                ->middleware('erp.mfa.fresh')->name('erp.sueldos.liquidaciones.store');
            Route::get('/liquidaciones/{id}',                     [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'show'])
                ->whereNumber('id')->name('erp.sueldos.liquidaciones.show');
            Route::post('/liquidaciones/{id}/calcular',           [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'calcular'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liquidaciones.calcular');
            Route::post('/liquidaciones/{id}/aprobar',            [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'aprobar'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liquidaciones.aprobar');
            Route::post('/liquidaciones/{id}/anular',             [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'anular'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liquidaciones.anular');
            Route::post('/liquidaciones/{id}/rectificar',         [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'rectificar'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liquidaciones.rectificar');
            Route::get('/liquidaciones/{id}/items',               [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'items'])
                ->whereNumber('id')->name('erp.sueldos.liquidaciones.items');
            Route::get('/liquidaciones/{id}/recibo/{empleadoId}', [\App\Erp\Http\Controllers\Sueldos\LiquidacionesController::class, 'recibo'])
                ->whereNumber('id')->whereNumber('empleadoId')->name('erp.sueldos.liquidaciones.recibo');

            // ---- 8E: Pagos en 3 modalidades + asientos automáticos -----------
            Route::post('/liquidaciones/{id}/contabilizar',  [\App\Erp\Http\Controllers\Sueldos\PagosController::class, 'contabilizar'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liq.contabilizar');
            Route::post('/liquidaciones/{id}/pagar/formal',  [\App\Erp\Http\Controllers\Sueldos\PagosController::class, 'pagarFormal'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liq.pagar.formal');
            Route::post('/liquidaciones/{id}/pagar/efectivo',[\App\Erp\Http\Controllers\Sueldos\PagosController::class, 'pagarEfectivo'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liq.pagar.efectivo');
            Route::post('/liquidaciones/{id}/pagar/mt',      [\App\Erp\Http\Controllers\Sueldos\PagosController::class, 'pagarMt'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liq.pagar.mt');
            Route::get('/liquidaciones/{id}/pagos',          [\App\Erp\Http\Controllers\Sueldos\PagosController::class, 'listarPorLiquidacion'])
                ->whereNumber('id')->name('erp.sueldos.liq.pagos');
            Route::get('/pagos/{id}',                        [\App\Erp\Http\Controllers\Sueldos\PagosController::class, 'show'])
                ->whereNumber('id')->name('erp.sueldos.pagos.show');

            // ---- 8F: Export LIBER + reportes -------------------------------
            Route::post('/liquidaciones/{id}/export-liber',  [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'generarLiber'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liber.generar');
            Route::get('/exports-liber',                     [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'listarLiber'])
                ->name('erp.sueldos.liber.index');
            Route::get('/exports-liber/{id}',                [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'showLiber'])
                ->whereNumber('id')->name('erp.sueldos.liber.show');
            Route::get('/exports-liber/{id}/descargar',      [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'descargarLiber'])
                ->whereNumber('id')->name('erp.sueldos.liber.descargar');
            Route::post('/exports-liber/{id}/marcar-enviado', [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'marcarEnviadoLiber'])
                ->whereNumber('id')->middleware('erp.mfa.fresh')->name('erp.sueldos.liber.enviado');

            Route::get('/reportes/liquidacion/{id}',          [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'liquidacionResumen'])
                ->whereNumber('id')->name('erp.sueldos.rep.liquidacion');
            Route::get('/reportes/empleado/{id}/historico',   [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'empleadoHistorico'])
                ->whereNumber('id')->name('erp.sueldos.rep.historico');
            Route::get('/reportes/costo-laboral',             [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'costoLaboral'])
                ->name('erp.sueldos.rep.costo');
            Route::get('/reportes/empleado/{id}/cc',          [\App\Erp\Http\Controllers\Sueldos\ReportesSueldosController::class, 'ccEmpleado'])
                ->whereNumber('id')->name('erp.sueldos.rep.cc');
        });

        // ====================================================================
        // Anexo Cierres Diarios — workflow + ajuste retroactivo + exports.
        // ====================================================================
        Route::prefix('cierres-diarios')->group(function () {
            Route::get('/',                              [\App\Erp\Http\Controllers\CierresDiariosController::class, 'index'])
                ->name('erp.cierres.dia.index');
            Route::get('/{fecha}',                       [\App\Erp\Http\Controllers\CierresDiariosController::class, 'show'])
                ->name('erp.cierres.dia.show');
            Route::post('/{fecha}/iniciar',              [\App\Erp\Http\Controllers\CierresDiariosController::class, 'iniciar'])
                ->middleware('erp.mfa.fresh')->name('erp.cierres.dia.iniciar');
            Route::post('/{fecha}/sellar',               [\App\Erp\Http\Controllers\CierresDiariosController::class, 'sellar'])
                ->middleware('erp.mfa.fresh')->name('erp.cierres.dia.sellar');
            Route::post('/{fecha}/ajuste-retroactivo',   [\App\Erp\Http\Controllers\CierresDiariosController::class, 'ajusteRetroactivo'])
                ->middleware('erp.mfa.fresh')->name('erp.cierres.dia.ajuste');
            Route::get('/{fecha}/exportar-liber',        [\App\Erp\Http\Controllers\CierresDiariosController::class, 'exportarLiber'])
                ->name('erp.cierres.dia.export.liber');
            Route::get('/{fecha}/exportar-pdf',          [\App\Erp\Http\Controllers\CierresDiariosController::class, 'exportarPdf'])
                ->name('erp.cierres.dia.export.pdf');
        });
    });
});

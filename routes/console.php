<?php

use App\Erp\Jobs\EmitirFacturaJob;
use App\Erp\Jobs\FceAceptacionTacitaJob;
use App\Erp\Jobs\ImportarMisComprobantesDiario;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// SPEC 03 F6 — schedulers

// RN-43: scraper Mis Comprobantes del día anterior, 02:00 AR.
Schedule::job(new ImportarMisComprobantesDiario())
    ->dailyAt('02:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->name('mis-comprobantes-diario')
    ->onOneServer();

// RN-36: aceptación tácita de FCE (cada día al mediodía).
Schedule::job(new FceAceptacionTacitaJob())
    ->dailyAt('12:00')
    ->timezone('America/Argentina/Buenos_Aires')
    ->name('fce-aceptacion-tacita')
    ->onOneServer();

// RN-39: drenar outbox de emisión cada 2 min (cola de facturas pendientes).
Schedule::job(new EmitirFacturaJob())
    ->everyTwoMinutes()
    ->name('emision-outbox-drain')
    ->withoutOverlapping();

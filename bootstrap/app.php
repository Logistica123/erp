<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::middleware('api')
                ->group(base_path('routes/erp.php'));
        },
    )
    ->withCommands([
        \App\Erp\Console\Commands\RebuildSaldos::class,
    ])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'erp.auth' => \App\Erp\Http\Middleware\ErpAuth::class,
            'erp.mfa.fresh' => \App\Erp\Http\Middleware\ErpRequireMfaFresh::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

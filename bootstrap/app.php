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
        // API-only app: unauthenticated debe devolver JSON 401, no redirect a
        // route('login') (no existe). Laravel 13 arroja AuthenticationException
        // que por default intenta redirect y termina en 500 si no hay login web.
        $exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'error' => ['code' => 'NO_AUTH', 'message' => 'No autenticado.'],
                ], 401);
            }
        });
    })->create();

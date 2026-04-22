<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// API-only app: no hay login web. Laravel intenta route('login') al
// rechazar un request sin auth, lo que termina en 500 si la ruta no
// existe. Definirla acá como stub JSON resuelve la cadena y devuelve
// 401 con el envelope estándar del ERP.
Route::match(['GET', 'POST'], '/login', function () {
    return response()->json([
        'ok' => false,
        'error' => ['code' => 'NO_AUTH', 'message' => 'Autenticación requerida.'],
    ], 401);
})->name('login');

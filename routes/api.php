<?php

use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\PosSettingsController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\StoreController;
use Illuminate\Support\Facades\Route;

/*
 * The endpoints of docs/API.md (frontend repo), implemented slice by slice.
 * Session state rides on the cookie the api middleware group carries — see
 * bootstrap/app.php for the group's composition.
 */

/* ---- identity ---- */

Route::get('/session', [SessionController::class, 'show']);
Route::post('/session', [SessionController::class, 'store']);
Route::delete('/session', [SessionController::class, 'destroy']);
Route::post('/password-resets', [PasswordResetController::class, 'store']);

/* ---- signed-in area ---- */

Route::middleware('auth:web')->group(function () {
    Route::get('/stores', [StoreController::class, 'index']);

    /* ---- owner only ---- */

    Route::middleware('owner')->group(function () {
        Route::get('/settings/pos', [PosSettingsController::class, 'show']);
        Route::post('/settings/pos/reconnect', [PosSettingsController::class, 'reconnect']);
    });
});

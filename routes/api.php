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

/* Asking for a link sends mail to an address the caller names, so it carries
   a tighter limit than the group's. Redeeming one does not: the token is 60
   random characters, guessing is not the threat, and a manager fumbling a new
   password twice must not be locked out of their own reset. */
Route::post('/password-resets', [PasswordResetController::class, 'store'])
    ->middleware('throttle:password-resets');
Route::post('/password-resets/redeem', [PasswordResetController::class, 'redeem']);

/* ---- signed-in area ---- */

Route::middleware('auth:web')->group(function () {
    Route::get('/stores', [StoreController::class, 'index']);

    /* ---- owner only ---- */

    Route::middleware('owner')->group(function () {
        Route::get('/settings/pos', [PosSettingsController::class, 'show']);
        Route::post('/settings/pos/reconnect', [PosSettingsController::class, 'reconnect']);
    });
});

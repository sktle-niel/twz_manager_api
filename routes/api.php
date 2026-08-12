<?php

use App\Http\Controllers\Api\AccountPasswordController;
use App\Http\Controllers\Api\AdvanceController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\CatalogController;
use App\Http\Controllers\Api\DepositController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\ManagerController;
use App\Http\Controllers\Api\ManagerPasswordController;
use App\Http\Controllers\Api\PosSettingsController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\ReconciliationController;
use App\Http\Controllers\Api\ResetPinController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\SalesFilterController;
use App\Http\Controllers\Api\SessionController;
use App\Http\Controllers\Api\SignInLogController;
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

/* ---- signed-in area ---- */

Route::middleware('auth:web')->group(function () {
    Route::get('/stores', [StoreController::class, 'index']);

    /* Served from the local receipts table; a manager may only ask for
       their own branch — the controller enforces the scope */
    Route::get('/sales/daily', [SalesController::class, 'daily']);
    Route::get('/sales/hourly', [SalesController::class, 'hourly']);

    /* The audit spine: what was taken, spent, and deposited, day by day */
    Route::get('/audits', [AuditController::class, 'index']);
    Route::get('/deposits/pending', [DepositController::class, 'pending']);
    Route::get('/deposits', [DepositController::class, 'index']);
    Route::post('/deposits', [DepositController::class, 'store']);

    Route::get('/expenses', [ExpenseController::class, 'index']);
    Route::post('/expenses', [ExpenseController::class, 'store']);
    Route::patch('/expenses/{expense}', [ExpenseController::class, 'update']);
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy']);
    Route::get('/expense-categories', [ExpenseCategoryController::class, 'index']);

    /* Cash advances: drawer money an employee drew against their pay. The
       ledger nets them from the day's expected deposit; same closed-day rule */
    Route::get('/advances', [AdvanceController::class, 'index']);
    Route::post('/advances', [AdvanceController::class, 'store']);
    Route::patch('/advances/{advance}', [AdvanceController::class, 'update']);
    Route::delete('/advances/{advance}', [AdvanceController::class, 'destroy']);

    Route::get('/accounts/{accountId}/sign-ins', [SignInLogController::class, 'index']);

    /* Web-push reminders: the key to subscribe with, and this browser's
       mailbox — registered to whoever is signed in, forgotten on request */
    Route::get('/push/key', [PushController::class, 'key']);
    Route::post('/push/subscriptions', [PushController::class, 'store']);
    Route::delete('/push/subscriptions', [PushController::class, 'destroy']);

    /* Both roles read the window (the status card counts against it);
       only the owner turns the dial, below */
    Route::get('/settings/reconciliation', [ReconciliationController::class, 'show']);

    /* Stored photos: same origin, same session cookie, nothing public */
    Route::get('/files/{path}', [FileController::class, 'show'])->where('path', '.*');

    /* Both roles change their own password here; the current one is the proof */
    Route::put('/account/password', [AccountPasswordController::class, 'update']);

    /* ---- owner only ---- */

    Route::middleware('owner')->group(function () {
        Route::get('/settings/pos', [PosSettingsController::class, 'show']);
        Route::post('/settings/pos/reconnect', [PosSettingsController::class, 'reconnect']);

        /* What never counts toward gross and profit (services & labor), and
           the catalog search that picks those items out */
        Route::get('/settings/sales-filter', [SalesFilterController::class, 'show']);
        Route::put('/settings/sales-filter', [SalesFilterController::class, 'update']);
        Route::get('/catalog/search', [CatalogController::class, 'search']);

        Route::put('/expense-categories', [ExpenseCategoryController::class, 'replace']);
        Route::patch('/settings/reconciliation', [ReconciliationController::class, 'update']);

        /* Manager accounts are made HERE by the owner — never synced from
           Loyverse. One branch, one manager; occupied branches swap. */
        Route::get('/managers', [ManagerController::class, 'index']);
        Route::post('/managers', [ManagerController::class, 'store']);
        Route::patch('/managers/{manager}/branch', [ManagerController::class, 'assign']);

        /* Recovery lives here now: a manager who is locked out asks the owner,
           and the owner sets a new password behind the PIN. Nothing is mailed
           anywhere, so nothing outside this door can start a reset. */
        Route::get('/settings/reset-pin', [ResetPinController::class, 'show']);
        Route::put('/settings/reset-pin', [ResetPinController::class, 'update']);
        Route::put('/managers/{manager}/password', [ManagerPasswordController::class, 'update']);
    });
});

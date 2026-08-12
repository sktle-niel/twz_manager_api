<?php

use App\Http\Controllers\SpaController;
use Illuminate\Support\Facades\Route;

/*
 * The whole web side is the PWA: the staged build answers every path the API
 * does not, and the SPA router takes over in the browser. Real files under
 * public/ (assets, icons, sw.js) never reach the router at all.
 */
Route::get('/', SpaController::class);
Route::fallback(SpaController::class);

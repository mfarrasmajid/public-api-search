<?php

use App\Http\Controllers\Api\ApiCatalogController;
use App\Http\Controllers\Api\MetaController;
use App\Http\Controllers\Api\SearchController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API (v1 lives at the root for the POC)
|--------------------------------------------------------------------------
| Contract is documented in docs/api-contract.md - update both together.
*/

Route::get('/search', SearchController::class)->name('search');

Route::get('/apis', [ApiCatalogController::class, 'index'])->name('apis.index');
Route::get('/apis/{api}', [ApiCatalogController::class, 'show'])->name('apis.show');

Route::get('/meta', [MetaController::class, 'filters'])->name('meta');
Route::get('/health', [MetaController::class, 'health'])->name('health');

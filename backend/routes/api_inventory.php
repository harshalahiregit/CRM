<?php

use App\Http\Controllers\Api\Inventory\CategoryController;
use App\Http\Controllers\Api\Inventory\ProductController;
use App\Http\Controllers\Api\Inventory\SettingsController;
use App\Http\Controllers\Api\Inventory\StockController;
use App\Http\Controllers\Api\Inventory\VoucherController;
use App\Http\Controllers\Api\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

// ── Inventory OS (owner: Shivam, Sanctum) ───────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('inventory')->group(function () {

    // Dashboard / alerts
    Route::get('/summary',    [StockController::class, 'summary']);
    Route::get('/low-stock',  [StockController::class, 'lowStock']);

    // Settings master data (§10). `/settings` returns every section at once for
    // the Item form; the {kind} routes drive the Settings screen's tabs.
    Route::get('/settings',                  [SettingsController::class, 'all']);
    Route::get('/settings/groups/{group}/subgroups', [SettingsController::class, 'subgroups']);
    Route::get('/settings/{kind}',           [SettingsController::class, 'index']);
    Route::post('/settings/{kind}',          [SettingsController::class, 'store']);
    Route::patch('/settings/{kind}/reorder', [SettingsController::class, 'reorder']);
    Route::put('/settings/{kind}/{id}',      [SettingsController::class, 'update'])->where('id', '[0-9]+');
    Route::delete('/settings/{kind}/{id}',   [SettingsController::class, 'destroy'])->where('id', '[0-9]+');

    // Categories — declared BEFORE /products/{product} isn't needed (different
    // prefix), but kept above for readability.
    Route::get('/categories',              [CategoryController::class, 'index']);
    Route::post('/categories',             [CategoryController::class, 'store']);
    Route::put('/categories/{category}',   [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Stock operations
    Route::post('/stock/move',   [StockController::class, 'move']);
    Route::post('/stock/adjust', [StockController::class, 'adjust']);

    // Voucher documents (§3–§6). {type} = receipt | delivery | internal | loss_adjustment.
    // Posting is what writes to the ledger; cancelling a posted one reverses it.
    Route::get('/vouchers/{type}',             [VoucherController::class, 'index']);
    Route::post('/vouchers/{type}',            [VoucherController::class, 'store']);
    Route::get('/vouchers/{type}/{id}',        [VoucherController::class, 'show'])->where('id', '[0-9]+');
    Route::put('/vouchers/{type}/{id}',        [VoucherController::class, 'update'])->where('id', '[0-9]+');
    Route::delete('/vouchers/{type}/{id}',     [VoucherController::class, 'destroy'])->where('id', '[0-9]+');
    Route::post('/vouchers/{type}/{id}/post',  [VoucherController::class, 'post'])->where('id', '[0-9]+');
    Route::post('/vouchers/{type}/{id}/cancel', [VoucherController::class, 'cancel'])->where('id', '[0-9]+');

    // Products — static segments BEFORE /{product} so they aren't captured as an id.
    Route::get('/products/lookup', [ProductController::class, 'lookup']);
    Route::get('/products',        [ProductController::class, 'index']);
    Route::post('/products',       [ProductController::class, 'store']);
    Route::get('/products/{product}',    [ProductController::class, 'show']);
    Route::put('/products/{product}',    [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::get('/products/{product}/levels',  [StockController::class, 'levels']);
    Route::get('/products/{product}/history', [StockController::class, 'history']);

    // Warehouses + bin locations
    Route::get('/warehouses',        [WarehouseController::class, 'index']);
    Route::post('/warehouses',       [WarehouseController::class, 'store']);
    Route::get('/warehouses/{warehouse}',    [WarehouseController::class, 'show']);
    Route::put('/warehouses/{warehouse}',    [WarehouseController::class, 'update']);
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
    Route::get('/warehouses/{warehouse}/locations',  [WarehouseController::class, 'locations']);
    Route::post('/warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
    Route::delete('/warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'destroyLocation']);
});

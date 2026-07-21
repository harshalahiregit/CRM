<?php

use App\Http\Controllers\Api\Inventory\AnalyticsController;
use App\Http\Controllers\Api\Inventory\CategoryController;
use App\Http\Controllers\Api\Inventory\ConfigController;
use App\Http\Controllers\Api\Inventory\InventoryStaffController;
use App\Http\Controllers\Api\Inventory\ProductController;
use App\Http\Controllers\Api\Inventory\ReportController;
use App\Http\Controllers\Api\Inventory\SettingsController;
use App\Http\Controllers\Api\Inventory\StockController;
use App\Http\Controllers\Api\Inventory\TraceabilityController;
use App\Http\Controllers\Api\Inventory\VoucherController;
use App\Http\Controllers\Api\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

// ── Inventory OS (owner: Shivam, Sanctum) ───────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
Route::middleware('auth:sanctum')->prefix('inventory')->group(function () {

    // Dashboard / alerts
    Route::get('/summary',    [StockController::class, 'summary']);
    Route::get('/low-stock',  [StockController::class, 'lowStock']);

    // Inventory history (§7) — the whole-module audit ledger.
    Route::get('/history',    [StockController::class, 'ledger']);

    // Reports (§8) — summary | valuation | analysis.
    Route::get('/reports/{kind}', [ReportController::class, 'show']);

    // Analytics — ABC/XYZ, turnover, dead stock, accuracy. Viewer-aware.
    Route::get('/analytics',  [AnalyticsController::class, 'index']);

    // Traceability: batches, serials, reservations, expiry.
    Route::get('/batches',            [TraceabilityController::class, 'batches']);
    Route::post('/batches',           [TraceabilityController::class, 'storeBatch']);
    Route::put('/batches/{batch}',    [TraceabilityController::class, 'updateBatch']);
    Route::delete('/batches/{batch}', [TraceabilityController::class, 'destroyBatch']);
    Route::get('/batches-fefo',       [TraceabilityController::class, 'fefo']);
    Route::get('/expiry',             [TraceabilityController::class, 'expiry']);

    Route::get('/serials',              [TraceabilityController::class, 'serials']);
    Route::post('/serials',             [TraceabilityController::class, 'storeSerials']);
    Route::put('/serials/{serial}',     [TraceabilityController::class, 'updateSerial']);
    Route::delete('/serials/{serial}',  [TraceabilityController::class, 'destroySerial']);

    Route::get('/reservations',                         [TraceabilityController::class, 'reservations']);
    Route::post('/reservations',                        [TraceabilityController::class, 'reserve']);
    Route::post('/reservations/{reservation}/close',    [TraceabilityController::class, 'closeReservation']);

    // People, for the "Staff" filter on reports/history.
    Route::get('/staff',      [InventoryStaffController::class, 'index']);

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
    Route::post('/vouchers/{type}/{id}/send',   [VoucherController::class, 'send'])->where('id', '[0-9]+');

    // Products — static segments BEFORE /{product} so they aren't captured as an id.
    // Config + custom fields + reset (§9 tabs that aren't lookup lists).
    Route::get('/config',              [ConfigController::class, 'index']);
    Route::put('/config',              [ConfigController::class, 'update']);
    Route::get('/custom-fields',       [ConfigController::class, 'fields']);
    Route::post('/custom-fields',      [ConfigController::class, 'storeField']);
    Route::put('/custom-fields/{field}',    [ConfigController::class, 'updateField']);
    Route::delete('/custom-fields/{field}', [ConfigController::class, 'destroyField']);
    Route::post('/reset',              [ConfigController::class, 'reset']);

    // Items: bulk actions, tag vocabulary, imports. These sit BEFORE
    // /products/{product} so "bulk" and "tags" aren't read as an id.
    Route::get('/products/tags',   [ProductController::class, 'tags']);
    Route::post('/products/bulk',  [ProductController::class, 'bulk']);
    Route::get('/products/import/{kind}/template', [ProductController::class, 'importTemplate']);
    Route::post('/products/import/{kind}',         [ProductController::class, 'import']);
    Route::get('/products/lookup', [ProductController::class, 'lookup']);
    Route::get('/products',        [ProductController::class, 'index']);
    Route::post('/products',       [ProductController::class, 'store']);
    Route::get('/products/{product}',    [ProductController::class, 'show']);
    Route::put('/products/{product}',    [ProductController::class, 'update']);
    Route::delete('/products/{product}', [ProductController::class, 'destroy']);
    Route::post('/products/{product}/image',   [ProductController::class, 'uploadImage']);
    Route::get('/products/{product}/image',    [ProductController::class, 'image']);
    Route::delete('/products/{product}/image', [ProductController::class, 'deleteImage']);
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

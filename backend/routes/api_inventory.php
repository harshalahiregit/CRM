<?php

use App\Http\Controllers\Api\Inventory\AnalyticsController;
use App\Http\Controllers\Api\Inventory\CategoryController;
use App\Http\Controllers\Api\Inventory\ConfigController;
use App\Http\Controllers\Api\Inventory\CountController;
use App\Http\Controllers\Api\Inventory\InventoryStaffController;
use App\Http\Controllers\Api\Inventory\ProductController;
use App\Http\Controllers\Api\Inventory\ReportController;
use App\Http\Controllers\Api\Inventory\SettingsController;
use App\Http\Controllers\Api\Inventory\FulfilmentController;
use App\Http\Controllers\Api\Inventory\ScanController;
use App\Http\Controllers\Api\Inventory\StockController;
use App\Http\Controllers\Api\Inventory\TraceabilityController;
use App\Http\Controllers\Api\Inventory\TransferController;
use App\Http\Controllers\Api\Inventory\VoucherController;
use App\Http\Controllers\Api\Inventory\VoucherFileController;
use App\Http\Controllers\Api\Inventory\WarehouseController;
use Illuminate\Support\Facades\Route;

// ── Inventory OS (owner: Shivam, Sanctum) ───────────────────────────────
// Isolated route file. Registered once from routes/api.php via a single require.
//
// `role:admin,staff` bars the portal roles from the whole module. Every
// controller here already calls denyExternal() by hand; the group guard is the
// structural backstop so a new inventory endpoint can never leak stock data to a
// client or vendor just because someone forgot the per-method call.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('inventory')->group(function () {

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
    Route::post('/batches/{batch}/recall',      [TraceabilityController::class, 'recallBatch']);
    Route::post('/batches/{batch}/lift-recall', [TraceabilityController::class, 'liftRecall']);
    Route::get('/batches-fefo',       [TraceabilityController::class, 'fefo']);
    Route::get('/expiry',             [TraceabilityController::class, 'expiry']);

    Route::get('/serials',              [TraceabilityController::class, 'serials']);
    Route::post('/serials',             [TraceabilityController::class, 'storeSerials']);
    Route::put('/serials/{serial}',     [TraceabilityController::class, 'updateSerial']);
    Route::delete('/serials/{serial}',  [TraceabilityController::class, 'destroySerial']);
    Route::get('/serials/{serial}/events',  [TraceabilityController::class, 'serialEvents']);
    Route::post('/serials/{serial}/events', [TraceabilityController::class, 'addSerialEvent']);

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

    // Pick, pack, ship (§11 / §14). Stock does not move on these routes —
    // shipping posts the delivery voucher, and that is what writes the movement.
    Route::get('/fulfilment/carriers',      [FulfilmentController::class, 'carriers']);
    Route::get('/fulfilment',               [FulfilmentController::class, 'index']);
    Route::post('/fulfilment',              [FulfilmentController::class, 'store']);
    Route::get('/fulfilment/{id}',          [FulfilmentController::class, 'show'])->where('id', '[0-9]+');
    Route::patch('/fulfilment/{id}/assign', [FulfilmentController::class, 'assign'])->where('id', '[0-9]+');
    Route::post('/fulfilment/{id}/pick',    [FulfilmentController::class, 'pick'])->where('id', '[0-9]+');
    Route::post('/fulfilment/{id}/picked',  [FulfilmentController::class, 'completePick'])->where('id', '[0-9]+');
    Route::post('/fulfilment/{id}/pack',    [FulfilmentController::class, 'pack'])->where('id', '[0-9]+');
    Route::post('/fulfilment/{id}/ship',    [FulfilmentController::class, 'ship'])->where('id', '[0-9]+');
    Route::post('/fulfilment/{id}/deliver', [FulfilmentController::class, 'deliver'])->where('id', '[0-9]+');
    Route::post('/fulfilment/{id}/cancel',  [FulfilmentController::class, 'cancel'])->where('id', '[0-9]+');

    // Cycle counting and physical verification (§12). Counting writes to the
    // count sheet only; APPROVAL is what posts the corrections to the ledger,
    // and never by the person who did the walking.
    Route::get('/counts',                    [CountController::class, 'index']);
    Route::post('/counts',                   [CountController::class, 'store']);
    Route::get('/counts/{id}',               [CountController::class, 'show'])->where('id', '[0-9]+');
    Route::patch('/counts/{id}/assign',      [CountController::class, 'assign'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/lines',        [CountController::class, 'addLine'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/count',        [CountController::class, 'count'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/recount',      [CountController::class, 'recount'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/submit',       [CountController::class, 'submit'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/approve',      [CountController::class, 'approve'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/reject',       [CountController::class, 'reject'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/cancel',       [CountController::class, 'cancel'])->where('id', '[0-9]+');
    Route::post('/counts/{id}/lines/{line}/photo', [CountController::class, 'uploadPhoto'])->where(['id' => '[0-9]+', 'line' => '[0-9]+']);
    Route::get('/counts/{id}/lines/{line}/photo',  [CountController::class, 'photo'])->where(['id' => '[0-9]+', 'line' => '[0-9]+']);

    // Transfers with a real in-transit state (§15). Dispatch moves stock into
    // the tenant's transit warehouse; receiving moves what actually arrived out
    // of it. Anything left over stays in transit until it is received late or
    // deliberately written off — it never quietly disappears.
    Route::get('/transfers',                 [TransferController::class, 'index']);
    Route::get('/transfers/reconcile',       [TransferController::class, 'reconcile']);
    Route::post('/transfers',                [TransferController::class, 'store']);
    Route::get('/transfers/{id}',            [TransferController::class, 'show'])->where('id', '[0-9]+');
    Route::post('/transfers/{id}/dispatch',  [TransferController::class, 'dispatchOut'])->where('id', '[0-9]+');
    Route::post('/transfers/{id}/receive',   [TransferController::class, 'receive'])->where('id', '[0-9]+');
    Route::post('/transfers/{id}/write-off', [TransferController::class, 'writeOff'])->where('id', '[0-9]+');
    Route::post('/transfers/{id}/cancel',    [TransferController::class, 'cancel'])->where('id', '[0-9]+');

    // One endpoint for the scanner: give it any code and it says what it is —
    // product, serial, batch, bin or document. See ScanService.
    Route::post('/scan', [ScanController::class, 'resolve']);

    // Everything waiting on this user's approval, across all four types. Must be
    // declared before /vouchers/{type} or "pending" is captured as a type.
    Route::get('/approvals/pending', [VoucherController::class, 'pending']);

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

    // Receiving inspection (§13). Recorded BEFORE posting, because posting a
    // receipt moves the ACCEPTED quantity, not whatever turned up on the lorry.
    Route::post('/vouchers/{type}/{id}/inspect',       [VoucherController::class, 'inspect'])->where('id', '[0-9]+');
    Route::post('/vouchers/{type}/{id}/vendor-return', [VoucherController::class, 'vendorReturn'])->where('id', '[0-9]+');

    // Paperwork that arrives with the goods — challan, test certificate,
    // inspection photo, POD. Private disk; downloads are authenticated.
    Route::get('/vouchers/{type}/{id}/files',                 [VoucherFileController::class, 'index'])->where('id', '[0-9]+');
    Route::post('/vouchers/{type}/{id}/files',                [VoucherFileController::class, 'store'])->where('id', '[0-9]+');
    Route::get('/vouchers/{type}/{id}/files/{file}/download', [VoucherFileController::class, 'download'])->where(['id' => '[0-9]+', 'file' => '[0-9]+']);
    Route::delete('/vouchers/{type}/{id}/files/{file}',       [VoucherFileController::class, 'destroy'])->where(['id' => '[0-9]+', 'file' => '[0-9]+']);

    // The approval gate. Only reachable where Settings > Approval switched a rule
    // on; submit is the raiser's action, approve/reject belong to someone else.
    Route::post('/vouchers/{type}/{id}/submit',  [VoucherController::class, 'submit'])->where('id', '[0-9]+');
    Route::post('/vouchers/{type}/{id}/approve', [VoucherController::class, 'approve'])->where('id', '[0-9]+');
    Route::post('/vouchers/{type}/{id}/reject',  [VoucherController::class, 'reject'])->where('id', '[0-9]+');

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
    Route::get('/products/{product}/units',    [ProductController::class, 'units']);
    Route::put('/products/{product}/units',    [ProductController::class, 'saveUnits']);
    Route::get('/products/{product}/convert',  [ProductController::class, 'convert']);

    // Warehouses + bin locations
    Route::get('/warehouses',        [WarehouseController::class, 'index']);
    Route::post('/warehouses',       [WarehouseController::class, 'store']);
    Route::get('/warehouses/{warehouse}',    [WarehouseController::class, 'show']);
    Route::put('/warehouses/{warehouse}',    [WarehouseController::class, 'update']);
    Route::delete('/warehouses/{warehouse}', [WarehouseController::class, 'destroy']);
    // The site as a building: bin occupancy, what is over its limit, and where
    // a given item should go. See LayoutService for why a capacity without a
    // unit is refused rather than guessed at.
    Route::get('/warehouses/{warehouse}/layout',  [WarehouseController::class, 'layout']);
    Route::post('/warehouses/{warehouse}/putaway', [WarehouseController::class, 'putaway']);

    Route::get('/warehouses/{warehouse}/locations',  [WarehouseController::class, 'locations']);
    Route::post('/warehouses/{warehouse}/locations', [WarehouseController::class, 'storeLocation']);
    Route::delete('/warehouses/{warehouse}/locations/{location}', [WarehouseController::class, 'destroyLocation']);
});

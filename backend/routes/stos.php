<?php

use App\Http\Controllers\Api\V1\Transport\DeviceTokenController;
use App\Http\Controllers\Api\V1\Transport\GensetController;
use App\Http\Controllers\Api\V1\Transport\DriverController;
use App\Http\Controllers\Api\V1\Transport\DriverDocumentController;
use App\Http\Controllers\Api\V1\Transport\FleetController;
use App\Http\Controllers\Api\V1\Transport\FuelController;
use App\Http\Controllers\Api\V1\Transport\MaintenanceController;
use App\Http\Controllers\Api\V1\Transport\OperatingCostController;
use App\Http\Controllers\Api\V1\Transport\TelemetryIngestionController;
use App\Http\Controllers\Api\V1\Transport\TrailerController;
use App\Http\Controllers\Api\V1\Transport\VehicleAllocationController;
use App\Http\Controllers\Api\V1\Transport\VehicleController;
use App\Http\Controllers\Api\V1\Transport\VehicleDocumentController;
use App\Http\Controllers\Api\V1\Transport\VehiclePassportController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| STOS — Sangoé Transport OS (owner: Developer 2, Fleet & Telemetry)
|--------------------------------------------------------------------------
|
| One route file for the module, required once from routes/api.php — the
| repo's module convention, so STOS routes never collide with anyone else's
| edits to the shared file.
|
| Everything lives under /api/v1/ per the M2 brief. Two doors, deliberately
| different:
|
|   • /v1/telemetry/*  — HARDWARE. No user session exists; a GPS unit presents
|     a shared secret in X-Device-Token, which `stos.device` checks and which
|     fails closed when no token is configured.
|   • /v1/fleet/*      — PEOPLE. auth:sanctum + role:admin,staff, with every
|     controller repeating the check as a structural backstop.
|
| Never move a route between those groups without moving its auth with it.
|
*/

Route::prefix('v1/telemetry')->middleware('stos.device')->group(function () {
    Route::post('/ingest', [TelemetryIngestionController::class, 'ingest']);

    // T-13 — the same door, for a unit posting a buffered run rather than a
    // single ping. Same auth, same validation per reading.
    Route::post('/ingest/batch', [TelemetryIngestionController::class, 'ingestBatch']);
});

Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('v1/fleet')->group(function () {

    // ── Step 1: the vehicle master ──────────────────────────────────────
    // Everything below needs the vehicle_id these create.
    Route::get('/vehicles', [FleetController::class, 'grid']);
    Route::post('/vehicles', [VehicleController::class, 'store']);
    Route::get('/vehicle-options', [VehicleController::class, 'options']);
    Route::put('/vehicles/{vehicle}', [VehicleController::class, 'update'])->where('vehicle', '[0-9]+');
    Route::delete('/vehicles/{vehicle}', [VehicleController::class, 'destroy'])->where('vehicle', '[0-9]+');
    // T-56 — the hand-driven edge of the asset state machine. Absorbed from
    // Dev 1's retiring endpoint; Fleet is the sole authority for this machine.
    Route::patch('/vehicles/{vehicle}/status', [VehicleController::class, 'transition'])->where('vehicle', '[0-9]+');

    // T-57 — statutory paperwork. Absorbed from Dev 1's retiring endpoint.
    // Writes go through STOS-DOC's service; Fleet owns only the consequence,
    // which is that five of these gate dispatch.
    Route::get('/vehicles/{vehicle}/documents', [VehicleDocumentController::class, 'index'])->where('vehicle', '[0-9]+');
    Route::post('/vehicles/{vehicle}/documents', [VehicleDocumentController::class, 'store'])->where('vehicle', '[0-9]+');
    Route::post('/vehicles/{vehicle}/documents/{document}/renew', [VehicleDocumentController::class, 'renew'])
        ->where(['vehicle' => '[0-9]+', 'document' => '[0-9]+']);
    // INTERIM — the verification workflow is Person 3's. See the controller.
    Route::patch('/documents/{document}/verify', [VehicleDocumentController::class, 'verify'])->where('document', '[0-9]+');

    // BEFORE the {vehicle} routes: "eligible" is a word, not an id, and a
    // wildcard declared first would swallow it.
    Route::get('/vehicles/eligible', [VehicleAllocationController::class, 'eligible']);

    Route::get('/vehicles/{vehicle}/live-status', [FleetController::class, 'liveStatus'])->where('vehicle', '[0-9]+');
    // Addressed by registration number or id — a person types the plate.
    Route::get('/vehicles/{vehicle}/passport', [VehiclePassportController::class, 'show']);

    // ── Fuel & emergency diesel (Feature 3) ─────────────────────────────
    Route::post('/vehicles/{vehicle}/fuel', [FuelController::class, 'store'])->where('vehicle', '[0-9]+');
    Route::get('/fuel/exceptions', [FuelController::class, 'exceptions']);
    Route::get('/fuel/{fuel}/receipt', [FuelController::class, 'receipt'])->where('fuel', '[0-9]+');

    // ── Drivers (STOS-FLEET) ────────────────────────────────────────────
    // Read LIVE from the CRM's customer/vendor directories — there is no
    // "create driver" here, because STOS does not own people.
    // ── Gensets (T-05) ──────────────────────────────────────────────────
    // Fit and unfit are their own endpoints, not a field on the update: a unit
    // physically moving between trailers is an event, and it is logged as one.
    Route::get('/gensets', [GensetController::class, 'index']);
    Route::post('/gensets', [GensetController::class, 'store']);
    Route::put('/gensets/{genset}', [GensetController::class, 'update'])->where('genset', '[0-9]+');
    Route::post('/gensets/{genset}/fit', [GensetController::class, 'fit'])->where('genset', '[0-9]+');
    Route::post('/gensets/{genset}/unfit', [GensetController::class, 'unfit'])->where('genset', '[0-9]+');

    // ── Device credentials (T-07) ───────────────────────────────────────
    // PEOPLE manage these; the hardware door is /v1/telemetry. Issuing from
    // behind the credential check would let any unit mint more.
    Route::get('/devices/tokens', [DeviceTokenController::class, 'index']);
    Route::post('/devices/tokens', [DeviceTokenController::class, 'store']);
    Route::post('/devices/tokens/{token}/rotate', [DeviceTokenController::class, 'rotate'])->where('token', '[0-9]+');
    Route::delete('/devices/tokens/{token}', [DeviceTokenController::class, 'revoke'])->where('token', '[0-9]+');

    Route::get('/drivers', [DriverController::class, 'index']);
    // The crew half of allocation. Same response shape as eligible vehicles,
    // because a dispatch board shows them side by side.
    Route::get('/drivers/eligible', [DriverController::class, 'eligible']);
    Route::put('/drivers/{source}/{person}', [DriverController::class, 'saveProfile'])
        ->where('source', '[a-z_]+')->where('person', '[0-9]+');
    Route::put('/drivers/{source}/{person}/assign', [DriverController::class, 'assign'])
        ->where('source', '[a-z_]+')->where('person', '[0-9]+');

    // ── A driver's paperwork (T-43) ─────────────────────────────────────
    // Absorbs Dev 1's retiring `/api/transport/drivers/{id}/documents`. The
    // `{source}` constraint keeps "eligible" from ever matching these: it is a
    // word, and these routes want a directory name.
    Route::get('/drivers/{source}/{person}/documents', [DriverDocumentController::class, 'index'])
        ->where('source', '[a-z_]+')->where('person', '[0-9]+');
    Route::post('/drivers/{source}/{person}/documents', [DriverDocumentController::class, 'store'])
        ->where('source', '[a-z_]+')->where('person', '[0-9]+');
    Route::post('/drivers/{source}/{person}/documents/{document}/renew', [DriverDocumentController::class, 'renew'])
        ->where('source', '[a-z_]+')->where('person', '[0-9]+')->where('document', '[0-9]+');
    // Verification is by document id — the verdict is about the evidence, not
    // about whose it is. Separate path from the vehicle one so neither has to
    // guess which kind it was handed.
    Route::patch('/driver-documents/{document}/verify', [DriverDocumentController::class, 'verify'])
        ->where('document', '[0-9]+');

    // ── Trailers and the coupling between (T-54) ────────────────────────
    // `history` BEFORE `{trailer}`: it is a word, not an id, and the numeric
    // constraint alone would not stop the detail route claiming it first.
    Route::get('/trailers', [TrailerController::class, 'index']);
    Route::get('/trailers/history', [TrailerController::class, 'history']);
    Route::post('/trailers', [TrailerController::class, 'store']);
    Route::put('/trailers/{trailer}', [TrailerController::class, 'update'])->where('trailer', '[0-9]+');
    Route::get('/trailers/{trailer}/compliance', [TrailerController::class, 'compliance'])->where('trailer', '[0-9]+');
    Route::post('/trailers/{trailer}/couple', [TrailerController::class, 'couple'])->where('trailer', '[0-9]+');
    Route::post('/trailers/{trailer}/uncouple', [TrailerController::class, 'uncouple'])->where('trailer', '[0-9]+');

    // ── Urea / AdBlue (STOS-COST) ───────────────────────────────────────
    Route::post('/vehicles/{vehicle}/urea', [OperatingCostController::class, 'storeUrea'])->where('vehicle', '[0-9]+');

    // ── Tyres (STOS-MAINT) ──────────────────────────────────────────────
    Route::get('/vehicles/{vehicle}/tyres', [OperatingCostController::class, 'tyres'])->where('vehicle', '[0-9]+');
    Route::post('/tyres/fit', [OperatingCostController::class, 'fitTyre']);
    Route::put('/tyres/{fitment}/inspect', [OperatingCostController::class, 'inspectTyre'])->where('fitment', '[0-9]+');
    Route::put('/tyres/{fitment}/remove', [OperatingCostController::class, 'removeTyre'])->where('fitment', '[0-9]+');

    // ── Trip cost roll-up — the HTTP face of Developer 3's contract ──────
    Route::get('/trips/{trip}/operating-costs', [OperatingCostController::class, 'tripCosts'])->where('trip', '[0-9]+');

    // ── Workshop job cards (Feature 4) ──────────────────────────────────
    Route::get('/maintenance/job-cards', [MaintenanceController::class, 'index']);
    Route::post('/maintenance/job-cards', [MaintenanceController::class, 'store']);
    Route::put('/maintenance/job-cards/{job}', [MaintenanceController::class, 'update'])->where('job', '[0-9]+');
    Route::put('/maintenance/job-cards/{job}/close', [MaintenanceController::class, 'close'])->where('job', '[0-9]+');
});

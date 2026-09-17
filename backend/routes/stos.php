<?php

use App\Http\Controllers\Api\V1\Transport\DeviceTokenController;
use App\Http\Controllers\Api\V1\Transport\DriverController;
use App\Http\Controllers\Api\V1\Transport\FleetController;
use App\Http\Controllers\Api\V1\Transport\FuelController;
use App\Http\Controllers\Api\V1\Transport\MaintenanceController;
use App\Http\Controllers\Api\V1\Transport\OperatingCostController;
use App\Http\Controllers\Api\V1\Transport\TelemetryIngestionController;
use App\Http\Controllers\Api\V1\Transport\VehicleAllocationController;
use App\Http\Controllers\Api\V1\Transport\VehicleController;
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

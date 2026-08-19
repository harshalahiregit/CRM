<?php

use App\Http\Controllers\Api\Vendor\VendorController;
use Illuminate\Support\Facades\Route;

// ── Vendor Master (Sanctum + role:admin,staff) ──────────────────────────
// Shared registry behind both TPV (onboarding/HSSE) and Purchase (procurement).
// Staff-only: a `vendor`/`third_party_vendor`/`client` login must never read the
// tenant's whole vendor book. Vendor self-service lands on its own portal route
// group scoped to the caller's own record — not here.
// NOTE: both middleware must go in ONE ->middleware([...]) call. Chaining a
// second ->middleware() REPLACES the first instead of merging, which silently
// drops auth:sanctum — the bug currently live in routes/admin.php.
Route::middleware(['auth:sanctum', 'role:admin,staff'])->prefix('vendors')->group(function () {

    Route::get('/stats',                    [VendorController::class, 'stats']);
    Route::get('/',                         [VendorController::class, 'index']);
    Route::post('/',                        [VendorController::class, 'store']);
    Route::get('/{vendor}',                 [VendorController::class, 'show']);
    Route::put('/{vendor}',                 [VendorController::class, 'update']);
    Route::patch('/{vendor}/status',        [VendorController::class, 'updateStatus']);
    // Manual resend of the activation e-mail (Active vendors only).
    Route::post('/{vendor}/resend-activation', [VendorController::class, 'resendActivation']);
    Route::post('/{vendor}/email',          [VendorController::class, 'sendEmail']);
    // Mints a one-time set-password link and returns the pre-filled draft. Sending
    // still goes through /email above — one send path, not two.
    Route::get('/{vendor}/login-link',       [VendorController::class, 'loginLink']);
    Route::delete('/{vendor}',              [VendorController::class, 'destroy']);
});

// Approval is an admin-only act — it is the gate that makes a vendor
// transactable and grants site access, so staff must not self-approve.
Route::middleware(['auth:sanctum', 'role:admin'])->prefix('vendors')->group(function () {

    Route::post('/{vendor}/approve',        [VendorController::class, 'approve']);
    // Compliance suspension is an admin authority (the auto-sweep does it too).
    Route::post('/{vendor}/suspend',        [VendorController::class, 'suspend']);
    Route::post('/{vendor}/reinstate',      [VendorController::class, 'reinstate']);
    Route::post('/{vendor}/offboard',       [VendorController::class, 'offboard']);
});

<?php

use App\Http\Controllers\Api\Contract\ContractController;
use App\Http\Controllers\Api\Contract\PartyContractController;
use App\Http\Controllers\Api\Contract\PublicContractController;
use App\Support\Shared\MeetingVisibility;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Contract module
|--------------------------------------------------------------------------
|
| A company-wide module of its own. Sales, Purchase and TPV keep their existing
| contract features untouched; this one stands beside them and links out to
| their customers and vendors through the counterparty morph.
|
| Open to every internal role, for the same reason Meetings is: a contract is
| company business, not one department's. Externals never reach these routes —
| the counterparty signs through the token routes at the bottom, which need no
| login at all.
*/

$internal = 'role:'.implode(',', MeetingVisibility::INTERNAL_ROLES);

Route::middleware(['auth:sanctum', $internal])->prefix('contracts')->group(function () {
    // Static segments before the {contract} wildcard, or "stats" is parsed as an id.
    Route::get('/stats',      [ContractController::class, 'stats']);
    Route::get('/categories', [ContractController::class, 'categories']);
    Route::post('/categories', [ContractController::class, 'storeCategory']);
    Route::get('/parties',    [ContractController::class, 'parties']);
    // Contracts belonging to ONE customer or vendor — what the Customer and
    // Vendor detail screens show on their Contracts tab. Read-only and served
    // from this module, so those modules keep working unchanged.
    Route::get('/for/{partyType}/{partyId}', [ContractController::class, 'forParty'])
        ->where(['partyType' => '[a-z_]+', 'partyId' => '[0-9]+']);

    Route::get('/',  [ContractController::class, 'index']);
    Route::post('/', [ContractController::class, 'store']);

    Route::get('/{contract}',    [ContractController::class, 'show']);
    Route::put('/{contract}',    [ContractController::class, 'update']);
    Route::delete('/{contract}', [ContractController::class, 'destroy']);

    Route::patch('/{contract}/status', [ContractController::class, 'setStatus']);
    Route::post('/{contract}/renew',   [ContractController::class, 'renew']);
    Route::post('/{contract}/sign',    [ContractController::class, 'sign']);
    // E-mails the contract (PDF + signing link) through the tenant's own SMTP.
    Route::post('/{contract}/send',    [ContractController::class, 'send']);
    Route::post('/{contract}/comments', [ContractController::class, 'comment']);

    Route::get('/{contract}/pdf',          [ContractController::class, 'pdf']);
    Route::get('/{contract}/signing-link', [ContractController::class, 'signingLink']);
});

/*
| The counterparty's routes — no login, the token is the authority.
|
| Kept outside the group above deliberately: a customer signing a contract has
| no account here, and requiring one would mean creating a login for every party
| we ever contract with.
*/
Route::prefix('public/contracts')->group(function () {
    Route::get('/{token}',          [PublicContractController::class, 'show']);
    Route::post('/{token}/sign',    [PublicContractController::class, 'sign']);
    Route::post('/{token}/comments', [PublicContractController::class, 'comment']);
    Route::get('/{token}/pdf',      [PublicContractController::class, 'pdf']);
    // Reached by scanning the QR on a printed page. Says only that the document
    // is real and who signed it.
    Route::get('/{token}/verify',   [PublicContractController::class, 'verify']);
})->where(['token' => '[A-Za-z0-9]{20,64}']);


/*
| The counterparty's own contracts, inside whichever portal they log in to.
|
| Declared here rather than in routes/portal.php so the Contract module owns its
| own surface and the existing portal route files stay untouched.
|
| NOT ONE of these takes a parameter, and that is a hard rule rather than a
| style: the client portal's security model is that no route accepts an
| identifier from the caller, so the guarantee is auditable by scanning the route
| table instead of by reading every controller. ClientPortalTest asserts exactly
| that, and it is what caught the first version of these routes. The list
| therefore carries each contract in full, and commenting names the contract in
| the request BODY, checked against the caller's own party.
|
| The path is `agreements`, not `contracts`, because the client and purchase
| portals ALREADY serve /contracts from their own legacy tables
| (client_contracts, purchase_contracts — the latter has live rows). Laravel
| matches the first registration, so these would have been silently shadowed and
| the party would have kept seeing the old, empty list. The middleware
| is the same as each portal's own group — it is what resolves the caller onto
| the request, and the controller reads the party from THERE, never from the URL.
|
| The onboarding gate (vendor.onboarded) allows reads, so a vendor still waiting
| for approval can read a contract they have been sent. Commenting on it is a
| write and is gated with everything else, which is right: negotiating terms is
| operational work.
*/

// Customer portal.
Route::middleware(['auth:sanctum', 'client.portal'])->prefix('portal/client/agreements')->group(function () {
    Route::get('/',         [PartyContractController::class, 'index']);
    Route::post('/comment', [PartyContractController::class, 'comment']);
});

// TPV vendor portal.
Route::middleware(['auth:sanctum', 'vendor.portal', 'temp.access', 'vendor.onboarded'])
    ->prefix('portal/agreements')->group(function () {
        Route::get('/',         [PartyContractController::class, 'index']);
        Route::post('/comment', [PartyContractController::class, 'comment']);
    });

// Purchase vendor portal.
Route::middleware(['auth:sanctum', 'purchase.vendor.portal', 'vendor.onboarded'])
    ->prefix('portal/purchase/agreements')->group(function () {
        Route::get('/',         [PartyContractController::class, 'index']);
        Route::post('/comment', [PartyContractController::class, 'comment']);
    });

<?php

use App\Http\Controllers\Api\Hr\PoshComplainantPortalController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| POSH complainant portal — public, scoped entirely by the {token}
|--------------------------------------------------------------------------
|
| Its own file and its own prefix, deliberately kept away from routes/hr.php.
| Every group in there assumes an authenticated staff user; a complainant has
| no account and never gets one, so these routes must not inherit auth:sanctum
| or any permission middleware. Keeping them physically separate means a route
| cannot drift into the authenticated block by being added in the wrong place.
|
| THE TOKEN IS NOT CONSTRAINED AT THE ROUTER, deliberately, and this is the
| one thing here worth reading twice. A `where('token','[A-Za-z0-9]{64}')`
| looks like free defence and it is not: the router's own 404 carries a
| different body from the controller's, so a malformed value answers
| differently from a well-formed one that does not exist. That difference tells
| a caller "this string is the right shape" and turns the portal into an oracle
| for the token format.
|
| So every value reaches the controller and leaves through one refusal.
| PoshTokenService::resolve() still checks length and character set in PHP
| before it hashes or queries anything, so nothing actually reaches the
| database that should not.
|
| READ ONLY. There is no POST here, by decision: a complainant cannot write to
| the thread and cannot upload. What the committee would be obliged to do with
| such a submission has not been settled.
|
| Throttled because the token is a bearer credential and the only thing
| standing between a guessed URL and a harassment file. 64 alphanumeric
| characters is not guessable, but a rate limit costs nothing and removes the
| question.
*/
Route::prefix('posh/portal/{token}')
    ->middleware('throttle:20,1')
    ->group(function () {
        Route::get('/',       [PoshComplainantPortalController::class, 'show']);
        Route::get('/thread', [PoshComplainantPortalController::class, 'thread']);
    });

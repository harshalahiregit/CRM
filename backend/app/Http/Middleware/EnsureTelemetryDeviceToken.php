<?php

namespace App\Http\Middleware;

use App\Domains\Integration\Services\DeviceTokenService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door on the telemetry ingest endpoint.
 *
 * Hardware cannot log in — a GPS box has no session and no Sanctum token — so
 * this endpoint sits outside auth:sanctum and is guarded by a credential in the
 * X-Device-Token header instead.
 *
 * ── TWO KINDS OF CREDENTIAL, AND WHY BOTH ARE HERE (T-07) ─────────────────
 * A **per-device token** is checked first. It identifies one unit in one
 * company, can be revoked on its own, and — the part that actually fixes
 * something — it tells ingestion WHICH COMPANY is calling. `gps_device_id` is
 * unique per company, not globally, so under the shared secret a device id held
 * by two companies could not be resolved at all and was refused with 409. With
 * a per-device token that ambiguity does not arise.
 *
 * The **fleet-wide shared secret** still works, and is deprecated. Removing it
 * in the same change that introduces tokens would take every already-flashed
 * unit off the air at once. It is honoured, marked as legacy on the request so
 * the rest of the stack knows what it is dealing with, and every use of it is
 * logged so there is a list of what still has to be migrated.
 *
 * It FAILS CLOSED. If neither credential verifies, the request is refused —
 * because the alternative is an open write endpoint, and anyone who could post
 * to it could forge a temperature trail, which is a forged delivery record.
 */
class EnsureTelemetryDeviceToken
{
    public function __construct(private DeviceTokenService $tokens)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $presented = (string) ($request->header('X-Device-Token') ?? '');

        if ($presented === '') {
            return $this->refuse();
        }

        // ── 1. A credential issued to one unit ────────────────────────
        if ($token = $this->tokens->verify($presented)) {
            // Carried so ingestion can scope resolution to this company
            // instead of searching every company for the device id.
            $request->attributes->set('stos_device_company_id', (int) $token->company_id);
            $request->attributes->set('stos_device_id', $token->device_id);
            $request->attributes->set('stos_device_token_id', $token->id);

            return $next($request);
        }

        // ── 2. The legacy fleet-wide secret ───────────────────────────
        $shared = (string) config('stos.ingest.token', '');

        if ($shared === '') {
            // No shared secret configured and the token did not verify. Not a
            // 503 any more: the endpoint IS configured, this caller just is not
            // recognised, and 503 would tell an attacker the server is unarmed.
            return $this->refuse();
        }

        // hash_equals, not ===, so the comparison does not leak the secret one
        // byte at a time through response timing.
        if (! hash_equals($shared, $presented)) {
            return $this->refuse();
        }

        $request->attributes->set('stos_device_legacy_auth', true);

        return $next($request);
    }

    private function refuse(): Response
    {
        // One message for an absent, unknown and revoked credential alike.
        // Distinguishing them confirms a correct guess to whoever is guessing.
        return response()->json([
            'status'  => 'error',
            'message' => 'Unrecognised device credentials.',
        ], 401);
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The door on the telemetry ingest endpoint.
 *
 * Hardware cannot log in — a GPS box has no session and no Sanctum token — so
 * this endpoint sits outside auth:sanctum and is guarded by a shared secret in
 * the X-Device-Token header instead.
 *
 * It FAILS CLOSED. No configured token means every request is refused, because
 * the alternative is an open write endpoint: anyone who learns a device id
 * could post positions and temperatures for a real truck, and a forged
 * temperature trail is a forged delivery record.
 *
 * hash_equals, not ===, so the comparison does not leak the secret one byte at
 * a time through response timing.
 */
class EnsureTelemetryDeviceToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('stos.ingest.token', '');

        if ($expected === '') {
            return response()->json([
                'status'  => 'error',
                'message' => 'Telemetry ingestion is not configured on this server.',
            ], 503);
        }

        $presented = (string) ($request->header('X-Device-Token') ?? '');

        if ($presented === '' || ! hash_equals($expected, $presented)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Unrecognised device credentials.',
            ], 401);
        }

        return $next($request);
    }
}

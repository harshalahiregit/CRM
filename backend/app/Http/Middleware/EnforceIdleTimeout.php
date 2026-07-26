<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\UserSession;
use App\Services\AuditLogService;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Idle-timeout enforcement for tracked, non-"remember me" sessions.
 *
 * Resolves the bearer token directly (so it works ahead of the auth pipeline)
 * and touches the session's activity, timing it out after the configured idle
 * window. Entirely defensive: sessions with no user_sessions row (legacy tokens)
 * and remember-me sessions are untouched, so existing auth is never affected.
 */
class EnforceIdleTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $bearer = $request->bearerToken();
            if ($bearer) {
                $token = PersonalAccessToken::findToken($bearer);
                if ($token) {
                    $session = UserSession::where('token_id', $token->id)->active()->first();
                    $idle = (int) config('auth_sessions.idle_minutes', 30);

                    if ($session && ! $session->remember_me && $idle > 0) {
                        if ($session->last_activity_at && $session->last_activity_at->lt(now()->subMinutes($idle))) {
                            $token->delete();
                            $session->update(['revoked_at' => now()]);
                            $user = User::find($session->user_id);
                            if ($user) {
                                app(AuditLogService::class)->record($user, 'Session Timed Out', $user, null, [
                                    'idle_minutes' => $idle,
                                ]);
                            }

                            return response()->json([
                                'status'  => 'error',
                                'code'    => 'session_timed_out',
                                'message' => 'Your session has timed out due to inactivity. Please sign in again.',
                            ], 401);
                        }

                        // Touch, throttled to avoid a write on every request.
                        if (! $session->last_activity_at || $session->last_activity_at->lt(now()->subSeconds(60))) {
                            $session->forceFill(['last_activity_at' => now()])->saveQuietly();
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            // Never let session bookkeeping break a request.
        }

        return $next($request);
    }
}

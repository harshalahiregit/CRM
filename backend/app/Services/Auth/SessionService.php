<?php

namespace App\Services\Auth;

use App\Exceptions\BusinessException;
use App\Models\User;
use App\Models\UserSession;
use App\Services\AuditLogService;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Enterprise session management layered over Sanctum. The token remains the
 * authentication source of truth; this records session metadata, enforces the
 * concurrency policy, and powers the sessions UI / idle timeout. Default policy
 * is 'single', which preserves the existing one-device behaviour exactly.
 */
class SessionService
{
    public function __construct(private AuditLogService $audit)
    {
    }

    /**
     * Issue a token and record its session, applying the concurrency policy.
     * Returns the plain-text token (same contract the caller had before).
     */
    public function establish(User $user, bool $remember, array $meta): string
    {
        // The tenant's own Security setting wins over the deployment default.
        //
        // "Allow only a single active session" on the Security Settings page was
        // saved, read back, and consulted by nothing — this method only ever
        // looked at config/auth_sessions.php, so the toggle changed nothing and
        // an admin who turned it on got a success toast and two live sessions.
        $policy       = (string) config('auth_sessions.concurrency', 'single');
        $max          = (int) config('auth_sessions.max_devices', 1);
        $rememberDays = 0;   // 0 = "use the deployment default" (see below)

        if ($user->tenant_id) {
            try {
                $security = app(\App\Services\Settings\SettingsService::class)
                    ->getGroup((int) $user->tenant_id, 'security');

                if (! empty($security['single_session_only'])) {
                    $policy = 'single';
                    $max    = 1;
                }

                $rememberDays = (int) ($security['remember_me_days'] ?? 0);
            } catch (\Throwable $e) {
                // Never block a login on a settings read.
                \Illuminate\Support\Facades\Log::channel('auth')->warning(
                    'Security settings unreadable during login', ['error' => $e->getMessage()],
                );
            }
        }

        $active = UserSession::where('user_id', $user->id)->active()->orderBy('id')->get();
        $evict  = self::evictions($policy, $max, $active->pluck('id')->all());
        foreach ($active->whereIn('id', $evict) as $session) {
            $this->killSession($session);
        }
        // 'single' also clears any legacy/untracked tokens — exact prior behaviour.
        if ($policy === 'single') {
            $user->tokens()->delete();
        }

        // "Remember me for N days" is a tenant setting too; fall back to config.
        $days  = $remember
            ? ($rememberDays > 0 ? $rememberDays : (int) config('auth_sessions.remember_me_days', 30))
            : (int) config('auth_sessions.token_days', 30);
        $token = $user->createToken('crm-auth-token', ['*'], now()->addDays($days));

        UserSession::create([
            'tenant_id'        => $user->tenant_id,
            'user_id'          => $user->id,
            'token_id'         => $token->accessToken->id,
            'device'           => $meta['device'] ?? null,
            'browser'          => $meta['browser'] ?? null,
            'ip'               => $meta['ip'] ?? null,
            'remember_me'      => $remember,
            'last_activity_at' => now(),
        ]);

        $this->audit->record($user, 'Login', $user, null, [
            'ip' => $meta['ip'] ?? null, 'browser' => $meta['browser'] ?? null,
            'device' => $meta['device'] ?? null, 'remember' => $remember,
        ]);
        if ($remember) {
            $this->audit->record($user, 'Remember-Me Enabled', $user, null, []);
        }

        return $token->plainTextToken;
    }

    /**
     * Pure policy rule (unit-tested): given the active session ids (oldest first)
     * BEFORE a new session is added, which should be revoked to satisfy the policy?
     */
    public static function evictions(string $policy, int $maxDevices, array $activeIdsOldestFirst): array
    {
        if ($policy === 'single') {
            return $activeIdsOldestFirst;
        }

        // 0 (or less) means unlimited — the same convention idle_minutes uses.
        // Previously this collapsed to max(1, ...), so a cap of 0 silently
        // behaved as "one device" and every second login kicked the first out.
        if ($maxDevices <= 0) {
            return [];
        }

        $overflow = count($activeIdsOldestFirst) + 1 - $maxDevices;

        return $overflow > 0 ? array_slice($activeIdsOldestFirst, 0, $overflow) : [];
    }

    /** End the caller's current session (logout). */
    public function endCurrent(User $user): void
    {
        $token = $user->currentAccessToken();
        if (! $token) {
            return;
        }

        UserSession::where('token_id', $token->id)->active()->update(['revoked_at' => now()]);
        $this->audit->record($user, 'Session Revoked', $user, 'logout', ['token_id' => $token->id]);
        $token->delete();
    }

    /** Active sessions for the sessions UI, flagging the current one. */
    public function listFor(User $user, ?int $currentTokenId): array
    {
        return UserSession::where('user_id', $user->id)->active()->orderByDesc('last_activity_at')->get()
            ->map(fn (UserSession $s) => [
                'id'               => $s->id,
                'device'           => $s->device,
                'browser'          => $s->browser,
                'ip'               => $s->ip,
                'remember_me'      => $s->remember_me,
                'last_activity_at' => optional($s->last_activity_at)->toIso8601String(),
                'created_at'       => optional($s->created_at)->toIso8601String(),
                'current'          => $currentTokenId !== null && (int) $s->token_id === (int) $currentTokenId,
            ])
            ->all();
    }

    public function revoke(User $user, UserSession $session): void
    {
        if ((int) $session->user_id !== (int) $user->id) {
            throw new BusinessException('Session not found.', 404);
        }
        $this->killSession($session);
        $this->audit->record($user, 'Session Revoked', $user, null, ['session_id' => $session->id]);
    }

    public function revokeOthers(User $user, ?int $currentTokenId): int
    {
        $n = 0;
        foreach (UserSession::where('user_id', $user->id)->active()->get() as $session) {
            if ($currentTokenId !== null && (int) $session->token_id === (int) $currentTokenId) {
                continue;
            }
            $this->killSession($session);
            $n++;
        }
        if ($n > 0) {
            $this->audit->record($user, 'Session Revoked', $user, 'logout-others', ['count' => $n]);
        }

        return $n;
    }

    public function forceLogout(User $target, User $actor): int
    {
        $n = 0;
        foreach (UserSession::where('user_id', $target->id)->active()->get() as $session) {
            $this->killSession($session);
            $n++;
        }
        $target->tokens()->delete(); // also clear any untracked tokens
        $this->audit->record($target, 'Force Logout', $actor, null, ['count' => $n]);

        return $n;
    }

    /** Update the caller's current-session activity timestamp (heartbeat). */
    public function touch(User $user): void
    {
        $token = $user->currentAccessToken();
        if ($token) {
            UserSession::where('token_id', $token->id)->active()->update(['last_activity_at' => now()]);
        }
    }

    private function killSession(UserSession $session): void
    {
        if ($session->token_id) {
            PersonalAccessToken::find($session->token_id)?->delete();
        }
        $session->update(['revoked_at' => now()]);
    }
}

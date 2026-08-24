<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Re-authentication before the vault opens.
 *
 * The legacy CRM had this (vault_confirm_password.php) and we dropped it in the
 * port. It is worth having back: a session left open on an unlocked laptop is
 * the realistic way a credential store leaks, and every other control here —
 * encryption at rest, per-entry visibility, the access log — assumes the person
 * at the keyboard is the person who logged in.
 *
 * The unlock is held server-side against the user id, not handed out as a token
 * the client stores. A token in localStorage would just be a second credential
 * to steal, and would survive a logout.
 */
class VaultUnlockService
{
    /** How long one unlock lasts. Long enough to work, short enough to matter. */
    private const WINDOW_MINUTES = 15;

    /** Wrong-password attempts before the vault refuses for a while. */
    private const MAX_ATTEMPTS = 5;

    private function key(User $user): string
    {
        return "vault-unlock:{$user->id}";
    }

    private function throttleKey(User $user): string
    {
        return "vault-unlock-attempts:{$user->id}";
    }

    /**
     * Confirm the user's own password and open the window.
     *
     * @throws BusinessException on a wrong password or too many attempts
     */
    public function unlock(User $user, string $password): int
    {
        $throttle = $this->throttleKey($user);

        if (RateLimiter::tooManyAttempts($throttle, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttle);

            throw new BusinessException(
                'Too many attempts. The vault is locked for '.max(1, (int) ceil($seconds / 60)).' minute(s).',
                429,
            );
        }

        if (! $user->password || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($throttle, self::WINDOW_MINUTES * 60);

            // Worth a log line: repeated failures here are someone at a desk
            // that is not theirs, which is exactly what this gate is for.
            Log::channel('daily')->warning('Vault unlock failed', [
                'user_id' => $user->id, 'ip' => request()->ip(),
            ]);

            throw new BusinessException('That password is not correct.', 401);
        }

        RateLimiter::clear($throttle);
        Cache::put($this->key($user), now()->timestamp, now()->addMinutes(self::WINDOW_MINUTES));

        return self::WINDOW_MINUTES * 60;
    }

    /** Is this user's vault currently open? */
    public function isUnlocked(User $user): bool
    {
        return Cache::has($this->key($user));
    }

    /** Seconds left on the window, or 0. */
    public function remaining(User $user): int
    {
        $since = Cache::get($this->key($user));
        if (! $since) {
            return 0;
        }

        return max(0, (self::WINDOW_MINUTES * 60) - (now()->timestamp - (int) $since));
    }

    /**
     * Refuse unless the vault is open.
     *
     * 423 Locked rather than 403: the caller is allowed to do this, they simply
     * have not confirmed who they are recently enough, and the UI needs to tell
     * those apart to know whether to prompt or to say no.
     */
    public function assertUnlocked(User $user): void
    {
        if (! $this->isUnlocked($user)) {
            throw new BusinessException('Confirm your password to open the vault.', 423);
        }
    }

    /** Close it — on logout, or when the user asks. */
    public function lock(User $user): void
    {
        Cache::forget($this->key($user));
    }
}

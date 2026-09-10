<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Services\Notifications\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A one-time link that lets somebody set their own password.
 *
 * It backs two things that look different and are the same underneath: the
 * forgot-password email, and inviting a new account to choose its first
 * password. Both mint the same token and land on the same /auth/set-password
 * page, so they are one implementation rather than two that drift.
 *
 * ── Why an invite matters more for a doctor than for most roles ─────────
 * A doctor login used to be created WITH a password, which was then shown to
 * the admin who created it. For an ordinary account that is merely untidy. For
 * this one it undermines the point of the module: every certificate carries
 * that doctor's licence number, and if an admin knows the password then
 * "Dr Rao signed this" is not a claim that survives being questioned.
 *
 * With an invite the doctor is the only person who ever knows their password,
 * so the signature on a certificate means what it says.
 */
class PasswordSetupLink
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Mint a fresh link for this user.
     *
     * One live token per address: issuing a second retires the first, or an
     * older email still sitting in an inbox keeps working after the newer one
     * has been used.
     */
    public function issue(User $user): string
    {
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $user->email],
            ['token' => Hash::make($token), 'created_at' => now()],
        );

        return FrontendUrl::to('/auth/set-password', ['token' => $token, 'email' => $user->email]);
    }

    /** How long a link stays usable, in minutes. */
    public function expiryMinutes(): int
    {
        return (int) config('auth.passwords.users.expire', 60);
    }

    /**
     * Invite a newly created account to choose its own password.
     *
     * Returns whether the mail was handed off. It is deliberately not fatal:
     * an account that exists but whose invite bounced can be invited again,
     * whereas failing the whole request would leave the admin unsure whether
     * the doctor was created at all.
     */
    public function invite(User $user, string $what = 'account', ?string $invitedBy = null): bool
    {
        $url = $this->issue($user);

        $body = implode("\n", array_filter([
            'Hello '.($user->name ?: '').',',
            '',
            $invitedBy
                ? $invitedBy.' has created your '.$what.' on Sangoe CRM.'
                : 'Your '.$what.' has been created on Sangoe CRM.',
            '',
            'Choose your password using the link below. Nobody else knows it, and',
            'nobody else can see it — which is what makes anything you sign yours.',
            $url,
            '',
            'This link can be used once and expires in '.$this->expiryMinutes().' minutes.',
            'If it has expired, ask for a new invitation or use "Forgot password" on the sign-in page.',
        ]));

        try {
            $this->notifications->email($user->email, 'Set your password', $body, ['user_id' => $user->id]);
            Log::info('Password setup invitation sent', ['user_id' => $user->id, 'what' => $what]);

            return true;
        } catch (\Throwable $e) {
            // The account is already created and the token already minted, so
            // the way out is to resend — not to fail and leave the admin
            // wondering whether anything happened.
            Log::warning('Password setup invitation could not be sent', [
                'user_id' => $user->id, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }
}

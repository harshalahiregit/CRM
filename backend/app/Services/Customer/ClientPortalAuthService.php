<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use App\Models\Customer\ClientContact;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Customer portal authentication.
 *
 * Restores the old CRM's model: the customer company never signs in — its
 * CONTACTS do. One company, several logins, each seeing only what its
 * permission flags allow. `client_contacts.password` has existed in Sangoe
 * since the rebuild with nothing to use it.
 *
 * Deliberately independent of the staff `User` guard and of the vendor and
 * purchase portals: a token issued here is tokenable to a ClientContact and
 * nothing else, so a customer contact can never reach a staff endpoint even if
 * a route is mis-registered.
 */
class ClientPortalAuthService
{
    private const TOKEN_NAME = 'client-portal';

    /** How long a set-password or reset link stays usable. */
    private const RESET_TTL_HOURS = 24;

    public function __construct(private ClientPortalNotifier $notifier)
    {
    }

    /**
     * Issue a set-password invitation.
     *
     * Used both for "invite this contact to the portal" and "they forgot their
     * password", because from the contact's side those are the same act: prove
     * you own the mailbox, then choose a password. Only the wording differs.
     */
    public function invite(ClientContact $contact, bool $isReset = false): void
    {
        if (! $contact->email) {
            throw new BusinessException('This contact has no email address to send an invitation to.', 422);
        }

        $contact->forceFill([
            'password_reset_token'      => Str::random(48),
            'password_reset_expires_at' => now()->addHours(self::RESET_TTL_HOURS),
            // An invitation is only meaningful if the portal is switched on for
            // them; inviting without this would send a link that cannot be used.
            'portal_status'             => $contact->portal_status === 'active' ? 'active' : 'invited',
        ])->save();

        $this->notifier->sendSetPassword($contact, $isReset);
    }

    /**
     * Forgotten password.
     *
     * Never reveals whether the address exists: a portal login page is public,
     * and a distinguishable response turns it into a customer-list oracle.
     */
    public function forgotPassword(string $email): void
    {
        $contact = ClientContact::where('email', $email)->whereNotNull('email')->first();

        if (! $contact || $contact->portal_status === 'disabled') {
            return;
        }

        $this->invite($contact, isReset: true);
    }

    /** Consume a set-password / reset token and set the password. */
    public function setPassword(string $token, string $password): ClientContact
    {
        $contact = ClientContact::where('password_reset_token', $token)->first();

        if (! $contact) {
            throw new BusinessException('This link is not valid or has already been used.', 404);
        }

        if (! $contact->password_reset_expires_at || $contact->password_reset_expires_at->isPast()) {
            throw new BusinessException('This link has expired. Please request a new one.', 410);
        }

        $contact->forceFill([
            'password'                  => Hash::make($password),
            'last_password_change'      => now(),
            'password_reset_token'      => null,
            'password_reset_expires_at' => null,
            // Following the link proves the mailbox, so verification is implied.
            'email_verified_at'         => $contact->email_verified_at ?? now(),
            'portal_status'             => 'active',
        ])->save();

        // Any session opened with the old password is no longer trustworthy —
        // a reset is exactly what someone does when they fear it was known.
        $contact->tokens()->delete();

        return $contact->fresh();
    }

    /** @return array{contact: ClientContact, token: string} */
    public function login(string $email, string $password, ?string $ip): array
    {
        $contact = ClientContact::where('email', $email)->first();

        // One message for every failure mode. "No such account" and "wrong
        // password" told apart is the same oracle as above.
        if (! $contact || ! $contact->password || ! Hash::check($password, $contact->password)) {
            throw new BusinessException('Those details do not match our records.', 401);
        }

        if ($contact->active === false) {
            throw new BusinessException('This contact has been deactivated. Please speak to your account manager.', 403);
        }

        if ($contact->portal_status !== 'active') {
            throw new BusinessException('Portal access has not been enabled for this contact.', 403);
        }

        $contact->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ])->save();

        Log::channel('daily')->info('Customer portal login', [
            'contact_id' => $contact->id, 'client_id' => $contact->client_id,
        ]);

        return [
            'contact' => $contact,
            'token'   => $contact->createToken(self::TOKEN_NAME)->plainTextToken,
        ];
    }

    public function logout(ClientContact $contact): void
    {
        $contact->currentAccessToken()?->delete();
    }

    /**
     * Change password from inside the portal, where the old one is known.
     * Other sessions are left alone: this is routine hygiene, not a compromise.
     */
    public function changePassword(ClientContact $contact, string $current, string $new): void
    {
        if (! $contact->password || ! Hash::check($current, $contact->password)) {
            throw new BusinessException('Your current password is not correct.', 422);
        }

        $contact->forceFill([
            'password'             => Hash::make($new),
            'last_password_change' => now(),
        ])->save();
    }
}

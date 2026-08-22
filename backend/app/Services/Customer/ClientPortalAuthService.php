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
     * The single portal account for an email address, or null.
     *
     * The portal has no tenant context — login is an email and a password,
     * nothing else — so an email that matches more than one contact is
     * genuinely ambiguous, and `first()` silently picked whichever row the
     * database happened to return. With the same person recorded at two
     * customers (a shared accountant, someone who moved companies), that is a
     * login into the wrong company's data.
     *
     * There is no safe guess here, so this refuses instead: an ambiguous email
     * authenticates nobody, and the collision is logged for someone to resolve.
     *
     * `$enabledOnly` separates the two callers, and the distinction matters.
     *
     * Forgotten-password proves nothing about who is asking, so it may only
     * find an account somebody already granted — otherwise the endpoint hands
     * out access to any address in the CRM.
     *
     * Login is the opposite: it must find the contact whatever their state, so
     * that the password is checked FIRST and a correct password can then be
     * told plainly that their access is switched off. Those messages are not an
     * enumeration oracle precisely because they come after proving the account
     * is yours — and "your access was disabled" is what that person needs to
     * read, rather than a generic failure they will retype three times.
     */
    private function portalAccountFor(string $email, bool $enabledOnly = true): ?ClientContact
    {
        $matches = ClientContact::query()
            ->where('email', $email)
            ->whereNotNull('email')
            ->when($enabledOnly, fn ($q) => $q->whereIn('portal_status', ['invited', 'active']))
            ->limit(2)
            ->get();

        if ($matches->count() > 1) {
            Log::warning('Portal login refused: the email matches more than one portal account.', [
                'email'       => $email,
                'contact_ids' => $matches->pluck('id')->all(),
            ]);

            return null;
        }

        return $matches->first();
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

        // Refuse to create the ambiguity rather than detect it at login. Two
        // portal accounts on one address cannot both sign in, so the second
        // invitation would silently lock out the first.
        $clash = ClientContact::query()
            ->where('email', $contact->email)
            ->where('id', '!=', $contact->id)
            ->whereIn('portal_status', ['invited', 'active'])
            ->first();

        if ($clash) {
            throw new BusinessException(
                'Another contact already uses this email address for portal access. '
                .'Remove their access first, or give this contact their own address.',
                422
            );
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
        $contact = $this->portalAccountFor($email);

        // Only an existing account may be reset. Previously this guarded on
        // portal_status === 'disabled' — a value the column never holds. The
        // default is 'inactive', so every contact nobody had invited fell
        // straight through to invite(), which set them to 'invited' and mailed
        // a working link. Anyone whose address was in the CRM could therefore
        // grant themselves a login, and reach the sections with no permission
        // gate: this company's client-visible notes and files, and the list of
        // everyone else with access.
        //
        // portalAccountFor() now admits only 'invited' and 'active', so the
        // state is decided in one place rather than by a string comparison
        // that had drifted away from the schema.
        if (! $contact) {
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
        // Any state — the password check below is the gate, and the specific
        // messages after it are only reachable once it passes.
        $contact = $this->portalAccountFor($email, enabledOnly: false);

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

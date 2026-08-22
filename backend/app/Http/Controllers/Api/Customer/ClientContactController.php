<?php

namespace App\Http\Controllers\Api\Customer;

use App\Rules\PhoneNumber;
use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Services\Customer\ClientPortalAuthService;
use App\Services\Customer\CustomFieldService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Per-customer contact management (the Contacts tab). Mirrors the legacy
 * contact modal: identity + primary flag + active flag + per-type email
 * notification toggles. Portal-login provisioning (creating a linked users
 * row) is intentionally deferred.
 */
class ClientContactController extends Controller
{
    use AssertsClientTenant;

    public const NOTIFICATION_KEYS = ['invoice', 'estimate', 'credit_note', 'proposal', 'project', 'ticket', 'task', 'contract'];

    public function __construct(private CustomFieldService $customFields)
    {
    }

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $tenantId = $request->user()->tenant_id;
        $isAdmin  = $request->user()->role === 'admin';

        $contacts = $client->contacts()->orderByDesc('is_primary')->get()
            ->map(function (ClientContact $c) use ($tenantId, $isAdmin) {
                $c->custom_fields = $this->customFields->valuesFor($tenantId, 'contacts', $c->id, $isAdmin);
                return $c;
            });

        return response()->json($contacts);
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        [$data, $customFields] = $this->validated($request, $client->id);

        $contact = $client->contacts()->create([...$data, 'tenant_id' => $client->tenant_id]);
        $this->customFields->saveValues($client->tenant_id, 'contacts', $contact->id, $customFields);
        $this->reconcilePrimary($client, $contact);

        return response()->json($contact->fresh(), 201);
    }

    public function update(Client $client, ClientContact $contact, Request $request)
    {
        $this->guard($client, $contact, $request);
        [$data, $customFields] = $this->validated($request, $client->id, $contact->id);
        $contact->update($data);
        $this->customFields->saveValues($client->tenant_id, 'contacts', $contact->id, $customFields);
        $this->reconcilePrimary($client, $contact);

        return response()->json($contact->fresh());
    }

    public function toggleActive(Client $client, ClientContact $contact, Request $request)
    {
        $this->guard($client, $contact, $request);
        $contact->update(['active' => ! $contact->active]);
        return response()->json($contact);
    }

    public function destroy(Client $client, ClientContact $contact, Request $request)
    {
        $this->guard($client, $contact, $request);

        // Don't leave a company with contacts but no primary.
        $wasPrimary = $contact->is_primary;
        $contact->delete();
        if ($wasPrimary) {
            $client->contacts()->where('active', true)->oldest()->first()?->update(['is_primary' => true]);
        }

        return response()->json(['message' => 'Contact deleted']);
    }

    /** @return array{0: array, 1: array} [contact attributes, custom-field values] */
    /**
     * Send this contact a portal invitation.
     *
     * Mints a set-password token and emails it through the tenant's own SMTP.
     * Deliberately an explicit action rather than a side effect of creating a
     * contact: most contacts never need a login, and silently mailing everyone
     * added to a customer would be both noisy and a small security surprise.
     */
    public function invite(Client $client, ClientContact $contact, Request $request, ClientPortalAuthService $auth)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($contact->client_id !== $client->id, 404);

        if (! $contact->email) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Add an email address before inviting this contact.',
            ], 422);
        }

        // An invitation to a contact with no permissions would land them on an
        // empty portal, so say so here rather than let them discover it.
        if (empty($contact->permissions)) {
            return response()->json([
                'status'  => 'error',
                'message' => 'Grant at least one section before inviting this contact, or they will see an empty portal.',
            ], 422);
        }

        $auth->invite($contact);

        return response()->json([
            'status'  => 'success',
            'message' => 'Invitation sent to '.$contact->email.'.',
        ]);
    }

    private function validated(Request $request, int $clientId, ?int $contactId = null): array
    {
        $data = $request->validate([
            'first_name'          => 'required|string|max:255',
            'last_name'           => 'nullable|string|max:255',
            'email'               => 'nullable|email|max:255',
            'phone'               => ['nullable', 'string', 'max:30', new PhoneNumber()],
            // §11 — role is what they are to us, department is where they sit,
            // and `title` (already present) is the designation on their card.
            'department'          => 'nullable|string|max:80',
            // Defence in depth. The role gate now requires a staff User, so a
            // contact cannot reach staff endpoints whatever this says — but a
            // contact whose role reads "admin" is misleading on every screen
            // that shows it, and the next person to write a string comparison
            // against a role should not find one waiting for them.
            //
            // A rejection list, not an allow-list: CustomerOptions keeps
            // CONTACT_ROLES deliberately open so a tenant can add "Distributor"
            // without a migration, and enforcing that list would turn every
            // edit of it into a data-repair job.
            'role'                => ['nullable', 'string', 'max:60', function ($attr, $value, $fail) {
                $reserved = ['admin', 'staff', 'third_party_vendor', 'client', 'company', 'superadmin', 'super_admin'];
                if (in_array(strtolower(trim((string) $value)), $reserved, true)) {
                    $fail('That word is reserved for staff accounts. Use the contact\'s role at their own company, such as Procurement or Finance.');
                }
            }],
            'whatsapp'            => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'is_decision_maker'   => 'nullable|boolean',
            'influence'           => 'nullable|string|max:20',
            'is_secondary'        => 'nullable|boolean',
            // Must be another contact of the SAME customer, or the org chart
            // would let one customer's contact report into another's.
            // Must be another contact of the SAME customer, and never itself —
            // a self-reference would render the org chart as an infinite loop.
            'reports_to'          => ['nullable', 'integer', Rule::exists('client_contacts', 'id')
                                        ->where('client_id', $clientId)
                                        ->when($contactId, fn ($q) => $q->whereNot('id', $contactId))],
            'title'               => 'nullable|string|max:100',
            'avatar'              => 'nullable|string|max:500000',
            'direction'           => 'nullable|in:ltr,rtl',
            'password'            => 'nullable|string|min:6|max:72',
            'is_primary'          => 'nullable|boolean',
            'active'              => 'nullable|boolean',
            'email_notifications' => 'nullable|array',
            'permissions'         => 'nullable|array',
            // Portal access is separate from `active`: most contacts are people
            // we mail invoices to and nothing more.
            'portal_status'       => 'nullable|in:inactive,invited,active,disabled',
            'permissions.*'       => 'string',
            'emails_enabled'      => 'nullable|boolean',
            'custom_fields'       => 'nullable|array',
        ]);

        $customFields = $data['custom_fields'] ?? [];
        unset($data['custom_fields']);

        // Blank password means "keep the existing one" — never overwrite with
        // empty. When a new one IS given, stamp the change time.
        if (empty($data['password'])) {
            unset($data['password']);
        } else {
            $data['last_password_change'] = now();
        }

        // Keep only recognised notification keys.
        if (isset($data['email_notifications'])) {
            $data['email_notifications'] = array_intersect_key(
                $data['email_notifications'],
                array_flip(self::NOTIFICATION_KEYS),
            );
        }

        // Keep only known modules, deduped, in canonical order.
        if (isset($data['permissions'])) {
            $data['permissions'] = array_values(array_intersect(
                ClientContact::MODULES,
                $data['permissions'],
            ));
        }

        return [$data, $customFields];
    }

    /** Ensure exactly one primary contact when this one is flagged primary. */
    private function reconcilePrimary(Client $client, ClientContact $contact): void
    {
        if ($contact->is_primary) {
            $client->contacts()->where('id', '!=', $contact->id)->update(['is_primary' => false]);
        } elseif (! $client->contacts()->where('is_primary', true)->exists()) {
            $client->contacts()->whereKey($contact->id)->update(['is_primary' => true]);
        }
    }

    private function guard(Client $client, ClientContact $contact, Request $request): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($contact->client_id !== $client->id, 404);
    }
}

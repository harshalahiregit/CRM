<?php

namespace App\Http\Controllers\Api\Customer;

use App\Rules\PhoneNumber;
use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Services\Customer\CustomFieldService;
use Illuminate\Http\Request;

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
    private function validated(Request $request, int $clientId, ?int $contactId = null): array
    {
        $data = $request->validate([
            'first_name'          => 'required|string|max:255',
            'last_name'           => 'nullable|string|max:255',
            'email'               => 'nullable|email|max:255',
            'phone'               => ['nullable', 'string', 'max:30', new PhoneNumber()],
            'title'               => 'nullable|string|max:100',
            'avatar'              => 'nullable|string|max:500000',
            'direction'           => 'nullable|in:ltr,rtl',
            'password'            => 'nullable|string|min:6|max:72',
            'is_primary'          => 'nullable|boolean',
            'active'              => 'nullable|boolean',
            'email_notifications' => 'nullable|array',
            'permissions'         => 'nullable|array',
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

<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientVaultAccessLog;
use App\Models\Customer\ClientVaultEntry;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;

/**
 * Per-customer credential vault. Passwords are encrypted at rest and never
 * included in list responses — the decrypted value is only returned by the
 * explicit reveal() endpoint.
 *
 * §15 of the Customer 360 document asks for Vault to differ from Files by
 * having "stronger RBAC and audit trails". The RBAC is ClientVaultEntry's
 * visibility rules; the trail is written here, on every action that changes a
 * credential or discloses one. Listing is not logged — the list never contains
 * a password, so a page view discloses nothing.
 */
class ClientVaultController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        // `password` is $hidden on the model, so it is excluded here.
        // Entries the user isn't allowed to see are filtered out server-side.
        return response()->json(
            $client->vaultEntries()->visibleTo($request->user())->latest()->get()
        );
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $data = $this->validated($request);

        $entry = $client->vaultEntries()->create([
            ...$data,
            'tenant_id'  => $client->tenant_id,
            'created_by' => $request->user()->id,
        ]);

        $this->audit($request, $entry, ClientVaultAccessLog::CREATED);

        return response()->json($entry, 201);
    }

    public function update(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        $vaultEntry->update($this->validated($request));
        $this->audit($request, $vaultEntry, ClientVaultAccessLog::UPDATED);

        return response()->json($vaultEntry);
    }

    /** Returns the decrypted password for a single entry (explicit reveal). */
    public function reveal(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request, manage: false);
        // The disclosure itself is the event worth recording.
        $this->audit($request, $vaultEntry, ClientVaultAccessLog::REVEALED);

        return response()->json(['password' => $vaultEntry->password]);
    }

    public function destroy(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        // Logged BEFORE the delete, so the trail exists even if the row does not.
        $this->audit($request, $vaultEntry, ClientVaultAccessLog::DELETED);
        $vaultEntry->delete();

        return response()->json(['message' => 'Vault entry deleted']);
    }

    /**
     * The audit trail for one entry.
     *
     * Administrators only. The log records who looked at which credential and
     * when, which is itself sensitive — handing it to every colleague would
     * turn an accountability record into a surveillance feed.
     */
    public function accessLog(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        abort_unless($request->user()->role === 'admin', 403,
            'Only an administrator can read the vault audit trail.');

        return response()->json(
            ClientVaultAccessLog::forTenant($client->tenant_id)
                ->where('vault_entry_id', $vaultEntry->id)
                ->with('user:id,name')
                ->orderByDesc('created_at')
                ->limit(200)
                ->get()
        );
    }

    /**
     * Append one row to the trail.
     *
     * Never allowed to break the action it is recording: a vault read must not
     * 500 because the audit insert failed. The write is best-effort and the
     * failure is logged, which is the right trade for a trail that exists to
     * explain history rather than to gate access.
     */
    private function audit(Request $request, ClientVaultEntry $entry, string $action): void
    {
        try {
            ClientVaultAccessLog::create([
                'tenant_id'      => $entry->tenant_id,
                'client_id'      => $entry->client_id,
                'vault_entry_id' => $entry->id,
                'user_id'        => $request->user()?->id,
                'action'         => $action,
                'ip'             => $request->ip(),
                'user_agent'     => substr((string) $request->userAgent(), 0, 255),
                'created_at'     => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'title'             => 'required|string|max:255',
            'username'          => 'nullable|string|max:255',
            'password'          => 'nullable|string|max:1000',
            'url'               => 'nullable|string|max:500',
            'notes'             => 'nullable|string|max:65535',
            'visibility'        => 'nullable|integer|in:1,2,3',
            'share_in_projects' => 'nullable|boolean',
        ]);

        // Notes are rich text — strip anything unsafe before storing.
        if (isset($data['notes'])) {
            $data['notes'] = HtmlSanitizer::clean($data['notes']);
        }

        return $data;
    }

    /**
     * @param bool $manage true for write actions (edit/delete), which are
     *                     limited to the entry's creator or an administrator.
     */
    private function guardEntry(Client $client, ClientVaultEntry $entry, Request $request, bool $manage = true): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($entry->client_id !== $client->id, 404);

        // Hidden entries must 404 (not 403) so their existence isn't leaked.
        abort_unless($entry->isVisibleTo($request->user()), 404);
        abort_unless(! $manage || $entry->isManageableBy($request->user()), 403,
            'Only the creator or an administrator can change this vault entry.');
    }
}

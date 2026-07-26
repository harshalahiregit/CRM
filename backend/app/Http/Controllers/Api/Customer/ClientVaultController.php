<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientVaultEntry;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;

/**
 * Per-customer credential vault. Passwords are encrypted at rest and never
 * included in list responses — the decrypted value is only returned by the
 * explicit reveal() endpoint.
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

        return response()->json($entry, 201);
    }

    public function update(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        $vaultEntry->update($this->validated($request));
        return response()->json($vaultEntry);
    }

    /** Returns the decrypted password for a single entry (explicit reveal). */
    public function reveal(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request, manage: false);
        return response()->json(['password' => $vaultEntry->password]);
    }

    public function destroy(Client $client, ClientVaultEntry $vaultEntry, Request $request)
    {
        $this->guardEntry($client, $vaultEntry, $request);
        $vaultEntry->delete();
        return response()->json(['message' => 'Vault entry deleted']);
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

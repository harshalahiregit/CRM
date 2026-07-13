<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientVaultEntry;
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
        return response()->json($client->vaultEntries()->get());
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
        $this->guardEntry($client, $vaultEntry, $request);
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
        return $request->validate([
            'title'    => 'required|string|max:255',
            'username' => 'nullable|string|max:255',
            'password' => 'nullable|string|max:1000',
            'url'      => 'nullable|string|max:500',
            'notes'    => 'nullable|string',
        ]);
    }

    private function guardEntry(Client $client, ClientVaultEntry $entry, Request $request): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($entry->client_id !== $client->id, 404);
    }
}

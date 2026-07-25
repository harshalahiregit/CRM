<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientAddress;
use Illuminate\Http\Request;

class ClientAddressController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        return response()->json($client->addresses()->get());
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $address = $client->addresses()->create([
            ...$this->validated($request),
            'tenant_id' => $client->tenant_id,
        ]);
        return response()->json($address, 201);
    }

    public function update(Client $client, ClientAddress $address, Request $request)
    {
        $this->guard($client, $address, $request);
        $address->update($this->validated($request));
        return response()->json($address);
    }

    public function destroy(Client $client, ClientAddress $address, Request $request)
    {
        $this->guard($client, $address, $request);
        $address->delete();
        return response()->json(['message' => 'Address deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'label'   => 'nullable|string|max:255',
            'address' => 'nullable|string|max:255',
            'city'    => 'nullable|string|max:100',
            'state'   => 'nullable|string|max:100',
            'zip'     => 'nullable|string|max:20',
            'country' => 'nullable|string|max:100',
        ]);
    }

    private function guard(Client $client, ClientAddress $address, Request $request): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($address->client_id !== $client->id, 404);
    }
}

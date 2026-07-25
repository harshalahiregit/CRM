<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientRecipient;
use Illuminate\Http\Request;

class ClientRecipientController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        return response()->json($client->recipients()->get());
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $recipient = $client->recipients()->create([
            ...$this->validated($request),
            'tenant_id' => $client->tenant_id,
        ]);
        return response()->json($recipient, 201);
    }

    public function update(Client $client, ClientRecipient $recipient, Request $request)
    {
        $this->guard($client, $recipient, $request);
        $recipient->update($this->validated($request));
        return response()->json($recipient);
    }

    public function destroy(Client $client, ClientRecipient $recipient, Request $request)
    {
        $this->guard($client, $recipient, $request);
        $recipient->delete();
        return response()->json(['message' => 'Recipient deleted']);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'name'    => 'required|string|max:255',
            'company' => 'nullable|string|max:255',
            'email'   => 'nullable|email|max:255',
            'phone'   => 'nullable|string|max:30',
            'address' => 'nullable|string|max:255',
            'city'    => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
        ]);
    }

    private function guard(Client $client, ClientRecipient $recipient, Request $request): void
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($recipient->client_id !== $client->id, 404);
    }
}

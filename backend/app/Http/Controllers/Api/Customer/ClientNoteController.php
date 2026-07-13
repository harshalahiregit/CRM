<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientNote;
use Illuminate\Http\Request;

class ClientNoteController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        return response()->json($client->notes()->with('author:id,name')->get());
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $data = $request->validate(['content' => 'required|string']);

        $note = $client->notes()->create([
            'tenant_id'  => $client->tenant_id,
            'content'    => $data['content'],
            'created_by' => $request->user()->id,
        ]);

        return response()->json($note->load('author:id,name'), 201);
    }

    public function destroy(Client $client, ClientNote $note, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($note->client_id !== $client->id, 404);
        $note->delete();
        return response()->json(['message' => 'Note deleted']);
    }
}

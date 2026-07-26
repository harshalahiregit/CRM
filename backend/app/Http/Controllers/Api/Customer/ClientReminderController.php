<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Models\Customer\ClientReminder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientReminderController extends Controller
{
    use AssertsClientTenant;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        return response()->json($client->reminders()->with('assignee:id,name')->get());
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $data = $request->validate([
            'description'    => 'required|string|max:255',
            'remind_at'      => 'required|date',
            // Scope the assignee to the acting tenant — no cross-tenant users.
            'remind_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $client->tenant_id)],
        ]);

        $reminder = $client->reminders()->create([
            ...$data,
            'tenant_id'  => $client->tenant_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($reminder->load('assignee:id,name'), 201);
    }

    public function destroy(Client $client, ClientReminder $reminder, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        abort_if($reminder->client_id !== $client->id, 404);
        $reminder->delete();
        return response()->json(['message' => 'Reminder deleted']);
    }
}

<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer\ClientGroup;
use Illuminate\Http\Request;

class ClientGroupController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(
            ClientGroup::forTenant($request->user()->tenant_id)
                ->withCount('clients')
                ->orderBy('name')
                ->get()
        );
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        $group = ClientGroup::create([...$data, 'tenant_id' => $request->user()->tenant_id]);
        return response()->json($group, 201);
    }

    public function update(Request $request, ClientGroup $group)
    {
        $this->assertTenant($group, $request->user()->tenant_id);
        $data = $request->validate(['name' => 'required|string|max:100']);
        $group->update($data);
        return response()->json($group);
    }

    public function destroy(Request $request, ClientGroup $group)
    {
        $this->assertTenant($group, $request->user()->tenant_id);
        $group->delete();
        return response()->json(['message' => 'Group deleted']);
    }

    private function assertTenant(ClientGroup $group, int $tenantId): void
    {
        abort_if($group->tenant_id !== $tenantId, 403, 'Unauthorized');
    }
}

<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use Illuminate\Http\Request;

/**
 * Shared CRUD for simple per-customer record tabs (contracts, expenses,
 * subscriptions, pre-alerts, packages, shipments). Subclasses declare the
 * Client relation name and the validation rules; everything else — tenant
 * guard, ownership check, create/update/delete — lives here.
 */
abstract class AbstractClientRecordController extends Controller
{
    use AssertsClientTenant;

    /** Name of the hasMany relation on Client (e.g. 'contracts'). */
    abstract protected function relation(): string;

    /** Validation rules for store/update. */
    abstract protected function rules(): array;

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        return response()->json($client->{$this->relation()}()->get());
    }

    public function store(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $data = $request->validate($this->rules());

        $record = $client->{$this->relation()}()->create([
            ...$data,
            'tenant_id'  => $client->tenant_id,
            'created_by' => $request->user()->id,
        ]);

        return response()->json($record, 201);
    }

    public function update(Client $client, int $recordId, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        // Resolve within the client's relation → inherently client + tenant scoped.
        $record = $client->{$this->relation()}()->findOrFail($recordId);
        $record->update($request->validate($this->rules()));
        return response()->json($record);
    }

    public function destroy(Client $client, int $recordId, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);
        $record = $client->{$this->relation()}()->findOrFail($recordId);
        $record->delete();
        return response()->json(['message' => 'Record deleted']);
    }
}

<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Services\Customer\Customer360Service;
use Illuminate\Http\Request;

/**
 * The Customer 360 overview — KPIs, alerts and account ownership for one customer.
 *
 * Read-only by design. Everything it returns belongs to another module; Customer
 * only frames it. See Customer360Service for the module boundary.
 */
class Customer360Controller extends Controller
{
    use AssertsClientTenant;

    public function __construct(private Customer360Service $overview)
    {
    }

    public function show(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        return response()->json($this->overview->overview($client));
    }
}

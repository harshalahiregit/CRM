<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Services\Customer\CustomerTimelineService;
use Illuminate\Http\Request;

/**
 * §5 — the Customer Timeline.
 *
 * Read-only, and deliberately so: the timeline is a view over records that
 * already exist elsewhere. Nothing is written here, and there is no "add
 * timeline entry" — an event on the timeline is always the shadow of a real
 * record in the module that owns it.
 */
class CustomerTimelineController extends Controller
{
    use AssertsClientTenant;

    public function __construct(private CustomerTimelineService $timeline)
    {
    }

    /**
     * §4 Activities, as a register of everything attached to this customer.
     *
     * Same sources as the Timeline, flat rather than grouped by day. Separate
     * from GET /activities, which stays the plain CRUD list the record form
     * writes to — merging them would make the writable endpoint's shape depend
     * on a query parameter.
     */
    public function feed(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        $data = $request->validate([
            'from'  => 'nullable|date',
            'to'    => 'nullable|date|after_or_equal:from',
            'types' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:1000',
        ]);

        return response()->json($this->timeline->feed(
            $client,
            $data['from'] ?? null,
            $data['to'] ?? null,
            array_filter(explode(',', $data['types'] ?? '')),
            (int) ($data['limit'] ?? 300),
        ));
    }

    public function index(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        $data = $request->validate([
            'from'    => 'nullable|date',
            'to'      => 'nullable|date|after_or_equal:from',
            'types'   => 'nullable|string',       // comma-separated event types
            'limit'   => 'nullable|integer|min:1|max:500',
        ]);

        return response()->json($this->timeline->timeline(
            $client,
            $data['from'] ?? null,
            $data['to'] ?? null,
            array_filter(explode(',', $data['types'] ?? '')),
            (int) ($data['limit'] ?? 200),
        ));
    }
}

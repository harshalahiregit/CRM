<?php

namespace App\Http\Controllers\Api\Transport;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Transport\TransportSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * One box, any Transport identifier — TM-001 §8's "Universal Search".
 *
 * CTD §4: "All relevant search paths must ultimately lead to the same Digital
 * Passport." This returns WHERE to go; the client navigates.
 *
 * Exact identifiers only — see TransportSearchService for why nothing here is
 * fuzzy. Gated by TRIP_VIEW: everything it can return is already visible to
 * someone who may read trips, and a search that finds records a user cannot
 * open would be a disclosure in itself.
 */
class TransportSearchController extends Controller
{
    use ApiResponse;

    public function __construct(private TransportSearchService $search)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate(
            ['q' => 'required|string|max:120'],
            ['q.required' => 'Type a container, trip, order or consignment number to search.'],
        );

        $hit = $this->search->resolve($data['q'], $request->user()->tenant_id);

        // A miss is a 200 with null, not a 404. "Nothing matches that" is a
        // legitimate answer to a search, and a 404 would make the client treat
        // it as a broken request.
        return $this->success(
            ['query' => $data['q'], 'result' => $hit],
            $hit ? 'Match found' : 'Nothing matches that identifier',
        );
    }
}

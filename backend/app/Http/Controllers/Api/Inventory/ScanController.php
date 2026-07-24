<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\ScanService;
use Illuminate\Http\Request;

/**
 * The scanner's single entry point. Point it at anything in the warehouse and
 * this says what it is — see ScanService for why it isn't five endpoints.
 */
class ScanController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private ScanService $scans)
    {
    }

    public function resolve(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate(['code' => 'required|string|max:120']);

        $hit = $this->scans->resolve($data['code'], $request->user()->tenant_id);

        // A miss is a normal outcome at a shelf — an unlabelled box, a smudged
        // sticker — not a client error. It returns 200 with found:false so the
        // scanner UI can say "nothing matches that" and stay ready for the next
        // scan, instead of throwing an error dialog at someone holding a box.
        return $this->success(
            $hit ? ['found' => true, ...$hit] : ['found' => false, 'code' => $data['code']],
            $hit ? 'Match found' : 'Nothing matches that code'
        );
    }
}

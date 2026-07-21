<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\AnalyticsService;
use Illuminate\Http\Request;

/**
 * Inventory analytics — ABC/XYZ, turnover, dead stock, stock accuracy and the
 * movement trend.
 *
 * Deliberately viewer-aware, the same way the Helpdesk analytics are: an admin
 * gets the tenant-wide picture, a staff member gets the identical maths applied
 * to their OWN recorded activity. The response says which scope it used so the
 * page can label itself honestly rather than implying one is the other.
 */
class AnalyticsController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private AnalyticsService $analytics)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        $f = $request->validate([
            'days'         => 'nullable|integer|min:7|max:365',
            'warehouse_id' => 'nullable|integer|min:1',
            // Admin drill-in: scope the whole page to one person. Ignored for
            // non-admins by the service, so it can't leak a colleague's numbers.
            'actor_id'     => 'nullable|integer|min:1',
        ]);

        return $this->success(
            $this->analytics->dashboard(
                $request->user()->tenant_id,
                $this->isAdmin($request),
                $request->user()->id,
                $f
            ),
            'Analytics computed'
        );
    }
}

<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Inventory\ReportService;
use Illuminate\Http\Request;

/**
 * Inventory reports (blueprint §8). One route serves all three report types —
 * they take the same filters and differ only in what they compute, so the kind
 * travels in the URL rather than duplicating three near-identical endpoints.
 */
class ReportController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    private const KINDS = ['summary', 'valuation', 'analysis'];

    public function __construct(private ReportService $reports)
    {
    }

    public function show(Request $request, string $kind)
    {
        $this->denyExternal($request);

        abort_unless(in_array($kind, self::KINDS, true), 404, 'Unknown report.');

        $filters = $request->validate([
            'from'         => 'nullable|date',
            'to'           => 'nullable|date|after_or_equal:from',
            'warehouse_id' => 'nullable|integer|min:1',
            'product_id'   => 'nullable|integer|min:1',
            'category_id'  => 'nullable|integer|min:1',
            'actor_id'     => 'nullable|integer|min:1',
        ]);

        $tenantId = $request->user()->tenant_id;

        $data = match ($kind) {
            'valuation' => $this->reports->valuation($tenantId, $filters),
            'analysis'  => $this->reports->analysis($tenantId, $filters),
            default     => $this->reports->stockSummary($tenantId, $filters),
        };

        return $this->success($data, 'Report generated');
    }
}

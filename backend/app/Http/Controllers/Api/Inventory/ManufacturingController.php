<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\BuildOrder;
use App\Services\Inventory\ManufacturingService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Manufacturing: BOMs + build orders. Internal staff manage; deleting a BOM is
 * admin-only. Completing a build posts real stock movements, so it's an
 * operational action any storekeeper may take (the ledger guards the rest).
 */
class ManufacturingController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private ManufacturingService $mfg)
    {
    }

    /* ── BOMs ───────────────────────────────────────────────────── */

    public function boms(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->listBoms($request->user()->tenant_id, $request->only(['status', 'product_id'])), 'BOMs retrieved');
    }

    public function showBom(Request $request, int $bom)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->showBom($bom, $request->user()->tenant_id), 'BOM retrieved');
    }

    public function storeBom(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->createBom($this->bomRules($request), $request->user()->tenant_id, $request->user()->id), 'BOM created', 201);
    }

    public function updateBom(Request $request, int $bom)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->updateBom($bom, $this->bomRules($request, false), $request->user()->tenant_id), 'BOM updated');
    }

    public function destroyBom(Request $request, int $bom)
    {
        $this->requireAdmin($request, 'delete a BOM');
        $this->mfg->deleteBom($bom, $request->user()->tenant_id);

        return $this->success(null, 'BOM deleted');
    }

    /* ── Build orders ───────────────────────────────────────────── */

    public function builds(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->listBuilds($request->user()->tenant_id, $request->only(['status'])), 'Build orders retrieved');
    }

    public function showBuild(Request $request, int $build)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->showBuild($build, $request->user()->tenant_id), 'Build order retrieved');
    }

    public function storeBuild(Request $request)
    {
        $this->denyExternal($request);
        $data = $request->validate([
            'bom_id'       => 'required|integer',
            'warehouse_id' => 'required|integer',
            'qty'          => 'required|numeric|gt:0',
            'note'         => 'nullable|string|max:2000',
        ]);

        return $this->success($this->mfg->createBuild($data, $request->user()->tenant_id, $request->user()->id), 'Build order created', 201);
    }

    public function buildAvailability(Request $request, int $build)
    {
        $this->denyExternal($request);

        return $this->success($this->mfg->buildAvailability($build, $request->user()->tenant_id), 'Availability computed');
    }

    public function setBuildStatus(Request $request, int $build)
    {
        $this->denyExternal($request);
        $data = $request->validate(['status' => ['required', Rule::in(BuildOrder::STATUSES)]]);

        return $this->success($this->mfg->setBuildStatus($build, $data['status'], $request->user()->tenant_id, $request->user()->id), 'Build order updated');
    }

    public function destroyBuild(Request $request, int $build)
    {
        $this->denyExternal($request);
        $this->mfg->deleteBuild($build, $request->user()->tenant_id);

        return $this->success(null, 'Build order deleted');
    }

    private function bomRules(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'product_id'         => ($creating ? 'required' : 'sometimes').'|integer',
            'name'               => 'nullable|string|max:180',
            'output_qty'         => 'nullable|numeric|gt:0',
            'status'             => ['nullable', Rule::in(['active', 'archived'])],
            'note'               => 'nullable|string|max:2000',
            'lines'              => 'sometimes|array',
            'lines.*.component_id' => 'required_with:lines|integer',
            'lines.*.qty'          => 'required_with:lines|numeric|gt:0',
            'lines.*.note'         => 'nullable|string|max:255',
        ]);
    }
}

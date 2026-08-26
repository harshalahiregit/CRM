<?php

namespace App\Http\Controllers\Api\Tpv;

use App\Http\Controllers\Controller;
use App\Models\Tpv\TpvWorker;
use App\Models\Tpv\TpvWorkerPpeIssue;
use App\Services\Tpv\PpeInventoryService;
use Illuminate\Http\Request;

/**
 * PPE, served straight from Inventory.
 *
 * Every figure here is read through PpeInventoryService, which reads Inventory —
 * this controller holds no stock logic of its own. Admin and both vendor portals
 * share it; the portal routes resolve the vendor from the token, so a vendor can
 * only ever see and issue against its own workers.
 */
class PpeController extends Controller
{
    public function __construct(private PpeInventoryService $ppe)
    {
    }

    /** Live PPE catalogue: name, sku, available, reserved, issued, reorder, status. */
    public function catalogue(Request $request)
    {
        return response()->json(
            $this->ppe->catalogue((int) $request->user()->tenant_id)->values()
        );
    }

    /** Dashboard summary cards — all derived from Inventory. */
    public function summary(Request $request)
    {
        return response()->json($this->ppe->summary((int) $request->user()->tenant_id));
    }

    /** One worker's PPE history: issued / returned / lost / damaged. */
    public function worker(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        return response()->json(
            $this->ppe->forWorker($worker->id, (int) $worker->tenant_id)->values()
        );
    }

    /**
     * The item photo, straight off the Inventory product.
     *
     * Inventory's own image route is admin-only; PPE is visible to both vendor
     * portals, so the picture is served here under the same tenancy rules as the
     * catalogue that references it.
     */
    public function image(Request $request, int $product)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $row = \App\Models\Inventory\Product::forTenant($tenantId)->findOrFail($product);

        abort_unless(
            $row->image_path && \Illuminate\Support\Facades\Storage::disk('local')->exists($row->image_path),
            404,
            'No image.'
        );

        return \Illuminate\Support\Facades\Storage::disk('local')->response($row->image_path);
    }

    /** Everyone currently holding a given PPE item — the Inventory→PPE direction. */
    public function holders(Request $request, int $product)
    {
        $tenantId = (int) $request->user()->tenant_id;

        $rows = TpvWorkerPpeIssue::query()
            ->where('tenant_id', $tenantId)
            ->where('inventory_item_id', $product)
            ->where('status', 'issued')
            ->with(['worker:id,name,worker_code,vendor_id', 'worker.vendor:id,company_name'])
            ->latest('issued_date')
            ->get()
            ->map(fn (TpvWorkerPpeIssue $i) => [
                'issue_id'    => $i->id,
                'worker_id'   => $i->tpv_worker_id,
                'worker'      => $i->worker?->name,
                'worker_code' => $i->worker?->worker_code,
                'vendor'      => $i->worker?->vendor?->company_name,
                'qty'         => (float) $i->qty - (float) $i->returned_qty,
                'issue_date'  => $i->issued_date,
                'worker_url'  => "/app/tpv/workforce/{$i->tpv_worker_id}",
            ]);

        return response()->json($rows);
    }

    /** Issue PPE. The stock guard lives in the service — see its issue(). */
    public function issue(Request $request, TpvWorker $worker)
    {
        $this->assertTenant($request, $worker);

        $data = $request->validate([
            'inventory_item_id' => 'required|integer|min:1',
            'qty'               => 'required|numeric|min:0.001',
            'size'              => 'nullable|string|max:40',
            'project'           => 'nullable|string|max:160',
            'site'              => 'nullable|string|max:160',
            'issued_date'       => 'nullable|date',
            'warehouse_id'      => 'nullable|integer|min:1',
            'notes'             => 'nullable|string|max:500',
        ]);

        return response()->json(
            $this->ppe->issue($worker, $data, $request->user()),
            201
        );
    }

    /** Return, or write off as lost / damaged. Partial quantities are allowed. */
    public function returnIssue(Request $request, TpvWorkerPpeIssue $issue)
    {
        $this->assertTenant($request, $issue);

        $data = $request->validate([
            'qty'          => 'nullable|numeric|min:0.001',
            'condition'    => 'required|in:returned,lost,damaged',
            'warehouse_id' => 'nullable|integer|min:1',
            'notes'        => 'nullable|string|max:500',
        ]);

        return response()->json($this->ppe->returnIssue($issue, $data, $request->user()));
    }

    /** §17 — atomically replace worn-out kit: close this issue, draw fresh stock. */
    public function replaceIssue(Request $request, TpvWorkerPpeIssue $issue)
    {
        $this->assertTenant($request, $issue);

        $data = $request->validate([
            'qty'          => 'nullable|numeric|min:0.001',
            'size'         => 'nullable|string|max:40',
            'project'      => 'nullable|string|max:160',
            'site'         => 'nullable|string|max:160',
            'warehouse_id' => 'nullable|integer|min:1',
            'notes'        => 'nullable|string|max:500',
        ]);

        return response()->json($this->ppe->replaceIssue($issue, $data, $request->user()), 201);
    }

    /** §17 — mark a consumable issue as used (spent on site). */
    public function markUsed(Request $request, TpvWorkerPpeIssue $issue)
    {
        $this->assertTenant($request, $issue);

        $data = $request->validate([
            'notes' => 'nullable|string|max:500',
        ]);

        return response()->json($this->ppe->markUsed($issue, $data, $request->user()));
    }

    /** 404 rather than 403 — the codebase hides other tenants' records. */
    private function assertTenant(Request $request, $model): void
    {
        abort_unless(
            (int) $model->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Not found'
        );
    }
}

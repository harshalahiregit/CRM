<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Attribute;
use App\Services\Inventory\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inventory Settings (blueprint §10). One generic controller for all six lookup
 * sections — reads are open to internal staff (the Item form needs them), writes
 * are admin-only master data.
 */
class SettingsController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private SettingsService $settings)
    {
    }

    /** Everything at once — one request behind the Item form's dropdowns. */
    public function all(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->settings->all($request->user()->tenant_id), 'Settings retrieved');
    }

    public function index(Request $request, string $kind)
    {
        $this->denyExternal($request);
        $this->settings->assertKind($kind);

        return $this->success(
            $this->settings->list($kind, $request->user()->tenant_id, $request->query('kind')),
            'Settings retrieved'
        );
    }

    /** Sub-groups for a group — the dependent dropdown. */
    public function subgroups(Request $request, int $group)
    {
        $this->denyExternal($request);

        return $this->success($this->settings->subgroupsFor($group, $request->user()->tenant_id), 'Sub-groups retrieved');
    }

    public function store(Request $request, string $kind)
    {
        $this->requireAdmin($request, 'change inventory settings');
        $this->settings->assertKind($kind);
        $data = $request->validate($this->rules($kind, $request));

        return $this->success($this->settings->create($kind, $data, $request->user()->tenant_id), 'Created', 201);
    }

    public function update(Request $request, string $kind, int $id)
    {
        $this->requireAdmin($request, 'change inventory settings');
        $this->settings->assertKind($kind);
        $data = $request->validate($this->rules($kind, $request));

        return $this->success($this->settings->update($kind, $id, $data, $request->user()->tenant_id), 'Updated');
    }

    public function destroy(Request $request, string $kind, int $id)
    {
        $this->requireAdmin($request, 'change inventory settings');
        $this->settings->assertKind($kind);
        $this->settings->delete($kind, $id, $request->user()->tenant_id);

        return $this->success(null, 'Deleted');
    }

    public function reorder(Request $request, string $kind)
    {
        $this->requireAdmin($request, 'change inventory settings');
        $this->settings->assertKind($kind);
        $data = $request->validate(['ordered_ids' => 'present|array|max:500', 'ordered_ids.*' => 'integer|min:1']);

        return $this->success(
            ['reordered' => $this->settings->reorder($kind, $data['ordered_ids'], $request->user()->tenant_id)],
            'Order saved'
        );
    }

    /** Per-section validation — they share a shape but not their extra columns. */
    private function rules(string $kind, Request $request): array
    {
        $tenantId = $request->user()->tenant_id;

        $base = ['name' => 'required|string|max:120', 'order' => 'nullable|integer|min:0'];

        return match ($kind) {
            'units'      => $base + ['short_name' => 'nullable|string|max:20'],
            'taxes'      => $base + ['rate' => 'required|numeric|min:0|max:100'],
            'subgroups'  => $base + ['group_id' => ['required', 'integer', Rule::exists('inventory_groups', 'id')->where('tenant_id', $tenantId)]],
            'attributes' => $base + [
                'kind'  => ['required', Rule::in(Attribute::KINDS)],
                'value' => 'nullable|string|max:60',
            ],
            default => $base,
        };
    }
}

<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\CustomField;
use App\Services\Inventory\ConfigService;
use Illuminate\Http\Request;

/**
 * The Settings tabs that hold configuration rather than lookup lists
 * (blueprint §9): inventory rules, approvals, min/max defaults, sale-price
 * rule — plus custom field definitions and "Reset data".
 *
 * Reading config is open to any internal user (the Item form needs the
 * defaults); changing it is admin-only, because these values decide how the
 * whole module behaves.
 */
class ConfigController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private ConfigService $config)
    {
    }

    /* ── Config ─────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->config->all($request->user()->tenant_id), 'Settings retrieved');
    }

    public function update(Request $request)
    {
        $this->requireAdmin($request, 'change inventory settings');

        $data = $request->validate([
            'settings' => 'required|array',
        ]);

        return $this->success(
            $this->config->save($request->user()->tenant_id, $data['settings']),
            'Settings saved'
        );
    }

    /* ── Custom fields ──────────────────────────────────────────── */

    public function fields(Request $request)
    {
        $this->denyExternal($request);
        $entity = $request->query('entity');

        return $this->success(
            $this->config->customFields($request->user()->tenant_id, $entity ?: null),
            'Custom fields retrieved'
        );
    }

    public function storeField(Request $request)
    {
        $this->requireAdmin($request, 'add custom fields');

        $data = $request->validate([
            'entity'    => ['nullable', 'string', 'in:'.implode(',', CustomField::ENTITIES)],
            'label'     => 'required|string|max:120',
            'type'      => ['nullable', 'string', 'in:'.implode(',', CustomField::TYPES)],
            'options'   => 'nullable|array|max:50',
            'options.*' => 'string|max:120',
            'required'  => 'nullable|boolean',
            'order'     => 'nullable|integer|min:0',
        ]);

        return $this->success(
            $this->config->createCustomField($request->user()->tenant_id, $data),
            'Custom field created',
            201
        );
    }

    public function updateField(Request $request, int $field)
    {
        $this->requireAdmin($request, 'change custom fields');

        $data = $request->validate([
            'label'     => 'nullable|string|max:120',
            'type'      => ['nullable', 'string', 'in:'.implode(',', CustomField::TYPES)],
            'options'   => 'nullable|array|max:50',
            'options.*' => 'string|max:120',
            'required'  => 'nullable|boolean',
            'order'     => 'nullable|integer|min:0',
        ]);

        return $this->success(
            $this->config->updateCustomField($request->user()->tenant_id, $field, array_filter($data, fn ($v) => $v !== null)),
            'Custom field updated'
        );
    }

    public function destroyField(Request $request, int $field)
    {
        $this->requireAdmin($request, 'delete custom fields');
        $this->config->deleteCustomField($request->user()->tenant_id, $field);

        return $this->success(null, 'Custom field deleted');
    }

    /* ── Reset data ─────────────────────────────────────────────── */

    /**
     * Destroys inventory data. Guarded three ways: admin only, the caller must
     * name each scope, and they must type the literal confirmation word — this
     * is the one action in the module that cannot be undone.
     */
    public function reset(Request $request)
    {
        $this->requireAdmin($request, 'reset inventory data');

        $data = $request->validate([
            'scopes'   => 'required|array|min:1',
            'scopes.*' => 'string|in:movements,vouchers,products,custom_fields,config',
            'confirm'  => 'required|string',
        ]);

        abort_unless($data['confirm'] === 'RESET', 422, 'Type RESET to confirm.');

        return $this->success(
            $this->config->resetData($request->user()->tenant_id, $data['scopes']),
            'Inventory data reset'
        );
    }
}

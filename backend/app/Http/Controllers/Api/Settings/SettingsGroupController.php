<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateSettingsGroupRequest;
use App\Services\Settings\SettingsService;
use App\Support\Settings\SettingRegistry;
use Illuminate\Http\Request;

/**
 * Generic settings-group endpoint — one controller serves any registry group
 * (currently upload / security / notifications, gated by the route constraint).
 * Reuses SettingsService + SettingRegistry (no parallel logic); admin-only,
 * tenant-scoped, cached. New groups need only a registry entry + the route
 * constraint — no controller changes.
 */
class SettingsGroupController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function show(Request $request, string $group)
    {
        abort_unless(SettingRegistry::groupExists($group), 404, 'Unknown settings group');

        return response()->json([
            'group'  => $group,
            'values' => $this->settings->getGroup($request->user()->tenant_id, $group),
        ]);
    }

    public function update(UpdateSettingsGroupRequest $request, string $group)
    {
        abort_unless(SettingRegistry::groupExists($group), 404, 'Unknown settings group');

        $tenantId = $request->user()->tenant_id;
        $this->settings->setGroup($tenantId, $group, $request->validated()['values'] ?? []);

        return response()->json([
            'group'  => $group,
            'values' => $this->settings->getGroup($tenantId, $group),
        ]);
    }
}

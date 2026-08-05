<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateGeneralSettingsRequest;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;

/**
 * General & Branding settings — the first consumer of the generic tenant settings
 * store (SettingsService + SettingRegistry). Admin-only, tenant-scoped; adds new
 * endpoints without touching existing settings routes/APIs.
 */
class GeneralSettingController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($request->user()->tenant_id));
    }

    public function update(UpdateGeneralSettingsRequest $request)
    {
        $tenantId = $request->user()->tenant_id;
        $data = $request->validated();

        if (! empty($data['general'])) {
            $this->settings->setGroup($tenantId, 'general', $data['general']);
        }
        if (! empty($data['branding'])) {
            $this->settings->setGroup($tenantId, 'branding', $data['branding']);
        }

        return response()->json($this->payload($tenantId));
    }

    private function payload(int $tenantId): array
    {
        return [
            'general'  => $this->settings->getGroup($tenantId, 'general'),
            'branding' => $this->settings->getGroup($tenantId, 'branding'),
        ];
    }
}

<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsFormatter;
use Illuminate\Http\Request;

/**
 * The tenant's resolved formatting contract (currency + localization) plus live
 * samples. Any consumer — React, PDF renderers, email templates, exports — reads
 * this instead of hardcoding a currency symbol or date pattern, so every surface
 * formats identically to the backend's SettingsFormatter.
 *
 * Read-only; available to any authenticated user of the tenant (not admin-only),
 * because every screen needs it to render amounts and dates.
 */
class FormatSettingController extends Controller
{
    public function __construct(private SettingsFormatter $formatter)
    {
    }

    public function show(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        return response()->json([
            'currency'     => $this->formatter->currencyMeta($tenantId),
            'localization' => $this->formatter->localizationMeta($tenantId),
            'samples'      => [
                'money'          => $this->formatter->money($tenantId, 1234567.891),
                'money_negative' => $this->formatter->money($tenantId, -1234.5),
                'number'         => $this->formatter->number($tenantId, 1234567.891),
                'date'           => $this->formatter->date($tenantId, now()),
                'time'           => $this->formatter->time($tenantId, now()),
                'date_time'      => $this->formatter->dateTime($tenantId, now()),
            ],
        ]);
    }
}

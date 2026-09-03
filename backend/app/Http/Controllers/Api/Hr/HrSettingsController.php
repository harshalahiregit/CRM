<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use App\Support\Settings\SettingRegistry;
use Illuminate\Http\Request;

/**
 * HR settings — the controls that used to be constants.
 *
 * The schema is sent with the values so the screen renders from what the server
 * actually enforces. A settings form with its own hardcoded field list is how a
 * setting ends up on screen that nothing reads, or read by something that is not
 * on screen.
 */
class HrSettingsController extends Controller
{
    public function __construct(private SettingsService $settings)
    {
    }

    public function index(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data'   => [
                'values' => $this->settings->getGroup((int) $request->user()->tenant_id, HrSetting::GROUP),
                'schema' => HrSetting::schema(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        // Rules come from the registry, so validation and storage cannot disagree
        // about what a setting is.
        $rules = [];
        foreach (HrSetting::keys() as $key) {
            $rules[$key] = SettingRegistry::meta(HrSetting::GROUP, $key)['rules'] ?? ['nullable'];
        }

        $data = $request->validate($rules);

        $values = $this->settings->setGroup(
            (int) $request->user()->tenant_id,
            HrSetting::GROUP,
            // Only the keys actually sent: a partial save must not reset the rest
            // to their defaults.
            array_intersect_key($data, array_flip(HrSetting::keys()))
        );

        return response()->json([
            'status'  => 'success',
            'message' => 'Settings saved.',
            'data'    => ['values' => $values, 'schema' => HrSetting::schema()],
        ]);
    }

    /**
     * The handful of settings an ordinary employee's screens need.
     *
     * Deliberately a short allowlist rather than the whole group: an employee has
     * no business reading the approval thresholds or the notification address,
     * and "send everything and hide some in the client" is not hiding.
     */
    public function forEmployee(Request $request)
    {
        $all = $this->settings->getGroup((int) $request->user()->tenant_id, HrSetting::GROUP);

        return response()->json([
            'status' => 'success',
            'data'   => array_intersect_key($all, array_flip([
                'company_start_time', 'company_end_time', 'standard_day_hours', 'half_day_hours',
                'allow_self_correction', 'correction_window_days',
            ])),
        ]);
    }
}

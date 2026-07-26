<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskWidgetService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin: manage the tenant's embeddable support widget (auth-scoped).
 */
class HelpdeskWidgetController extends Controller
{
    use ApiResponse;

    public function __construct(private HelpdeskWidgetService $widget)
    {
    }

    public function show(Request $request)
    {
        $settings = $this->widget->settingsFor($request->user()->tenant_id);

        return $this->success($this->present($settings), 'Widget settings retrieved');
    }

    public function update(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        $data = $request->validate([
            // BUG-07: previously 'nullable|string|max:255' — anything at all, so an
            // admin could save a regex, a partial host or a typo and get an
            // allowlist that silently never matched (or thought they had one when
            // the check was being skipped entirely).
            //
            // Contract: a comma-separated list of hostnames — "example.com" or
            // "example.com, www.example.com, support.example.co.uk". A list because
            // one widget is commonly embedded on several sites. A scheme or path is
            // tolerated on input ("https://example.com/help") and normalised away.
            //
            // Blank/null = the widget may be embedded anywhere. That is intentional
            // (a freshly-created widget has to work before anything is configured)
            // and is the state every existing row is in.
            'allowed_origin' => ['nullable', 'string', 'max:255', function ($attr, $value, $fail) {
                foreach (explode(',', (string) $value) as $entry) {
                    if (trim($entry) === '') {
                        continue;
                    }
                    $host = HelpdeskWidgetService::hostOf($entry);
                    if (! HelpdeskWidgetService::isValidHostname($host)) {
                        $fail("“".trim($entry)."” is not a valid site address. Enter one or more hostnames separated by commas, e.g. example.com, www.example.com. Wildcards and partial hosts are not supported. Leave this blank to allow the widget on any site.");

                        return;
                    }
                }
            }],
            // BUG-29: lets public submissions land in a chosen department instead of
            // relying on a tenant default that may never have been configured.
            // Tenant-scoped so one workspace can't route into another's department.
            'department_id'  => ['nullable', 'integer', Rule::exists('ticket_departments', 'id')
                ->where(fn ($q) => $q->where('tenant_id', $tenantId))],
            'is_enabled'     => 'boolean',
        ]);

        $settings = $this->widget->updateSettings($tenantId, $data);

        return $this->success($this->present($settings), 'Widget settings updated');
    }

    public function rotate(Request $request)
    {
        $settings = $this->widget->rotateKey($request->user()->tenant_id);

        return $this->success($this->present($settings), 'Widget key rotated');
    }

    /** Attach the ready-to-paste embed snippet. */
    private function present($settings): array
    {
        $submitUrl = url("/api/helpdesk/public/widget/{$settings->public_key}/tickets");

        return [
            'public_key'     => $settings->public_key,
            'allowed_origin' => $settings->allowed_origin,
            'department_id'  => $settings->department_id,
            'is_enabled'     => $settings->is_enabled,
            'submit_url'     => $submitUrl,
            'embed_snippet'  => "<script src=\"".url('/widget.js')."\" data-key=\"{$settings->public_key}\" async></script>",
        ];
    }
}

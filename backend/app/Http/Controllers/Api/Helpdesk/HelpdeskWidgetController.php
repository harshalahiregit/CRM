<?php

namespace App\Http\Controllers\Api\Helpdesk;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Services\Helpdesk\HelpdeskWidgetService;
use Illuminate\Http\Request;

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
        $data = $request->validate([
            'allowed_origin' => 'nullable|string|max:255',
            'is_enabled'     => 'boolean',
        ]);
        $settings = $this->widget->updateSettings($request->user()->tenant_id, $data);

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
            'is_enabled'     => $settings->is_enabled,
            'submit_url'     => $submitUrl,
            'embed_snippet'  => "<script src=\"".url('/widget.js')."\" data-key=\"{$settings->public_key}\" async></script>",
        ];
    }
}

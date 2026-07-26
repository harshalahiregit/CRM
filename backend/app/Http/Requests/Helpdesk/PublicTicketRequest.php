<?php

namespace App\Http\Requests\Helpdesk;

use App\Models\Helpdesk\HelpdeskWidgetSetting;
use App\Services\Helpdesk\HelpdeskSettingsService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'subject'  => 'required|string|max:255',
            'message'  => 'required|string|max:5000',
            // BUG-15: this used to be a hardcoded in:low,medium,high,urgent while
            // every other ticket path validates against the tenant's configured
            // priority list — rename a priority in Support Settings and the widget
            // started rejecting its own values. Now validated against the same list.
            'priority' => ['nullable', 'string', Rule::in($this->tenantPriorityNames())],
            // Honeypot: a hidden field real users never fill. Bots do → reject.
            'hp'       => 'prohibited',
        ];
    }

    /**
     * The tenant's configured priority names, resolved from the widget key in the
     * route (POST /helpdesk/public/widget/{key}/tickets).
     *
     * This is a public, unauthenticated route, so there is no $this->user() to
     * scope by — the public key IS the tenant identifier, exactly as it is in
     * HelpdeskWidgetService::submit(). Resolving it here is cheap (one indexed
     * lookup on a unique column) and keeps validation where the rest of the
     * ticket FormRequests keep it.
     *
     * An unknown or disabled key deliberately yields an empty list: Rule::in([])
     * would reject every value, so we return the default set instead and let
     * submit() raise the single authoritative 403 ("widget is not active"). That
     * way a bad key never leaks tenant existence through a validation error.
     *
     * @return array<string>
     */
    private function tenantPriorityNames(): array
    {
        $key = (string) $this->route('key');

        $tenantId = $key === '' ? null : HelpdeskWidgetSetting::where('public_key', $key)
            ->where('is_enabled', true)
            ->value('tenant_id');

        if (! $tenantId) {
            return ['low', 'medium', 'high', 'urgent'];
        }

        return app(HelpdeskSettingsService::class)->priorityNames((int) $tenantId);
    }
}

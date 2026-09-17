<?php

namespace Sire\Http\Requests;

use Sire\Services\SireDashboardService;
use Sire\Support\SirePriority;
use Sire\Support\SireStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The dashboard filter allowlist, enforced at the edge.
 *
 * validated() returns ONLY these keys, so SireDashboardService cannot be handed a
 * parameter it does not expect even if the client invents one.
 */
class DashboardFilterRequest extends FormRequest
{
    public function rules(): array
    {
        $tenantId = (int) $this->user()->tenant_id;

        return [
            'scope' => ['nullable', Rule::in(SireDashboardService::SCOPES)],

            // Accepted so a mismatch is a hard 404 rather than a silent ignore.
            // See the note in SireDashboardService::applyFilters().
            'tenant_id' => ['nullable', 'integer'],

            // One module or several. The register filters to one at a time; the
            // export is usually "give me Sales and Inventory", because that is
            // how somebody decides what to spend an afternoon on.
            'module'      => ['nullable'],
            'module.*'    => ['string', 'max:64'],

            // Export only. The register pages; a brief does not, so it needs a
            // ceiling of its own or one call can try to render the whole history.
            'limit'       => ['nullable', 'integer', 'min:1', 'max:500'],
            'type'        => ['nullable', 'string', 'max:48'],
            'severity_id' => ['nullable', 'integer', Rule::exists('sire_severities', 'id')->where('tenant_id', $tenantId)],
            'assignee_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('tenant_id', $tenantId)],

            // Comma lists; individual values are intersected with the allowed set
            // in the service, so an invalid entry narrows rather than widens.
            'priority' => ['nullable', 'string', 'max:32'],
            'status'   => ['nullable', 'string', 'max:512'],

            'date_from' => ['nullable', 'date'],
            'date_to'   => ['nullable', 'date', 'after_or_equal:date_from'],

            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ];
    }

    public function messages(): array
    {
        return [
            'severity_id.exists' => 'That severity does not belong to your workspace.',
            'assignee_id.exists' => 'That user does not belong to your workspace.',
        ];
    }

    /** Only the filter keys, never `scope` or `per_page`. */
    public function filters(): array
    {
        return array_intersect_key($this->validated(), array_flip([
            'tenant_id', 'module', 'type', 'severity_id', 'priority',
            'status', 'assignee_id', 'date_from', 'date_to',
        ]));
    }
}

<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\Client;

use App\Models\Customer\ClientActivity;
use Illuminate\Validation\Rule;

/**
 * §4 — Activities.
 *
 * `type` and `outcome` validate against the model's lists because these drive
 * the timeline's icons and Health's engagement signal; a free-text type would
 * quietly become an unclassified row nothing can group.
 */
class ClientActivityController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'activities';
    }

    protected function htmlFields(): array
    {
        return ['summary'];
    }

    protected function rules(Client $client): array
    {
        return [
            // Must be a contact of THIS customer — an unscoped exists() accepts any
            // contact in the database, including another tenant's.
            'client_contact_id' => ['nullable', 'integer',
                Rule::exists('client_contacts', 'id')
                    ->where('tenant_id', $client->tenant_id)
                    ->where('client_id', $client->id)],
            'type'              => ['required', Rule::in(ClientActivity::TYPES)],
            'direction'         => ['nullable', Rule::in(ClientActivity::DIRECTIONS)],
            'subject'           => 'required|string|max:255',
            'summary'           => 'nullable|string',
            'outcome'           => ['nullable', Rule::in(ClientActivity::OUTCOMES)],
            'occurred_at'       => 'required|date',
            'duration_minutes'  => 'nullable|integer|min:0|max:1440',
            'follow_up_on'      => 'nullable|date',
            'follow_up_done'    => 'boolean',
        ];
    }
}

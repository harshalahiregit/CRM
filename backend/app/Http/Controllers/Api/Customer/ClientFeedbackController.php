<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\Client;

use App\Models\Customer\ClientFeedback;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * §10 — Customer Experience (CSAT / NPS).
 *
 * The two metrics use different scales, so the score bound depends on which one
 * is being submitted. Validating against a fixed 0-10 would silently accept a
 * CSAT of 9 on a five-point scale and skew every average built on it.
 */
class ClientFeedbackController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'feedback';
    }

    protected function rules(Client $client): array
    {
        // Bound the score by the metric actually being submitted.
        $metric = request()->input('metric');
        $max    = ClientFeedback::MAX[$metric] ?? 10;

        return [
            // Scoped to this customer: attributing a survey response to another
            // company's contact would be silently wrong on every report.
            'client_contact_id' => ['nullable', 'integer',
                Rule::exists('client_contacts', 'id')
                    ->where('tenant_id', $client->tenant_id)
                    ->where('client_id', $client->id)],
            'metric'            => ['required', Rule::in(ClientFeedback::METRICS)],
            'score'             => "required|integer|min:0|max:{$max}",
            'comments'          => 'nullable|string',
            'collected_via'     => ['nullable', Rule::in(['portal', 'staff', 'email'])],
            'responded_at'      => 'required|date',
        ];
    }

    /** Averages alongside the rows — every screen showing the list wants them. */
    public function index(\App\Models\Customer\Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        $rows = $client->feedback()->get();

        return response()->json([
            'rows'    => $rows,
            'summary' => app(\App\Services\Customer\CustomerExperienceService::class)->summarise($rows),
        ]);
    }
}

<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\ClientComplaint;
use Illuminate\Validation\Rule;

/**
 * §17 SERVICE — Complaints and Escalations.
 *
 * `resolved_at` is accepted from the caller rather than stamped on a status
 * change, because complaints are routinely logged after the fact: someone
 * records a complaint that was raised and settled last week, and a server
 * timestamp would say it was resolved today.
 */
class ClientComplaintController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'complaints';
    }

    protected function htmlFields(): array
    {
        return ['description', 'resolution'];
    }

    protected function rules(): array
    {
        return [
            'reference'   => 'nullable|string|max:40',
            'kind'        => ['required', Rule::in(ClientComplaint::KINDS)],
            'subject'     => 'required|string|max:255',
            'description' => 'nullable|string',
            'category'    => ['nullable', Rule::in(ClientComplaint::CATEGORIES)],
            'severity'    => ['nullable', Rule::in(ClientComplaint::SEVERITIES)],
            'status'      => ['nullable', Rule::in(ClientComplaint::STATUSES)],
            'owner_id'    => 'nullable|integer|exists:users,id',
            'raised_at'   => 'required|date',
            'resolved_at' => 'nullable|date|after_or_equal:raised_at',
            'resolution'  => 'nullable|string',
        ];
    }
}

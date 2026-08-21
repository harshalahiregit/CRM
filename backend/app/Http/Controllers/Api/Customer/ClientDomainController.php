<?php

namespace App\Http\Controllers\Api\Customer;

use App\Models\Customer\ClientDomain;
use Illuminate\Validation\Rule;

/**
 * Domain Manager (§1, §17 ADMIN).
 *
 * The domain itself is validated loosely on purpose. Customers hand over
 * internal hostnames and punycode names that a strict public-suffix check
 * rejects, and refusing to record a domain we are being asked to watch is worse
 * than recording an odd-looking one.
 */
class ClientDomainController extends AbstractClientRecordController
{
    protected function relation(): string
    {
        return 'domains';
    }

    protected function rules(): array
    {
        return [
            'domain'           => 'required|string|max:255',
            'registrar'        => 'nullable|string|max:255',
            'registered_on'    => 'nullable|date',
            'expires_on'       => 'nullable|date',
            'auto_renew'       => 'boolean',
            'dns_provider'     => 'nullable|string|max:255',
            'hosting_provider' => 'nullable|string|max:255',
            'ssl_expires_on'   => 'nullable|date',
            'status'           => ['nullable', Rule::in(ClientDomain::STATUSES)],
            'notes'            => 'nullable|string',
        ];
    }
}

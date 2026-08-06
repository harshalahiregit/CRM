<?php

namespace App\Services\Hr\Publishing;

/**
 * Review comment #13 — "Trulytalents – not connected for job post".
 *
 * All the HTTP behaviour now lives in RestJobBoardChannel, which was extracted
 * from THIS class when #12 added LinkedIn, Naukri and Indeed: three more boards
 * speaking the same REST shape did not warrant three more copies of it.
 *
 * Everything that makes TrulyTalents TrulyTalents — endpoint, key, paths,
 * response shape — is config (`config/hr_publishing.trulytalents`), so a change
 * of API version or account is a deploy of new env, not new PHP.
 */
class TrulyTalentsChannel extends RestJobBoardChannel
{
    public function key(): string
    {
        return 'trulytalents';
    }

    public function label(): string
    {
        return 'TrulyTalents';
    }
}

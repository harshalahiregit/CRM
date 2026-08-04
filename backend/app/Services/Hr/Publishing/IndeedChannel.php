<?php

namespace App\Services\Hr\Publishing;

/**
 * #12 — Indeed job distribution.
 *
 * Endpoint, credentials, paths and response shape are all
 * `config/hr_publishing.indeed`. Left unconfigured the channel reports itself as
 * such and a publish attempt is recorded as `failed` with that reason — never a
 * silent success against a board the job never reached.
 */
class IndeedChannel extends RestJobBoardChannel
{
    public function key(): string
    {
        return 'indeed';
    }

    public function label(): string
    {
        return 'Indeed';
    }
}

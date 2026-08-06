<?php

namespace App\Services\Hr\Publishing;

/**
 * #12 — Naukri job distribution.
 *
 * Endpoint, credentials, paths and response shape are all
 * `config/hr_publishing.naukri`. Left unconfigured the channel reports itself as
 * such and a publish attempt is recorded as `failed` with that reason — never a
 * silent success against a board the job never reached.
 */
class NaukriChannel extends RestJobBoardChannel
{
    public function key(): string
    {
        return 'naukri';
    }

    public function label(): string
    {
        return 'Naukri';
    }
}

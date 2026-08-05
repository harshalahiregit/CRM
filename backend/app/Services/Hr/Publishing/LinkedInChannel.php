<?php

namespace App\Services\Hr\Publishing;

/**
 * #12 — LinkedIn job distribution.
 *
 * LinkedIn's Job Posting API names its fields differently from ours and reports
 * status with its own vocabulary ("LISTED", "CLOSED"). Both are handled in
 * `config/hr_publishing.linkedin` via `field_map` and `status_map` rather than by
 * overriding anything here — which is the point of the shared base.
 */
class LinkedInChannel extends RestJobBoardChannel
{
    public function key(): string
    {
        return 'linkedin';
    }

    public function label(): string
    {
        return 'LinkedIn';
    }
}

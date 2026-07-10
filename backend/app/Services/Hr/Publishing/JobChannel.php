<?php

namespace App\Services\Hr\Publishing;

use App\Models\Hr\HrJobPosting;

/**
 * Contract every job distribution channel implements (Career Portal today;
 * LinkedIn / Naukri / Indeed / TrulyTalents as future integrations).
 *
 * Implementations are pure "push to the channel" adapters — the surrounding
 * JobPublishingService handles the publication ledger, tenant/role guards and
 * audit logging, so a new channel only has to talk to its own platform.
 */
interface JobChannel
{
    public function key(): string;

    public function label(): string;

    /**
     * Publish the posting to the channel.
     *
     * @return array{external_ref?:?string, external_url?:?string, meta?:array}
     */
    public function publish(HrJobPosting $job): array;

    /** Remove the posting from the channel. */
    public function unpublish(HrJobPosting $job): void;
}

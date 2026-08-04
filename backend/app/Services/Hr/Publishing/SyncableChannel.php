<?php

namespace App\Services\Hr\Publishing;

use App\Models\Hr\HrJobPosting;

/**
 * A channel that can be asked what it currently thinks of a posting.
 *
 * Deliberately SEPARATE from JobChannel rather than another method on it. Not
 * every channel has a remote status to report — the Career Portal *is* the local
 * database, so "ask it for status" would mean asking ourselves — and a required
 * method would force every future channel to implement a meaningless stub.
 *
 * JobPublishingService checks `instanceof` and refuses cleanly when a channel
 * cannot answer, so adding LinkedIn/Naukri/Indeed later is still: write the class,
 * register it, and implement this interface only if the platform supports it.
 */
interface SyncableChannel
{
    /**
     * The channel's current view of a posting.
     *
     * @param  string  $externalRef  the reference stored when it was published
     * @return array{status:string, external_url?:?string, meta?:array}
     *         `status` is one of: published | removed | expired | unknown
     */
    public function syncStatus(HrJobPosting $job, string $externalRef): array;
}

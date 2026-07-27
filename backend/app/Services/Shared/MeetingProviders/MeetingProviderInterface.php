<?php

namespace App\Services\Shared\MeetingProviders;

/**
 * Contract that every meeting provider driver must satisfy.
 *
 * @return array{
 *   link:      string,
 *   id:        string|null,
 *   passcode:  string|null,
 *   host_link: string|null,
 *   platform:  string,
 * }
 */
interface MeetingProviderInterface
{
    /**
     * Create an online meeting and return the resulting data.
     *
     * @param  array{
     *   title:        string,
     *   scheduled_at: string,   // ISO-8601
     *   duration_min: int,
     * } $data
     */
    public function create(array $data): array;
}

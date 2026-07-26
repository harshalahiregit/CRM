<?php

namespace App\Services\Shared\MeetingProviders;

use InvalidArgumentException;

/**
 * Factory — resolves a provider driver from the platform key.
 *
 * Resolution order:
 *  1. The $platform argument passed by the controller (user's choice per meeting).
 *  2. Falls back to config('meeting.provider') if platform is null.
 *  3. Falls back to StubProvider if the key is unrecognised.
 */
class MeetingProviderFactory
{
    /**
     * @param  string|null $platform  'google_meet' | 'zoom' | 'teams' | 'stub' | null
     */
    public static function make(?string $platform = null): MeetingProviderInterface
    {
        $key = $platform ?? config('meeting.provider', 'stub');

        return match ($key) {
            'google_meet' => new GoogleMeetProvider(),
            'zoom'        => new ZoomProvider(),
            'teams'       => new TeamsProvider(),
            default       => new StubProvider(),
        };
    }
}

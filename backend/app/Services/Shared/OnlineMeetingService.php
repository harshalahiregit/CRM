<?php

namespace App\Services\Shared;

use App\Models\Shared\KickoffMeeting;
use App\Services\Shared\MeetingProviders\MeetingProviderFactory;
use Illuminate\Support\Facades\Log;

/**
 * Thin orchestrator: creates an online meeting via the configured/requested
 * provider, then persists the result onto the KickoffMeeting record.
 *
 * This service is intentionally stateless — it does no routing, no auth, and
 * no notification logic. Those concerns live in the controller / existing
 * notification jobs.
 */
class OnlineMeetingService
{
    /**
     * Generate an online meeting for the given kickoff meeting record.
     *
     * @param  KickoffMeeting  $meeting
     * @param  string|null     $platform   'google_meet' | 'zoom' | 'teams' | 'stub' | null (uses config default)
     * @return array{link: string, id: string|null, passcode: string|null, host_link: string|null, platform: string}
     *
     * @throws \RuntimeException  When the provider call fails.
     */
    public function createMeeting(KickoffMeeting $meeting, ?string $platform = null): array
    {
        $provider = MeetingProviderFactory::make($platform);

        $result = $provider->create([
            'title'        => $meeting->title ?? "Kickoff Meeting #{$meeting->id}",
            'scheduled_at' => $meeting->scheduled_at?->toIso8601String() ?? now()->toIso8601String(),
            'duration_min' => $meeting->duration_minutes ?? 60,
        ]);

        // Persist the platform link back to the meeting record
        $meeting->update([
            'meeting_platform' => $result['platform'],
            'meeting_link'     => $result['link'],
            'meeting_id'       => $result['id'],
            'meeting_passcode' => $result['passcode'],
            'meeting_host_link'=> $result['host_link'],
        ]);

        Log::info('OnlineMeetingService: meeting link generated', [
            'kickoff_meeting_id' => $meeting->id,
            'platform'           => $result['platform'],
            'link'               => $result['link'],
        ]);

        return $result;
    }

    /**
     * Return the stored online-meeting data for a meeting, or null if none.
     */
    public function getLinkData(KickoffMeeting $meeting): ?array
    {
        if (! $meeting->meeting_platform) {
            return null;
        }

        return [
            'platform'  => $meeting->meeting_platform,
            'link'      => $meeting->meeting_link,
            'id'        => $meeting->meeting_id,
            'passcode'  => $meeting->meeting_passcode,
            'host_link' => $meeting->meeting_host_link,
        ];
    }
}

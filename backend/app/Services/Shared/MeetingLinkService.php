<?php

namespace App\Services\Shared;

use App\Services\Shared\MeetingProviders\MeetingProviderFactory;
use Illuminate\Support\Facades\Log;

/**
 * Ad-hoc meeting links for the message composers (owner: Shivam).
 *
 * The composer's "Meeting" button needs a link RIGHT NOW, not a scheduled
 * KickoffMeeting record — so this sits beside OnlineMeetingService rather than
 * reusing it. It always returns a REAL, usable link:
 *
 *  - Jitsi        — a fresh room on the free meet.jit.si; no credentials, ever.
 *  - Google Meet  — a real scheduled Meet link when the tenant has the Google
 *                   integration configured; otherwise meet.google.com/new, the
 *                   genuine "start a new meeting" URL.
 *  - Zoom         — a real join link when Zoom S2S OAuth is configured;
 *                   otherwise zoom.us/start, Zoom's own instant-meeting starter.
 *
 * It never hands back the StubProvider's fake example.com link — an unusable
 * link in a real message would be worse than the honest instant-start URL.
 */
class MeetingLinkService
{
    public const PLATFORMS = ['google_meet', 'zoom', 'jitsi'];

    /** @return array{platform:string,label:string,link:string,instant:bool} */
    public function forPlatform(string $platform, int $tenantId, string $title = 'CRM meeting'): array
    {
        return match ($platform) {
            'jitsi'       => $this->jitsi($tenantId),
            'google_meet' => $this->viaProviderOrFallback('google_meet', 'Google Meet', 'https://meet.google.com/new', $title, fn () => (bool) config('meeting.google_meet.credentials_path')),
            'zoom'        => $this->viaProviderOrFallback('zoom', 'Zoom', 'https://zoom.us/start/videomeeting', $title, fn () => (bool) config('meeting.zoom.account_id')),
            default       => throw new \InvalidArgumentException('Unsupported meeting platform.'),
        };
    }

    private function jitsi(int $tenantId): array
    {
        $room = 'CRM-' . $tenantId . '-' . bin2hex(random_bytes(4));

        return ['platform' => 'jitsi', 'label' => 'Jitsi', 'link' => "https://meet.jit.si/{$room}", 'instant' => false];
    }

    /**
     * Mint a real link through the configured provider; if it isn't configured
     * (or the API call fails), fall back to the platform's instant-start URL so
     * the button always produces something that actually opens a meeting.
     */
    private function viaProviderOrFallback(string $platform, string $label, string $fallback, string $title, callable $isConfigured): array
    {
        if ($isConfigured()) {
            try {
                $result = MeetingProviderFactory::make($platform)->create([
                    'title'        => $title,
                    'scheduled_at' => now()->toIso8601String(),
                    'duration_min' => 60,
                ]);
                if (! empty($result['link'])) {
                    return ['platform' => $platform, 'label' => $label, 'link' => $result['link'], 'instant' => false];
                }
            } catch (\Throwable $e) {
                Log::warning('MeetingLinkService: provider failed, using instant fallback', ['platform' => $platform, 'error' => $e->getMessage()]);
            }
        }

        return ['platform' => $platform, 'label' => $label, 'link' => $fallback, 'instant' => true];
    }
}

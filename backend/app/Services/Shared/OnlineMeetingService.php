<?php

namespace App\Services\Shared;

use App\Services\Shared\MeetingProviders\MeetingProviderFactory;
use App\Support\Shared\JitsiHost;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Mints an online-meeting link for a scheduled meeting and stores it on the
 * record.
 *
 * Two things this had to learn:
 *
 *  1. **It serves both meeting engines.** The shared engine (kickoff_meetings)
 *     and Purchase's (purchase_kickoff_meetings) carry the same five columns —
 *     platform, link, id, passcode, host link — so one service writes to either.
 *     It used to be typed to the shared model alone, which is why a Purchase
 *     meeting's "generate link" call went to the shared route and came back
 *     "No query results for model [KickoffMeeting]".
 *
 *  2. **The link it returns has to actually open a meeting.** When a provider
 *     is not configured the old behaviour was either a 422 (Zoom and Meet throw
 *     without credentials) or the StubProvider's https://meet.example.com/…,
 *     which is not a meeting at all. Now an unconfigured platform falls back to
 *     that platform's own instant-start URL — meet.google.com/new,
 *     zoom.us/start — and Jitsi is always a real room needing no credentials.
 *     A person clicking Join gets a meeting either way; what changes with
 *     credentials is whether it was scheduled through the provider's API.
 */
class OnlineMeetingService
{
    /**
     * Platforms offered in the UI. Jitsi is first among the credential-free
     * options because it is the only one that yields a genuine, unique room
     * with no configuration at all.
     */
    public const PLATFORMS = ['jitsi', 'google_meet', 'zoom', 'teams'];

    /**
     * What a REQUEST may carry, which is wider than what we offer.
     *
     * 'stub' was the old generic option and is still stored on every meeting
     * created before Jitsi existed here — and the detail page sends the stored
     * platform back when you press Generate. Rejecting it would 422 every one
     * of those meetings, so it is accepted and normalised to Jitsi, which is
     * what "generic link" was always pretending to be.
     */
    public const ACCEPTED = ['jitsi', 'google_meet', 'zoom', 'teams', 'stub'];

    public const LABELS = [
        'jitsi'       => 'Jitsi Meet',
        'google_meet' => 'Google Meet',
        'zoom'        => 'Zoom',
        'teams'       => 'Microsoft Teams',
    ];

    /**
     * Generate (or regenerate) the meeting link and persist it.
     *
     * @param  Model        $meeting   a KickoffMeeting or PurchaseKickoffMeeting
     * @param  string|null  $platform  null falls back to the meeting's own
     *                                 platform, then to the configured default
     * @return array{link:string,id:string|null,passcode:string|null,host_link:string|null,platform:string,instant:bool}
     */
    public function createMeeting(Model $meeting, ?string $platform = null): array
    {
        $platform = $this->resolvePlatform($meeting, $platform);
        $result   = $this->mint($platform, $this->titleFor($meeting), $meeting);

        $meeting->forceFill([
            'meeting_platform'  => $result['platform'],
            'meeting_link'      => $result['link'],
            'meeting_id'        => $result['id'],
            'meeting_passcode'  => $result['passcode'],
            'meeting_host_link' => $result['host_link'],
        ])->save();

        Log::info('OnlineMeetingService: meeting link generated', [
            'meeting'  => $meeting::class.'#'.$meeting->getKey(),
            'platform' => $result['platform'],
            'instant'  => $result['instant'],
        ]);

        return $result;
    }

    /** The stored link data for a meeting, or null when it has none. */
    public function getLinkData(Model $meeting): ?array
    {
        if (! $meeting->meeting_platform && ! $meeting->meeting_link) {
            return null;
        }

        return [
            'platform'  => $meeting->meeting_platform,
            'label'     => self::LABELS[$meeting->meeting_platform] ?? $meeting->meeting_platform,
            'link'      => $meeting->meeting_link,
            'id'        => $meeting->meeting_id,
            'passcode'  => $meeting->meeting_passcode,
            'host_link' => $meeting->meeting_host_link,
        ];
    }

    /**
     * Should this meeting have a link at all?
     *
     * An in-person meeting does not need one, and minting one anyway puts a
     * "Join online" button on an invitation to a site office.
     */
    public function wantsLink(Model $meeting): bool
    {
        $mode = strtolower((string) ($meeting->mode ?? ''));

        return $mode === '' || str_contains($mode, 'online') || str_contains($mode, 'virtual') || str_contains($mode, 'hybrid');
    }

    /* ── Internals ──────────────────────────────────────────────────────── */

    private function resolvePlatform(Model $meeting, ?string $platform): string
    {
        $chosen = $platform ?: ($meeting->meeting_platform ?: config('meeting.provider', 'jitsi'));

        // 'stub' is a test double, never something a person picked. Anyone who
        // lands on it gets Jitsi, which is a real room and costs nothing.
        return in_array($chosen, self::PLATFORMS, true) ? $chosen : 'jitsi';
    }

    private function titleFor(Model $meeting): string
    {
        return $meeting->title ?: 'Kickoff meeting #'.$meeting->getKey();
    }

    /**
     * Ask the provider for a real scheduled meeting; fall back to the
     * platform's instant-start URL when it is not configured or the call fails.
     */
    private function mint(string $platform, string $title, Model $meeting): array
    {
        if ($platform === 'jitsi') {
            // A fresh, unguessable room on the public Jitsi instance. No API,
            // no credentials, and the link works the moment it is created.
            $room = 'CRM-'.($meeting->tenant_id ?: 0).'-'.bin2hex(random_bytes(5));
            // The tenant's own server when they have set one, else the
            // deployment default. Resolved per tenant rather than read from
            // config, so an administrator can move off the public instance —
            // and its Google sign-in — without a redeploy.
            $host = JitsiHost::for($meeting->tenant_id ?? null);

            return [
                'platform' => 'jitsi', 'link' => "https://{$host}/{$room}",
                'id' => $room, 'passcode' => null, 'host_link' => null, 'instant' => false,
            ];
        }

        if ($this->isConfigured($platform)) {
            try {
                $result = MeetingProviderFactory::make($platform)->create([
                    'title'        => $title,
                    'scheduled_at' => optional($meeting->scheduled_at)->toIso8601String() ?? now()->toIso8601String(),
                    'duration_min' => $meeting->duration_minutes ?? 60,
                ]);

                if (! empty($result['link'])) {
                    return $result + ['instant' => false];
                }
            } catch (\Throwable $e) {
                // Never fail the meeting over this. A scheduled meeting with an
                // instant-start link is far better than a 422 and no link.
                Log::warning('OnlineMeetingService: provider failed, using instant-start link', [
                    'platform' => $platform, 'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'platform'  => $platform,
            'link'      => $this->instantStartUrl($platform),
            'id'        => null,
            'passcode'  => null,
            'host_link' => null,
            // The caller shows this as "start a new meeting" rather than
            // pretending a room was booked in advance.
            'instant'   => true,
        ];
    }

    private function isConfigured(string $platform): bool
    {
        return match ($platform) {
            'google_meet' => (bool) config('meeting.google_meet.credentials_path'),
            'zoom'        => (bool) config('meeting.zoom.account_id'),
            'teams'       => (bool) config('meeting.teams.tenant_id'),
            default       => false,
        };
    }

    /** Each platform's own "start a meeting now" URL — genuine, and always live. */
    private function instantStartUrl(string $platform): string
    {
        return match ($platform) {
            'google_meet' => 'https://meet.google.com/new',
            'zoom'        => 'https://zoom.us/start/videomeeting',
            'teams'       => 'https://teams.microsoft.com/start',
            default       => 'https://'.JitsiHost::for().'/CRM-'.bin2hex(random_bytes(5)),
        };
    }
}

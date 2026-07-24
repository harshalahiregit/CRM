<?php

namespace App\Services\Shared\MeetingProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Zoom provider (Server-to-Server OAuth — replaces deprecated JWT app).
 *
 * Requirements (set in .env):
 *   MEETING_PROVIDER=zoom
 *   ZOOM_ACCOUNT_ID=...
 *   ZOOM_CLIENT_ID=...
 *   ZOOM_CLIENT_SECRET=...
 *
 * The access token is cached for 55 minutes (Zoom tokens last 60 min).
 */
class ZoomProvider implements MeetingProviderInterface
{
    private string $accountId;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->accountId    = config('meeting.zoom.account_id', '');
        $this->clientId     = config('meeting.zoom.client_id', '');
        $this->clientSecret = config('meeting.zoom.client_secret', '');
    }

    public function create(array $data): array
    {
        $token = $this->getAccessToken();

        $start = (new \DateTime($data['scheduled_at']))->format('Y-m-d\TH:i:s');

        $response = Http::withToken($token)
            ->post('https://api.zoom.us/v2/users/me/meetings', [
                'topic'      => $data['title'],
                'type'       => 2,                        // Scheduled
                'start_time' => $start,
                'duration'   => $data['duration_min'],
                'timezone'   => 'UTC',
                'settings'   => [
                    'join_before_host'  => true,
                    'waiting_room'      => false,
                    'auto_recording'    => 'none',
                ],
            ])
            ->throw()
            ->json();

        return [
            'link'      => $response['join_url']  ?? null,
            'id'        => (string) ($response['id'] ?? ''),
            'passcode'  => $response['password']   ?? null,
            'host_link' => $response['start_url']  ?? null,
            'platform'  => 'zoom',
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        return Cache::remember('zoom_oauth_token', 55 * 60, function () {
            if (! $this->accountId || ! $this->clientId || ! $this->clientSecret) {
                throw new RuntimeException('Zoom credentials not configured. Set ZOOM_ACCOUNT_ID, ZOOM_CLIENT_ID, ZOOM_CLIENT_SECRET in .env.');
            }

            $resp = Http::withBasicAuth($this->clientId, $this->clientSecret)
                ->asForm()
                ->post('https://zoom.us/oauth/token', [
                    'grant_type' => 'account_credentials',
                    'account_id' => $this->accountId,
                ])
                ->throw()
                ->json();

            return $resp['access_token'];
        });
    }
}

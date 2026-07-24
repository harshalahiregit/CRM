<?php

namespace App\Services\Shared\MeetingProviders;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Microsoft Teams provider (Microsoft Graph API — application permissions).
 *
 * Requirements (set in .env):
 *   MEETING_PROVIDER=teams
 *   TEAMS_TENANT_ID=...
 *   TEAMS_CLIENT_ID=...
 *   TEAMS_CLIENT_SECRET=...
 *
 * The Azure AD app needs:
 *   - OnlineMeetings.ReadWrite.All  (application permission, admin consented)
 *
 * The access token is cached for 50 minutes (Azure tokens last ~60 min).
 */
class TeamsProvider implements MeetingProviderInterface
{
    private string $tenantId;
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->tenantId     = config('meeting.teams.tenant_id', '');
        $this->clientId     = config('meeting.teams.client_id', '');
        $this->clientSecret = config('meeting.teams.client_secret', '');
    }

    public function create(array $data): array
    {
        $token = $this->getAccessToken();

        $start = new \DateTime($data['scheduled_at']);
        $end   = (clone $start)->modify("+{$data['duration_min']} minutes");

        $response = Http::withToken($token)
            ->post('https://graph.microsoft.com/v1.0/me/onlineMeetings', [
                'subject'         => $data['title'],
                'startDateTime'   => $start->format(\DateTime::RFC3339),
                'endDateTime'     => $end->format(\DateTime::RFC3339),
            ])
            ->throw()
            ->json();

        return [
            'link'      => $response['joinWebUrl']       ?? null,
            'id'        => $response['id']               ?? null,
            'passcode'  => null,
            'host_link' => null,
            'platform'  => 'teams',
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        return Cache::remember("teams_oauth_token_{$this->tenantId}", 50 * 60, function () {
            if (! $this->tenantId || ! $this->clientId || ! $this->clientSecret) {
                throw new RuntimeException('Teams credentials not configured. Set TEAMS_TENANT_ID, TEAMS_CLIENT_ID, TEAMS_CLIENT_SECRET in .env.');
            }

            $resp = Http::asForm()
                ->post("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token", [
                    'grant_type'    => 'client_credentials',
                    'client_id'     => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'scope'         => 'https://graph.microsoft.com/.default',
                ])
                ->throw()
                ->json();

            return $resp['access_token'];
        });
    }
}

<?php

namespace App\Services\Shared\MeetingProviders;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Google Meet provider via Google Calendar API (service-account auth).
 *
 * Requirements (set in .env):
 *   MEETING_PROVIDER=google_meet
 *   GOOGLE_MEET_CREDENTIALS_JSON=/path/to/service-account.json
 *   GOOGLE_MEET_CALENDAR_ID=primary   (optional)
 *
 * The service account must have:
 *   - Domain-wide delegation OR
 *   - Shared calendar access to the organiser's calendar.
 *
 * This creates a Calendar event with `conferenceDataVersion=1` which
 * instructs Google to attach a Meet link automatically.
 */
class GoogleMeetProvider implements MeetingProviderInterface
{
    private string $credentialsPath;
    private string $calendarId;

    public function __construct()
    {
        $this->credentialsPath = config('meeting.google_meet.credentials_path', '');
        $this->calendarId      = config('meeting.google_meet.calendar_id', 'primary');
    }

    public function create(array $data): array
    {
        $token = $this->getAccessToken();

        $start = new \DateTime($data['scheduled_at']);
        $end   = (clone $start)->modify("+{$data['duration_min']} minutes");

        $event = [
            'summary' => $data['title'],
            'start'   => ['dateTime' => $start->format(\DateTime::RFC3339), 'timeZone' => 'UTC'],
            'end'     => ['end'      => $end->format(\DateTime::RFC3339),   'timeZone' => 'UTC'],
            'conferenceData' => [
                'createRequest' => [
                    'requestId'             => uniqid('crm-', true),
                    'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
                ],
            ],
        ];

        $response = Http::withToken($token)
            ->post("https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events?conferenceDataVersion=1", $event)
            ->throw()
            ->json();

        $confData = $response['conferenceData'] ?? [];
        $link     = $confData['entryPoints'][0]['uri'] ?? null;
        $meetId   = $confData['conferenceId'] ?? null;

        if (! $link) {
            throw new RuntimeException('Google Meet did not return a conference link.');
        }

        return [
            'link'      => $link,
            'id'        => $meetId,
            'passcode'  => null,
            'host_link' => null,
            'platform'  => 'google_meet',
        ];
    }

    // ── Private ────────────────────────────────────────────────────────────────

    private function getAccessToken(): string
    {
        if (! $this->credentialsPath || ! file_exists($this->credentialsPath)) {
            throw new RuntimeException('Google Meet credentials file not found. Set GOOGLE_MEET_CREDENTIALS_JSON in .env.');
        }

        $creds = json_decode(file_get_contents($this->credentialsPath), true);

        $now  = time();
        $jwt  = $this->buildJwt($creds, $now);

        $resp = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ])->throw()->json();

        return $resp['access_token'];
    }

    private function buildJwt(array $creds, int $now): string
    {
        $header  = $this->b64u(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64u(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/calendar',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now,
        ]));

        $data = "{$header}.{$payload}";
        openssl_sign($data, $sig, $creds['private_key'], 'SHA256');

        return "{$data}." . $this->b64u($sig);
    }

    private function b64u(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

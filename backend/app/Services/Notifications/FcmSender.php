<?php

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging, HTTP v1.
 *
 * The legacy `/fcm/send` endpoint and its "Server Key" were switched off by
 * Google in 2024. v1 takes an OAuth2 bearer token minted from a service account
 * key, which is why this class signs a JWT rather than reading a key out of the
 * config: most instructions still online describe the dead API.
 *
 * Credentials come from a file OUTSIDE the repo, named by FCM_CREDENTIALS. The
 * key grants send rights on the whole project, so it must never be somewhere a
 * `git add -A` can reach.
 *
 * Not configured is a first-class state, not an error. Push simply reports
 * itself unavailable and the queue records that honestly, rather than throwing
 * on a server that has no key — which is every developer machine.
 */
class FcmSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /** Access tokens last an hour; cached a little under that. */
    private const TOKEN_TTL = 3300;

    public function configured(): bool
    {
        $path = (string) config('services.fcm.credentials');

        return $path !== '' && is_readable($path) && config('services.fcm.project_id');
    }

    /**
     * Send one message to one device.
     *
     * @return array{ok: bool, error: ?string, retire: bool}
     *         `retire` means FCM says this token is dead and should be deleted —
     *         not a failure to retry, and the difference matters: retrying a dead
     *         token forever makes a healthy queue look broken.
     */
    public function send(string $token, string $title, string $body, array $data = []): array
    {
        if (! $this->configured()) {
            return ['ok' => false, 'error' => 'Push is not configured on this server.', 'retire' => false];
        }

        $accessToken = $this->accessToken();
        if (! $accessToken) {
            return ['ok' => false, 'error' => 'Could not obtain a Google access token.', 'retire' => false];
        }

        $project = config('services.fcm.project_id');

        // BOTH a notification block and data, deliberately.
        //
        // The notification block is what makes delivery the system's problem
        // rather than the app's: Android draws it whether the app is alive,
        // asleep or killed. Sending data-only instead — which was tried, to get
        // the full-colour app logo onto the notification — meant Google
        // accepted the message and NOTHING appeared on the phone, because that
        // path depends on the app's background isolate being allowed to run.
        // An arriving notification with a plain icon beats a prettier one that
        // never arrives.
        //
        // The data half still travels, so the app can route a tap to the right
        // screen, and so the foreground handler can draw the richer version
        // (colour logo included) when the app is open.
        //
        // Values are cast to strings because FCM rejects a number or a bool in
        // `data` outright, and an empty PHP array encodes as a JSON list, which
        // FCM refuses with "Cannot bind a list to map for field 'data'".
        $message = [
            'token'        => $token,
            'notification' => ['title' => $title, 'body' => $body],
            'android'      => ['priority' => 'high'],
        ];

        if ($data !== []) {
            $message['data'] = array_map(fn ($v) => (string) $v, $data);
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("https://fcm.googleapis.com/v1/projects/{$project}/messages:send", [
                    'message' => $message,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'retire' => false];
        }

        if ($response->successful()) {
            return ['ok' => true, 'error' => null, 'retire' => false];
        }

        $status = (string) $response->json('error.status', '');
        $reason = (string) $response->json('error.message', 'FCM rejected the message.');

        // UNREGISTERED: the app was uninstalled or its data cleared.
        // INVALID_ARGUMENT on a token: it was never valid, or belongs to another
        // Firebase project — which is exactly what every old token looks like
        // after the project is changed.
        // SENDER_ID_MISMATCH: minted under a DIFFERENT Firebase project, so this
        // server can never send to it. Left in the table it fails on every
        // single send forever, which is what makes a working queue look broken —
        // one such token had been failing since the project was switched.
        $retire = in_array($status, ['NOT_FOUND', 'UNREGISTERED', 'SENDER_ID_MISMATCH'], true)
            || ($status === 'INVALID_ARGUMENT' && str_contains(strtolower($reason), 'token'))
            || str_contains(strtolower($reason), 'senderid mismatch');

        return ['ok' => false, 'error' => $reason, 'retire' => $retire];
    }

    /**
     * An OAuth2 access token for the messaging scope.
     *
     * Cached: a token is good for an hour and minting one is a signed round trip
     * to Google, which is not something to do once per recipient of a broadcast.
     */
    private function accessToken(): ?string
    {
        return Cache::remember('fcm.access_token', self::TOKEN_TTL, function () {
            $creds = json_decode((string) file_get_contents((string) config('services.fcm.credentials')), true);

            if (! isset($creds['client_email'], $creds['private_key'])) {
                Log::warning('FCM credentials file is not a service account key.');

                return null;
            }

            $now = time();
            $jwt = $this->signJwt([
                'iss'   => $creds['client_email'],
                'scope' => self::SCOPE,
                'aud'   => self::TOKEN_URL,
                'iat'   => $now,
                'exp'   => $now + 3600,
            ], $creds['private_key']);

            if (! $jwt) {
                return null;
            }

            $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]);

            if (! $response->successful()) {
                Log::warning('FCM token exchange failed', ['body' => $response->body()]);

                return null;
            }

            return $response->json('access_token');
        });
    }

    /** RS256, which is the only algorithm Google accepts for this grant. */
    private function signJwt(array $claims, string $privateKey): ?string
    {
        $encode = fn (array $p) => rtrim(strtr(base64_encode(json_encode($p)), '+/', '-_'), '=');

        $input = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode($claims);

        $key = openssl_pkey_get_private($privateKey);
        if (! $key) {
            Log::warning('FCM private key could not be read.');

            return null;
        }

        $signature = '';
        if (! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            return null;
        }

        return $input.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}

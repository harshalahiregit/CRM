<?php

namespace App\Services\SangoeTrack;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP client for the SangoeTrack HRM API (track.sangoe.in).
 *
 * Read-only: this integration never writes to SangoeTrack. The JWT is cached so
 * a sync of N employees costs one login rather than N; a 401 mid-run busts the
 * cache and retries the call once, which covers a token expiring between the
 * cache TTL and its real lifetime.
 *
 * Response shapes are not contractually guaranteed to us, so every field is read
 * through config('sangoetrack.map') with an ordered list of candidate keys.
 * `php artisan sangoetrack:probe` dumps a real response to confirm that map.
 */
class SangoeTrackClient
{
    private const CACHE_KEY = 'sangoetrack:token';

    public function isConfigured(): bool
    {
        return (bool) (config('sangoetrack.base_url')
            && config('sangoetrack.email')
            && config('sangoetrack.password'));
    }

    /**
     * Authenticate and return a JWT, cached until shortly before it expires.
     *
     * @throws SangoeTrackException
     */
    public function login(bool $fresh = false): string
    {
        if (! $this->isConfigured()) {
            throw new SangoeTrackException('SangoeTrack is not configured: set SANGOETRACK_BASE_URL, SANGOETRACK_EMAIL and SANGOETRACK_PASSWORD.');
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        return Cache::remember(self::CACHE_KEY, (int) config('sangoetrack.token_ttl'), function (): string {
            $response = $this->http(false)->post($this->url('login'), [
                'email'    => config('sangoetrack.email'),
                'password' => config('sangoetrack.password'),
            ]);

            if (! $response->successful()) {
                throw new SangoeTrackException('SangoeTrack login failed (HTTP '.$response->status().').');
            }

            $token = $this->pick($response->json() ?? [], 'token');

            if (! is_string($token) || $token === '') {
                throw new SangoeTrackException('SangoeTrack login returned no token. Run `php artisan sangoetrack:probe` and check config/sangoetrack.php map.token.');
            }

            return $token;
        });
    }

    /**
     * Raw attendance rows for one user over one month.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getAttendanceHistory(int $userId, int $workspaceId, string $month, string $year): array
    {
        return $this->rows('attendance_history', [
            'user_id'      => $userId,
            'workspace_id' => $workspaceId,
            'month'        => $month,
            'year'         => $year,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLeaves(int $userId, int $workspaceId, string $month, string $year): array
    {
        return $this->rows('leaves', [
            'user_id'      => $userId,
            'workspace_id' => $workspaceId,
            'month'        => $month,
            'year'         => $year,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLeaveTypes(int $workspaceId): array
    {
        return $this->rows('leave_types', ['workspace_id' => $workspaceId]);
    }

    /** @return array<int, array<string, mixed>> */
    public function getLeaveBalance(int $userId, int $workspaceId): array
    {
        return $this->rows('leave_balance', [
            'user_id'      => $userId,
            'workspace_id' => $workspaceId,
        ]);
    }

    /**
     * Raw POST for diagnostics (sangoetrack:probe). Returns the decoded body
     * exactly as received, with no field mapping applied.
     */
    public function raw(string $endpointKey, array $payload = []): array
    {
        $response = $this->send($endpointKey, $payload);

        return [
            'status' => $response->status(),
            'body'   => $response->json() ?? ['_raw' => $response->body()],
        ];
    }

    /* ─────────────────────────── internals ─────────────────────────── */

    /**
     * POST an authenticated call and normalise the payload down to a list of rows.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $endpointKey, array $payload): array
    {
        $body = $this->send($endpointKey, $payload)->json() ?? [];

        // The list may sit at the top level or under any of the configured keys.
        $rows = $this->pick($body, 'rows');
        if ($rows === null) {
            $rows = array_is_list($body) ? $body : [];
        }

        if (! is_array($rows)) {
            return [];
        }

        // A single-record response is still one row.
        if (! array_is_list($rows)) {
            $rows = [$rows];
        }

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Send an authenticated POST, retrying once on 401 with a fresh token.
     *
     * @throws SangoeTrackException
     */
    private function send(string $endpointKey, array $payload): Response
    {
        $url = $this->url($endpointKey);

        $response = $this->http()->withToken($this->login())->post($url, $payload);

        if ($response->status() === 401) {
            // Cached token outlived its real validity — re-login once and retry.
            Log::channel('hr')->info('SangoeTrack token rejected, re-authenticating', ['endpoint' => $endpointKey]);
            $response = $this->http()->withToken($this->login(true))->post($url, $payload);
        }

        if (! $response->successful()) {
            throw new SangoeTrackException(
                'SangoeTrack '.$endpointKey.' failed (HTTP '.$response->status().'): '.mb_substr($response->body(), 0, 300)
            );
        }

        return $response;
    }

    private function http(bool $retry = true): PendingRequest
    {
        $request = Http::acceptJson()
            ->asJson()
            ->timeout((int) config('sangoetrack.http.timeout'));

        if ($retry) {
            $request = $request->retry(
                max(1, (int) config('sangoetrack.http.retry_times')),
                (int) config('sangoetrack.http.retry_sleep'),
                throw: false
            );
        }

        return $request;
    }

    private function url(string $endpointKey): string
    {
        $path = config('sangoetrack.endpoints.'.$endpointKey);

        if (! $path) {
            throw new SangoeTrackException('Unknown SangoeTrack endpoint: '.$endpointKey);
        }

        return config('sangoetrack.base_url').'/'.ltrim($path, '/');
    }

    /**
     * First present value among the configured candidate keys for $field.
     * Candidates may be dot-paths, so `data.token` works as well as `token`.
     */
    private function pick(array $body, string $field): mixed
    {
        foreach ((array) config('sangoetrack.map.'.$field, []) as $key) {
            $value = Arr::get($body, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

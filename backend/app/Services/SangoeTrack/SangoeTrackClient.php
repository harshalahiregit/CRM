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
 * No longer read-only. Approvals, disbursements and salary changes now go out
 * through here, and they go through SangoeTrack's own controllers rather than
 * its tables on purpose: approving an attendance correction there also writes
 * the attendance row and pushes a notification to the employee's phone. Writing
 * to the database directly would do neither, and the employee would never learn
 * their correction was accepted.
 *
 * The JWT is cached so N calls cost one login; a 401 mid-run busts the cache and
 * retries once, covering a token that expired between our TTL and its real one.
 *
 * Response shapes are not contractually guaranteed to us, so every field is read
 * through config('sangoetrack.map') with an ordered list of candidate keys.
 * `php artisan sangoetrack:probe` dumps a real response to confirm that map.
 */
class SangoeTrackClient
{
    private const CACHE_KEY = 'sangoetrack:token';

    /**
     * Endpoints SangoeTrack declared as GET. Everything else there is POST —
     * including reads — so POST is the default and this is the exception list.
     * Sending POST to their GET route yields a 405, not a helpful error.
     */
    private const GET_ENDPOINTS = ['admin_dashboard'];

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
     * Call an endpoint and return its decoded body, with SangoeTrack's own
     * failure convention translated into an exception.
     *
     * They answer a refused request with HTTP 200 and `status: 0` in the body —
     * so `$response->successful()` is true for "Permission denied", "Leave not
     * found" and every validation failure. Checking only the HTTP code makes a
     * rejected approval look like it worked, which is the worst way for this to
     * fail: the CRM would show success while nothing happened on their side.
     *
     * `status` absent is treated as success — some of their endpoints return a
     * bare object with no envelope at all.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws SangoeTrackException
     */
    public function call(string $endpointKey, array $payload = []): array
    {
        $body = $this->send($endpointKey, $payload)->json() ?? [];

        if (! is_array($body)) {
            throw new SangoeTrackException('SangoeTrack '.$endpointKey.' returned a non-object response.');
        }

        if (array_key_exists('status', $body) && (int) $body['status'] === 0) {
            throw new SangoeTrackException(
                is_string($body['message'] ?? null) && $body['message'] !== ''
                    ? $body['message']
                    : 'SangoeTrack '.$endpointKey.' refused the request.'
            );
        }

        return $body;
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
        $url  = $this->url($endpointKey);
        $verb = in_array($endpointKey, self::GET_ENDPOINTS, true) ? 'get' : 'post';

        $response = $this->http()->withToken($this->login())->{$verb}($url, $payload);

        // 401 is the obvious "token expired". 403 is here because SangoeTrack
        // answers a token it no longer recognises with "Permission denied", not
        // with a 401: its admin check resolves the user to null and refuses on
        // that basis, so a dead session is indistinguishable from a real
        // permission problem by status code alone.
        //
        // That matters because SangoeTrack appears to invalidate the previous
        // token when the same account logs in again. Two clients sharing one
        // account — which is what we do today — knock each other out, and
        // without this the CRM would serve a dead token for the full cache TTL
        // before recovering. Observed in production: every screen returned
        // "Permission denied" for the better part of an hour.
        //
        // Retrying a genuine permission failure costs one extra request and
        // returns the same error, which is the cheaper mistake of the two.
        if (in_array($response->status(), [401, 403], true)) {
            Log::channel('hr')->info('SangoeTrack token rejected, re-authenticating', [
                'endpoint' => $endpointKey,
                'status'   => $response->status(),
            ]);
            $response = $this->http()->withToken($this->login(true))->{$verb}($url, $payload);
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

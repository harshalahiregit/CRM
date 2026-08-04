<?php

namespace App\Services\Hr\Publishing;

use App\Models\Hr\HrJobPosting;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Review comment #12 — "Publish Channel: fix", i.e. finish the channels the UI
 * had been listing as "Coming Soon" since the module shipped.
 *
 * Every remaining board (LinkedIn, Naukri, Indeed) speaks the same shape of REST
 * API as TrulyTalents: POST a job, get an id back; DELETE it by that id; GET it
 * to read its status. Writing that three more times would be three more copies of
 * the same retry policy, the same error handling and the same response parsing —
 * exactly the duplication the comment's "no duplicate logic" rules out.
 *
 * So the HTTP behaviour lives here once, and a channel is a subclass that
 * declares its key and label. Everything that differs between boards — endpoint,
 * credentials, paths, timeouts, response shape, even the field names in the
 * payload — is CONFIGURATION, read from `config/hr_publishing.{configKey}`.
 * Onboarding a fourth board is a config block and a six-line class.
 *
 * This deliberately owns no ledger, no tenant guard and no audit entry:
 * JobPublishingService does all of that, and a channel that duplicated it would
 * be the same mistake one layer down.
 */
abstract class RestJobBoardChannel implements JobChannel, SyncableChannel
{
    abstract public function key(): string;

    abstract public function label(): string;

    /**
     * Config section and env prefix for this board. Defaults to the channel key,
     * so `naukri` reads `hr_publishing.naukri` and NAUKRI_BASE_URL.
     */
    protected function configKey(): string
    {
        return $this->key();
    }

    public function publish(HrJobPosting $job): array
    {
        $config = $this->config();

        $response = $this->request($config)
            ->post($this->url($config, $config['publish_path']), $this->payload($job, $config));

        if ($response->failed()) {
            // The remote body is the useful half of the diagnosis, so it is kept —
            // truncated, because JobPublishingService stores it in a 1000-char column.
            throw new RuntimeException(sprintf(
                '%s rejected the posting (HTTP %d): %s',
                $this->label(), $response->status(),
                mb_substr(trim($response->body()) ?: 'no response body', 0, 300)
            ));
        }

        $body = $response->json() ?: [];
        $reference = data_get($body, $config['response_ref_key']);

        if (! $reference) {
            // Without a reference the posting can never be withdrawn or synced,
            // so a 200 with no id is a failure, not a success.
            throw new RuntimeException(sprintf(
                '%s accepted the posting but returned no reference under "%s" — it cannot be tracked or withdrawn.',
                $this->label(), $config['response_ref_key']
            ));
        }

        Log::channel('hr')->info('Job published to channel', [
            'channel' => $this->key(), 'job_posting_id' => $job->id,
            'tenant_id' => $job->tenant_id, 'external_ref' => $reference,
        ]);

        return [
            'external_ref' => (string) $reference,
            'external_url' => data_get($body, $config['response_url_key']),
            'meta' => [
                'http_status'  => $response->status(),
                'published_at' => now()->toIso8601String(),
                // Kept for support: when a board disputes what it received, this
                // is the only record of what it actually said.
                'response'     => $body,
            ],
        ];
    }

    public function unpublish(HrJobPosting $job): void
    {
        $config = $this->config();
        $publication = $job->publications()
            ->where('channel', $this->key())
            ->whereNotNull('external_ref')
            ->latest('id')->first();

        if (! $publication) {
            throw new RuntimeException(sprintf(
                'No %s reference is on record for this job, so it cannot be withdrawn.', $this->label()
            ));
        }

        $response = $this->request($config)
            ->delete($this->url($config, $config['unpublish_path']).'/'.$publication->external_ref);

        // 404 means it is already gone from their side. Treating that as an error
        // would leave the job stuck as published here with nothing to withdraw.
        if ($response->failed() && $response->status() !== 404) {
            throw new RuntimeException(sprintf(
                '%s refused to withdraw the posting (HTTP %d): %s',
                $this->label(), $response->status(),
                mb_substr(trim($response->body()) ?: 'no response body', 0, 300)
            ));
        }

        Log::channel('hr')->info('Job withdrawn from channel', [
            'channel' => $this->key(), 'job_posting_id' => $job->id,
            'external_ref' => $publication->external_ref, 'http_status' => $response->status(),
        ]);
    }

    /**
     * What the board currently says about a posting.
     *
     * A 404 is a real, meaningful answer: the posting is gone from their side —
     * expired, or pulled by a moderator. Reporting that as `removed` is the whole
     * point of a sync, because the CRM would otherwise go on claiming the job is
     * live on a board that dropped it weeks ago.
     */
    public function syncStatus(HrJobPosting $job, string $externalRef): array
    {
        $config = $this->config();

        $response = $this->request($config)
            ->get($this->url($config, $config['publish_path']).'/'.$externalRef);

        if ($response->status() === 404) {
            return [
                'status' => 'removed',
                'meta'   => ['http_status' => 404, 'checked_at' => now()->toIso8601String()],
            ];
        }

        if ($response->failed()) {
            // A transport or auth failure is NOT a statement about the posting.
            // Reporting it as `removed` would withdraw a perfectly live job.
            throw new RuntimeException(sprintf(
                '%s could not be reached for status (HTTP %d): %s',
                $this->label(), $response->status(),
                mb_substr(trim($response->body()) ?: 'no response body', 0, 300)
            ));
        }

        $body = $response->json() ?: [];
        $remote = (string) (data_get($body, $config['response_status_key']) ?? '');

        return [
            'status'       => $this->mapStatus($remote),
            'external_url' => data_get($body, $config['response_url_key']),
            'meta'         => [
                'http_status'   => $response->status(),
                'remote_status' => $remote ?: null,
                'checked_at'    => now()->toIso8601String(),
                'response'      => $body,
            ],
        ];
    }

    /* ── Internals ────────────────────────────────────────────────────── */

    private function request(array $config)
    {
        return Http::withToken($config['api_key'])
            ->acceptJson()
            ->timeout($config['timeout'])
            ->retry($config['retries'], 200, $this->retryWhen(), throw: false);
    }

    private function url(array $config, string $path): string
    {
        return $config['base_url'].'/'.ltrim($path, '/');
    }

    /**
     * Retry transport failures, 429 and 5xx — never a 4xx.
     *
     * A 4xx is the board's considered answer ("no such posting", "bad payload")
     * and repeating the identical request cannot change it. Without this predicate
     * `retry()` treats every non-2xx as retryable, so a 404 status check costs
     * three round trips to learn what the first one already told us.
     */
    private function retryWhen(): \Closure
    {
        return function ($exception) {
            if (! $exception instanceof RequestException) {
                return true; // connection/timeout — worth another attempt
            }

            $status = $exception->response->status();

            return $status === 429 || $status >= 500;
        };
    }

    /**
     * Their vocabulary onto ours.
     *
     * Boards use their own words for the same four states, so the mapping is
     * config-extensible: `status_map` adds terms without a code change. Anything
     * still unrecognised becomes `unknown` rather than being guessed at — a status
     * we do not understand must not silently become "removed".
     */
    protected function mapStatus(string $remote): string
    {
        $remote = mb_strtolower(trim($remote));

        foreach ((array) ($this->config()['status_map'] ?? []) as $status => $terms) {
            if (in_array($remote, array_map('mb_strtolower', (array) $terms), true)) {
                return $status;
            }
        }

        return match ($remote) {
            'live', 'active', 'published', 'open' => 'published',
            'closed', 'removed', 'deleted', 'withdrawn', 'archived' => 'removed',
            'expired', 'lapsed' => 'expired',
            default => 'unknown',
        };
    }

    /**
     * Resolved config, or an exception naming exactly which env var is missing.
     *
     * "Not configured" is a distinct, common and fixable state — the message says
     * which variable to set rather than surfacing a connection error from a
     * request to an empty URL. JobPublishingService catches this and records it as
     * a `failed` publication, so a recruiter is never told a job is live on a
     * board it never reached.
     */
    protected function config(): array
    {
        $key    = $this->configKey();
        $config = config("hr_publishing.{$key}", []);
        $prefix = mb_strtoupper($key);

        foreach (['base_url', 'api_key'] as $required) {
            if (blank($config[$required] ?? null)) {
                throw new RuntimeException(sprintf(
                    '%s is not configured — set %s_%s in the environment.',
                    $this->label(), $prefix, mb_strtoupper($required)
                ));
            }
        }

        return [
            'base_url'            => rtrim($config['base_url'], '/'),
            'api_key'             => $config['api_key'],
            'publish_path'        => $config['publish_path'] ?? 'jobs',
            'unpublish_path'      => $config['unpublish_path'] ?? 'jobs',
            'timeout'             => (int) ($config['timeout'] ?? 20),
            'retries'             => max(1, (int) ($config['retries'] ?? 2)),
            'response_ref_key'    => $config['response_ref_key'] ?? 'id',
            'response_url_key'    => $config['response_url_key'] ?? 'url',
            'response_status_key' => $config['response_status_key'] ?? 'status',
            'status_map'          => $config['status_map'] ?? [],
            'field_map'           => $config['field_map'] ?? [],
        ];
    }

    /**
     * The job as this board expects it.
     *
     * The canonical payload is built once; `field_map` renames keys for a board
     * that calls `title` something else. That keeps a board's naming quirk in
     * config instead of forcing a subclass to re-implement the whole payload.
     */
    protected function payload(HrJobPosting $job, array $config): array
    {
        // Column names are the ones hr_job_postings actually has. The originals
        // read `salary_min`, `required_skills`, `experience_required` and
        // `number_of_positions` — none of which exist on this table, so every
        // board had been receiving a posting with no salary, skills, experience
        // or headcount, silently dropped by the array_filter below.
        $mr = $job->manpowerRequest;

        $canonical = array_filter([
            'title'           => $job->title,
            'description'     => $job->description ?: $job->requirements,
            'department'      => $job->department,
            'location'        => $job->location,
            'employment_type' => $job->job_type,
            // Skills and experience live on the requisition the job came from.
            'experience'      => $mr?->experience_required,
            'openings'        => $job->number_of_openings,
            'skills'          => $mr?->required_skills ?: null,
            'salary_min'      => $job->salary_from,
            'salary_max'      => $job->salary_to,
            // Our own id, so a webhook or a support query traces back to us.
            'external_id'     => (string) $job->id,
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        $map = (array) ($config['field_map'] ?? []);
        if ($map === []) {
            return $canonical;
        }

        $out = [];
        foreach ($canonical as $field => $value) {
            $out[$map[$field] ?? $field] = $value;
        }

        return $out;
    }
}

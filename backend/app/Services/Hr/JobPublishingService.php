<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Hr\HrJobPosting;
use App\Models\Hr\HrJobPublication;
use App\Models\User;
use App\Services\Hr\Publishing\JobChannel;
use App\Support\Hr\JobPostingStatus;
use Illuminate\Support\Facades\Log;

/**
 * Publishes a Job Posting to distribution channels (Career Portal today;
 * LinkedIn / Naukri / Indeed / TrulyTalents when their channel classes exist).
 *
 * This is the single place that owns the publication ledger, tenant + role
 * guards and audit logging, so channels stay thin. Adding a channel = write a
 * JobChannel class and register it in config/hr_publishing.php.
 */
class JobPublishingService
{
    /** Channel metadata for the UI: which channels exist and which are integrated. */
    public function channels(): array
    {
        return collect(config('hr_publishing.channels', []))
            ->map(fn ($c, $key) => [
                'key'        => $key,
                'label'      => $c['label'] ?? ucfirst($key),
                'integrated' => ! empty($c['class']),
            ])->values()->all();
    }

    public function publish(HrJobPosting $job, string $channelKey, User $user): HrJobPublication
    {
        $this->assertTenant($job, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to publish this job');

        if (! JobPostingStatus::isLive($job->status)) {
            throw new BusinessException('Publish the job (make it live) before pushing it to a channel', 422);
        }

        $channel = $this->resolve($channelKey);

        // Push to the channel. External adapters may throw — capture that on the
        // ledger (status=failed + error_message) so the UI can surface it.
        try {
            $result = $channel->publish($job);
        } catch (\Throwable $e) {
            $this->record($job, $channel->key(), 'failed', [
                'error_message'  => mb_substr($e->getMessage(), 0, 1000),
                'last_synced_at' => now(),
            ]);
            $job->recordAudit('Publish to '.$channel->label().' failed', $user, $e->getMessage(), ['channel' => $channel->key()]);
            Log::channel('hr')->warning('Job channel publish failed', ['job_posting_id' => $job->id, 'channel' => $channel->key(), 'error' => $e->getMessage()]);
            throw new BusinessException('Failed to publish to '.$channel->label().': '.$e->getMessage(), 502);
        }

        $publication = $this->record($job, $channel->key(), 'published', [
            'external_ref'   => $result['external_ref'] ?? null,
            'external_url'   => $result['external_url'] ?? null,
            'published_at'   => now(),
            'last_synced_at' => now(),
            'error_message'  => null,
            'meta'           => $result['meta'] ?? null,
        ]);

        $job->recordAudit('Published to '.$channel->label(), $user, null, array_filter([
            'channel'         => $channel->key(),
            'url'             => $result['external_url'] ?? null,
            'portal_home_url' => $result['meta']['portal_home_url'] ?? null,
        ]));
        Log::channel('hr')->info('Job published to channel', ['job_posting_id' => $job->id, 'tenant_id' => $job->tenant_id, 'channel' => $channel->key()]);

        return $publication;
    }

    /**
     * Publish to several channels in one go (workspace multi-select). Each
     * channel is attempted independently — one failing (or being un-integrated)
     * never aborts the others; every outcome is recorded per channel.
     */
    public function publishMany(HrJobPosting $job, array $channelKeys, User $user): array
    {
        $this->assertTenant($job, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to publish this job');

        $results = [];
        foreach (array_values(array_unique($channelKeys)) as $key) {
            try {
                $pub = $this->publish($job, $key, $user);
                $results[] = ['channel' => $key, 'status' => $pub->status];
            } catch (\Throwable $e) {
                $results[] = ['channel' => $key, 'status' => 'failed', 'message' => $e->getMessage()];
            }
        }

        return [
            'published' => count(array_filter($results, fn ($r) => $r['status'] === 'published')),
            'total'     => count($results),
            'results'   => $results,
        ];
    }

    private function record(HrJobPosting $job, string $channel, string $status, array $extra): HrJobPublication
    {
        return HrJobPublication::updateOrCreate(
            ['job_posting_id' => $job->id, 'channel' => $channel],
            array_merge(['tenant_id' => $job->tenant_id, 'status' => $status], $extra)
        );
    }

    public function unpublish(HrJobPosting $job, string $channelKey, User $user): void
    {
        $this->assertTenant($job, $user);
        $this->authorize($user->canManageHrQueue(), 'You are not authorised to unpublish this job');

        $channel = $this->resolve($channelKey);
        $channel->unpublish($job);

        HrJobPublication::where('job_posting_id', $job->id)->where('channel', $channel->key())
            ->update(['status' => 'removed']);

        $job->recordAudit('Removed from '.$channel->label(), $user, null, ['channel' => $channel->key()]);
        Log::channel('hr')->info('Job removed from channel', ['job_posting_id' => $job->id, 'tenant_id' => $job->tenant_id, 'channel' => $channel->key()]);
    }

    private function resolve(string $key): JobChannel
    {
        $channels = config('hr_publishing.channels', []);
        if (! array_key_exists($key, $channels)) {
            throw new BusinessException("Unknown publishing channel: {$key}", 422);
        }
        $class = $channels[$key]['class'] ?? null;
        if (! $class) {
            throw new BusinessException(($channels[$key]['label'] ?? $key).' integration is not available yet', 422);
        }

        return app($class);
    }

    private function assertTenant(HrJobPosting $job, User $user): void
    {
        if ((int) $job->tenant_id !== (int) $user->tenant_id) {
            throw new UnauthorizedTenantException('Job not found');
        }
    }

    private function authorize(bool $allowed, string $message): void
    {
        if (! $allowed) {
            throw new BusinessException($message, 403);
        }
    }
}

<?php

namespace App\Console\Commands\SangoeTrack;

use App\Models\Hr\HrEmployee;
use App\Services\SangoeTrack\SangoeTrackClient;
use App\Services\SangoeTrack\SangoeTrackException;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Links CRM employees to their SangoeTrack HRM user by email.
 *
 * Matching is on email — official_email first, then the personal email — and is
 * scoped per tenant, because a SangoeTrack user id is only unique inside its own
 * workspace. Idempotent: re-running re-confirms existing links and fills gaps.
 */
class MapSangoeTrackEmployees extends Command
{
    protected $signature = 'sangoetrack:map-employees
                            {--tenant= : Restrict to one tenant id}
                            {--workspace= : Workspace id (defaults to SANGOETRACK_WORKSPACE_ID)}
                            {--remap : Overwrite links that are already set}
                            {--dry-run : Report what would change without writing}';

    protected $description = 'Match CRM employees to SangoeTrack users by email and store sangoetrack_user_id';

    public function handle(SangoeTrackClient $client): int
    {
        $workspaceId = (int) ($this->option('workspace') ?: config('sangoetrack.workspace_id'));

        if ($workspaceId <= 0) {
            $this->error('No workspace id. Pass --workspace= or set SANGOETRACK_WORKSPACE_ID.');

            return self::FAILURE;
        }

        // Leave balance is the cheapest call that returns the workspace roster;
        // it carries a user id + email per member, which is all the mapping needs.
        try {
            $remote = $client->getLeaveBalance(0, $workspaceId);
        } catch (SangoeTrackException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $byEmail = [];
        foreach ($remote as $row) {
            $email = $this->firstOf($row, ['email', 'user_email', 'user.email']);
            $id    = $this->firstOf($row, ['user_id', 'id', 'user.id']);
            if ($email && $id) {
                $byEmail[strtolower(trim((string) $email))] = (int) $id;
            }
        }

        if (! $byEmail) {
            $this->error('SangoeTrack returned no usable {email, user_id} pairs. Run `php artisan sangoetrack:probe leave_balance` and check the response shape.');

            return self::FAILURE;
        }

        $this->info('SangoeTrack users discovered: '.count($byEmail));

        $dry = (bool) $this->option('dry-run');
        $mapped = $skipped = $unmatched = 0;

        HrEmployee::query()
            ->when($this->option('tenant'), fn ($q) => $q->where('tenant_id', (int) $this->option('tenant')))
            ->when(! $this->option('remap'), fn ($q) => $q->whereNull('sangoetrack_user_id'))
            ->orderBy('id')
            ->chunkById(200, function ($employees) use ($byEmail, $workspaceId, $dry, &$mapped, &$skipped, &$unmatched) {
                foreach ($employees as $employee) {
                    $candidates = array_filter([
                        $employee->official_email ? strtolower(trim($employee->official_email)) : null,
                        $employee->email ? strtolower(trim($employee->email)) : null,
                    ]);

                    $userId = null;
                    foreach ($candidates as $email) {
                        if (isset($byEmail[$email])) {
                            $userId = $byEmail[$email];
                            break;
                        }
                    }

                    if (! $userId) {
                        $unmatched++;
                        continue;
                    }

                    if ((int) $employee->sangoetrack_user_id === $userId
                        && (int) $employee->sangoetrack_workspace_id === $workspaceId) {
                        $skipped++;
                        continue;
                    }

                    if (! $dry) {
                        $employee->forceFill([
                            'sangoetrack_user_id'      => $userId,
                            'sangoetrack_workspace_id' => $workspaceId,
                        ])->saveQuietly();
                    }

                    $mapped++;
                    $this->line(sprintf('  %-32s -> user #%d', mb_strimwidth($employee->name ?? '?', 0, 32, '…'), $userId));
                }
            });

        $this->newLine();
        $this->info(($dry ? '[dry run] ' : '')."mapped {$mapped}, already linked {$skipped}, no SangoeTrack match {$unmatched}");

        return self::SUCCESS;
    }

    private function firstOf(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            $value = Arr::get($row, $key);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}

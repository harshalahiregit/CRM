<?php

namespace App\Console\Commands\SangoeTrack;

use App\Models\Hr\HrEmployee;
use App\Services\SangoeTrack\SangoeTrackDirectory;
use App\Services\SangoeTrack\SangoeTrackException;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Creates CRM employees from the SangoeTrack roster, read directly from its
 * MySQL database (workspace + is_active), not over HTTP.
 *
 * The counterpart to sangoetrack:map-employees: mapping links people who already
 * exist here, this creates the ones who do not. With an empty hr_employees there
 * is nothing to map to, so this is the first command to run on a fresh tenant.
 *
 * Email is the identity. A row whose email already exists is UPDATED in place
 * (gaining its SangoeTrack ids), never duplicated — so the command is safe to
 * re-run, and safe to run after map-employees.
 */
class ImportSangoeTrackEmployees extends Command
{
    protected $signature = 'sangoetrack:import-employees
                            {--tenant= : CRM tenant id to import into (required unless --dry-run)}
                            {--workspace= : SangoeTrack workspace (defaults to SANGOETRACK_WORKSPACE_ID)}
                            {--dry-run : Report what would change without writing}
                            {--update-existing : Also refresh name/phone/department on employees that already exist}';

    protected $description = 'Create CRM employees from the SangoeTrack database roster (idempotent)';

    public function handle(SangoeTrackDirectory $directory): int
    {
        $dry      = (bool) $this->option('dry-run');
        $tenantId = (int) $this->option('tenant');

        // A dry run is allowed without --tenant so the roster can be inspected
        // before deciding where it lands; a real import cannot be tenant-less.
        if ($tenantId <= 0 && ! $dry) {
            $this->error('--tenant is required: an imported employee must belong to exactly one tenant.');

            return self::FAILURE;
        }

        $workspace = (int) ($this->option('workspace') ?: config('sangoetrack.db.workspace', 1));

        $this->line('connection : '.$directory->connectionName());
        $this->line('workspace  : '.$workspace.'   (is_active = 1)');

        try {
            $rows = $directory->employees($workspace);
        } catch (SangoeTrackException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $rows) {
            $this->warn('No active employees found in that workspace. Check --workspace and the is_active flag.');

            return self::FAILURE;
        }

        $this->info('SangoeTrack employees found: '.count($rows));
        $this->newLine();

        $keyColumn = (string) config('sangoetrack.employee_key', 'user_id');
        $defaults  = (array) config('sangoetrack.import_defaults');
        $source    = (string) config('sangoetrack.source.imported');

        $created = $linked = $updated = $skipped = $failed = 0;

        foreach ($rows as $row) {
            $email = isset($row['email']) ? strtolower(trim((string) $row['email'])) : '';
            $name  = trim((string) ($row['name'] ?? ''));

            // Email is the match key and has no fallback: without it an import
            // could neither find an existing employee nor be re-run safely.
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                $this->warn(sprintf('  skip (no usable email): %s', $name !== '' ? $name : '#'.($row['id'] ?? '?')));
                continue;
            }

            // employees.user_id is the login; employees.id is the roster row.
            // Which one attendance wants is config, not a code change.
            $externalKey = (int) ($row[$keyColumn] ?? $row['user_id'] ?? $row['id'] ?? 0);

            try {
                $existing = $tenantId > 0
                    ? HrEmployee::where('tenant_id', $tenantId)
                        ->where(fn ($q) => $q->whereRaw('LOWER(email) = ?', [$email])
                            ->orWhereRaw('LOWER(official_email) = ?', [$email]))
                        ->first()
                    : null;

                if ($existing) {
                    $changes = [];

                    if ($externalKey > 0 && (int) $existing->sangoetrack_user_id !== $externalKey) {
                        $changes['sangoetrack_user_id'] = $externalKey;
                    }
                    if ((int) $existing->sangoetrack_workspace_id !== $workspace) {
                        $changes['sangoetrack_workspace_id'] = $workspace;
                    }

                    if ($this->option('update-existing')) {
                        foreach ([
                            'name'        => $name ?: null,
                            'department'  => $directory->label('department', $row['department_id'] ?? null),
                            'designation' => $directory->label('designation', $row['designation_id'] ?? null),
                        ] as $field => $value) {
                            if ($value && (string) $existing->{$field} !== (string) $value) {
                                $changes[$field] = $value;
                            }
                        }
                    }

                    if (! $changes) {
                        $skipped++;
                        continue;
                    }

                    if (! $dry) {
                        $existing->forceFill($changes)->saveQuietly();
                    }

                    isset($changes['sangoetrack_user_id']) ? $linked++ : $updated++;
                    $this->line(sprintf('  ~ %-34s %s', mb_strimwidth($email, 0, 34, '…'), implode(', ', array_keys($changes))));

                    continue;
                }

                if (! $dry) {
                    HrEmployee::create([
                        'tenant_id'                => $tenantId,
                        'source'                   => $source,
                        'employee_code'            => $this->employeeCode($row, $tenantId),
                        'name'                     => $name !== '' ? $name : $email,
                        'email'                    => $email,
                        'department'               => $directory->label('department', $row['department_id'] ?? null) ?: $defaults['department'],
                        'designation'              => $directory->label('designation', $row['designation_id'] ?? null) ?: $defaults['designation'],
                        'location'                 => $directory->label('branch', $row['branch_id'] ?? null),
                        'status'                   => $defaults['status'],
                        'joining_date'             => $this->joiningDate($row),
                        'sangoetrack_user_id'      => $externalKey ?: null,
                        'sangoetrack_workspace_id' => $workspace,
                    ]);
                }

                $created++;
                $this->line(sprintf('  + %-34s %s', mb_strimwidth($email, 0, 34, '…'), $name));
            } catch (Throwable $e) {
                $failed++;
                $this->warn('  ! '.$email.': '.$e->getMessage());
                Log::channel('hr')->warning('SangoeTrack employee import failed', [
                    'tenant_id' => $tenantId, 'email' => $email, 'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info(($dry ? '[dry run] ' : '').sprintf(
            'created %d | linked %d | updated %d | unchanged %d | failed %d',
            $created, $linked, $updated, $skipped, $failed
        ));

        if ($dry && $tenantId <= 0) {
            $this->line('(dry run without --tenant: nothing was matched against existing CRM employees)');
        }

        if ($created > 0 && ! $dry) {
            $this->line('Next: php artisan sangoetrack:sync-attendance --tenant='.$tenantId);
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * employee_code is NOT NULL and must be stable across runs. SangoeTrack's own
     * `employee_id` wins; otherwise derive from the roster row id so a re-run
     * produces the same code rather than a new one.
     */
    private function employeeCode(array $row, int $tenantId): string
    {
        $code = trim((string) ($row['employee_id'] ?? ''));

        $candidate = $code !== ''
            ? $code
            : 'ST-'.($row['id'] ?? substr(md5((string) ($row['email'] ?? '')), 0, 8));

        // Collisions are possible if a manual employee already claimed the code.
        $suffix = 0;
        $final  = $candidate;
        while (HrEmployee::where('tenant_id', $tenantId)->where('employee_code', $final)->exists()) {
            $final = $candidate.'-'.(++$suffix);
        }

        return $final;
    }

    /** SangoeTrack has no joining date on employees; created_at is the best proxy. */
    private function joiningDate(array $row): string
    {
        foreach (['date_of_joining', 'joining_date', 'created_at'] as $field) {
            if (! empty($row[$field])) {
                try {
                    return Carbon::parse((string) $row[$field])->toDateString();
                } catch (Throwable $e) {
                    // fall through to the next candidate
                }
            }
        }

        return Carbon::today()->toDateString();
    }
}

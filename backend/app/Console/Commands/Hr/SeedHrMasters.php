<?php

namespace App\Console\Commands\Hr;

use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Starter master data for a workspace that has none.
 *
 * Departments, designations, shifts and leave types are all REQUIRED by the
 * forms that use them and all start empty, with no seeder anywhere. On a fresh
 * workspace that means the add-employee form has two required dropdowns with
 * nothing in them — so no employee can be created at all, and nothing explains
 * why. Same shape for shifts and leave.
 *
 * These are a starting point, not a policy. Every row is ordinary enough to be
 * renamed or deleted, and the command never touches a name that already exists,
 * so running it on a configured workspace adds only what is missing.
 *
 * Deliberately a command rather than a migration: it is somebody's decision that
 * a workspace should start this way, and a migration would make it everybody's.
 */
class SeedHrMasters extends Command
{
    protected $signature = 'hr:seed-masters
        {--tenant= : Restrict to one tenant id}
        {--commit : Actually write. Without this nothing is changed}';

    protected $description = 'Create starter departments, designations, shifts and leave types for tenants that have none';

    private const DEPARTMENTS = [
        ['Management', 'MGMT'], ['Human Resources', 'HR'], ['Accounts', 'ACC'],
        ['Sales', 'SALES'], ['Operations', 'OPS'], ['Engineering', 'ENG'],
        ['Procurement', 'PROC'], ['Administration', 'ADMIN'],
    ];

    private const DESIGNATIONS = [
        ['Director', 'DIR'], ['Manager', 'MGR'], ['Assistant Manager', 'AMGR'],
        ['Team Lead', 'TL'], ['Senior Executive', 'SREXE'], ['Executive', 'EXE'],
        ['Junior Executive', 'JREXE'], ['Trainee', 'TRN'],
    ];

    /**
     * name, code, category, paid, yearly limit, needs a document.
     *
     * `category` is NOT NULL and constrained to HrLeaveType::CATEGORIES — it is
     * what the leave engine reasons about, while `name` is only what people call it.
     */
    private const LEAVE_TYPES = [
        ['Casual Leave', 'CL', 'Casual', true, 12, false],
        ['Sick Leave', 'SL', 'Sick', true, 12, true],
        ['Earned Leave', 'EL', 'Earned', true, 15, false],
        ['Unpaid Leave', 'LWP', 'Unpaid', false, 0, false],
    ];

    /** name, code, full-day hours, half-day hours */
    private const SHIFTS = [
        ['General', 'GEN', 9, 4.5],
        ['Morning', 'MOR', 8, 4],
        ['Evening', 'EVE', 8, 4],
    ];

    public function handle(): int
    {
        $commit = (bool) $this->option('commit');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey((int) $t))
            ->orderBy('id')
            ->get(['id', 'name']);

        if ($tenants->isEmpty()) {
            $this->warn('No tenants matched.');

            return self::SUCCESS;
        }

        if (! $commit) {
            $this->line('DRY RUN — nothing will be written.');
            $this->newLine();
        }

        $rows = [];

        foreach ($tenants as $tenant) {
            $added = [
                'departments'  => $this->seedSimple('hr_departments', self::DEPARTMENTS, $tenant->id, $commit),
                'designations' => $this->seedSimple('hr_designations', self::DESIGNATIONS, $tenant->id, $commit),
                'shifts'       => $this->seedShifts($tenant->id, $commit),
                'leave types'  => $this->seedLeaveTypes($tenant->id, $commit),
            ];

            foreach ($added as $what => $n) {
                if ($n > 0) {
                    $rows[] = [$tenant->id, $tenant->name, $what, $commit ? "created {$n}" : "would create {$n}"];
                }
            }
        }

        if (! $rows) {
            $this->info('Every tenant already has this master data. Nothing to do.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Name', 'What', 'Outcome'], $rows);

        if (! $commit) {
            $this->newLine();
            $this->info('Re-run with --commit to write. Existing names are never touched.');
        }

        return self::SUCCESS;
    }

    /** Departments and designations share a shape: name + code. */
    private function seedSimple(string $table, array $defs, int $tenantId, bool $commit): int
    {
        $existing = DB::table($table)->where('tenant_id', $tenantId)->pluck('name')->all();
        $n = 0;

        foreach ($defs as [$name, $code]) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            $n++;

            if ($commit) {
                DB::table($table)->insert([
                    'tenant_id' => $tenantId, 'name' => $name, 'code' => $code,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $n;
    }

    private function seedShifts(int $tenantId, bool $commit): int
    {
        $existing = DB::table('hr_shifts')->where('tenant_id', $tenantId)->pluck('name')->all();
        $n = 0;

        foreach (self::SHIFTS as [$name, $code, $full, $half]) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            $n++;

            if ($commit) {
                DB::table('hr_shifts')->insert([
                    'tenant_id' => $tenantId, 'name' => $name, 'code' => $code,
                    'full_day_hours' => $full, 'half_day_hours' => $half,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $n;
    }

    private function seedLeaveTypes(int $tenantId, bool $commit): int
    {
        $existing = DB::table('hr_leave_types')->where('tenant_id', $tenantId)->pluck('name')->all();
        $n = 0;

        foreach (self::LEAVE_TYPES as [$name, $code, $category, $paid, $limit, $needsDoc]) {
            if (in_array($name, $existing, true)) {
                continue;
            }

            $n++;

            if ($commit) {
                DB::table('hr_leave_types')->insert([
                    'tenant_id' => $tenantId, 'name' => $name, 'code' => $code,
                    'category' => $category, 'paid' => $paid, 'yearly_limit' => $limit,
                    'requires_attachment' => $needsDoc, 'requires_approval' => true,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        return $n;
    }
}

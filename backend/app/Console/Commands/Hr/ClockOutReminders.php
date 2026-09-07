<?php

namespace App\Console\Commands\Hr;

use App\Models\Hr\HrAttendance;
use App\Models\Hr\HrEmployee;
use App\Models\Tenant;
use App\Services\Hr\RequestNotifier;
use App\Services\Settings\SettingsService;
use App\Support\Hr\HrSetting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nudges anyone still clocked in after a long shift.
 *
 * The app has offered a "Clock-out reminders" switch on its settings screen the
 * whole time -- "a nudge if you stay clocked in past 10 hours" -- and nothing in
 * the CRM ever sent one. The switch was decoration. The old WorkDo system did
 * send it, over WhatsApp, which is why an approved sangoe_clockout_reminder
 * template already exists on the business account.
 *
 * A forgotten clock-out is not cosmetic: the shift stays open, the day reads as
 * absent in payroll, and somebody has to raise an attendance correction to undo
 * it. One message at hour ten avoids all of that.
 *
 * ONE PER PERSON PER DAY. This runs hourly, and an employee who works twelve
 * hours would otherwise be told three times.
 */
class ClockOutReminders extends Command
{
    protected $signature = 'hr:clock-out-reminders {--tenant=} {--dry-run}';

    protected $description = 'Remind employees who are still clocked in after a long shift';

    public function __construct(
        private SettingsService $settings,
        private RequestNotifier $notifier,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenants = $this->option('tenant')
            ? Tenant::where('id', (int) $this->option('tenant'))->get()
            : Tenant::where('status', 'active')->get();

        $total = 0;

        foreach ($tenants as $tenant) {
            $total += $this->forTenant((int) $tenant->id);
        }

        $this->info(($this->option('dry-run') ? 'Would remind ' : 'Reminded ').$total.' employee(s).');

        return self::SUCCESS;
    }

    private function forTenant(int $tenantId): int
    {
        $s = $this->settings->getGroup($tenantId, HrSetting::GROUP);

        if (! filter_var($s['clock_out_reminder_enabled'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
            return 0;
        }

        $after = (float) ($s['clock_out_reminder_after_hours'] ?? 10);
        if ($after <= 0) {
            return 0;
        }

        // Open shifts only, and only today's. An unclosed shift from last week
        // is somebody's forgotten row, not somebody still at their desk — this
        // command would otherwise message them every hour forever.
        $cutoff = now()->subHours($after);

        $rows = HrAttendance::where('tenant_id', $tenantId)
            ->whereDate('date', now()->toDateString())
            ->whereNotNull('check_in')
            ->whereNull('check_out')
            ->where('check_in', '<=', $cutoff)
            ->get();

        $sent = 0;

        foreach ($rows as $row) {
            $employee = HrEmployee::with('user')->find($row->employee_id);

            if (! $employee || ! $employee->user) {
                continue;
            }

            if (! $this->wantsIt($employee->user)) {
                continue;
            }

            if ($this->alreadyToldToday($tenantId, (int) $employee->id)) {
                continue;
            }

            $hours = (int) floor(abs(now()->diffInMinutes($row->check_in)) / 60);

            if ($this->option('dry-run')) {
                $this->line("  would remind {$employee->name} (clocked in {$hours}h)");
                $sent++;

                continue;
            }

            $this->notifier->tell(
                $employee,
                'Attendance',
                'Clock-out reminder',
                "You have been clocked in for {$hours} hours. Please remember to clock out.",
                null,
                ['hours' => $hours],
            );

            $sent++;
        }

        if ($sent > 0) {
            Log::channel('hr')->info('Clock-out reminders sent', ['tenant_id' => $tenantId, 'count' => $sent]);
        }

        return $sent;
    }

    /**
     * Their own switch wins. The app shows it per person, so an admin enabling
     * the reminder for the workspace must not override somebody who turned it
     * off for themselves.
     */
    private function wantsIt($user): bool
    {
        $prefs = $user->meta['notification_prefs'] ?? [];

        return filter_var($prefs['notify_clock_reminder'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }

    private function alreadyToldToday(int $tenantId, int $employeeId): bool
    {
        return \App\Models\Notifications\HrNotification::where('tenant_id', $tenantId)
            ->where('module', 'Attendance')
            ->where('event', 'Clock-out reminder')
            ->whereDate('created_at', now()->toDateString())
            ->whereHas('recipient', fn ($q) => $q->whereIn(
                'id',
                HrEmployee::where('id', $employeeId)->select('user_id')
            ))
            ->exists();
    }
}

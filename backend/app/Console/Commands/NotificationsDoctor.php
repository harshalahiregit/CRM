<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Tenant;
use App\Models\TenantMailSetting;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Why a notification did not arrive.
 *
 * Everything in this file is a question that can only be answered ON the server
 * that failed to deliver. Three of the four things that silence notifications
 * are invisible from the code — they are deployment state — and each of them
 * fails quietly, which is why "no mail came" took so long to explain:
 *
 *  1. mail.default is 'log'. `config('mail.default')` is `env('MAIL_MAILER',
 *     'log')`, and a server running `config:cache` never reads .env, so the
 *     literal default wins. Every message is written to storage/logs and the
 *     send reports success.
 *  2. The queue is 'database' with no worker. `env('QUEUE_CONNECTION',
 *     'database')` goes the same way, and 21 mailables implement ShouldQueue —
 *     they land in the `jobs` table and sit there for ever.
 *  3. Settings → Email is empty or switched off, so TenantMailer refuses.
 *  4. The scheduler is not running, so nothing due, expiring or overdue fires
 *     at all.
 *
 * Run `php artisan notifications:doctor` on the box and it says which.
 */
class NotificationsDoctor extends Command
{
    protected $signature = 'notifications:doctor {--tenant= : only this tenant}';

    protected $description = 'Check that in-app notifications and e-mail can actually be delivered';

    private int $problems = 0;

    public function handle(): int
    {
        $this->components->info('Notification delivery check');

        $this->checkMailTransport();
        $this->checkQueue();
        $this->checkScheduler();
        $this->checkTenantSmtp();
        $this->checkBellReachability();

        $this->newLine();

        if ($this->problems === 0) {
            $this->components->info('Nothing blocking delivery on this server.');

            return self::SUCCESS;
        }

        $this->components->error($this->problems.' thing(s) will stop notifications reaching people.');

        return self::FAILURE;
    }

    /* ── 1. Where does mail actually go? ─────────────────────────────────── */

    private function checkMailTransport(): void
    {
        $default = (string) config('mail.default');

        if ($default === 'log') {
            $this->bad(
                "mail.default is 'log' — every e-mail is written to storage/logs and never sent.",
                'Set MAIL_MAILER in .env, then re-run `php artisan config:cache`. Note that the app '
                .'sends through each tenant\'s own SMTP anyway, so this mainly affects anything that '
                .'still reaches the global mailer.',
            );

            return;
        }

        $this->ok("mail.default = {$default}");
    }

    /* ── 2. Queued mail needs a worker ───────────────────────────────────── */

    private function checkQueue(): void
    {
        $conn = (string) config('queue.default');

        if ($conn === 'sync') {
            $this->ok('queue.default = sync (mail is sent inline; no worker needed)');

            return;
        }

        $this->line("  queue.default = {$conn}");

        if ($conn !== 'database' || ! Schema::hasTable('jobs')) {
            $this->warn('  Cannot inspect this queue driver from here — confirm a worker is running.');
            $this->problems++;

            return;
        }

        $waiting = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->min('available_at');
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0;

        // A backlog whose oldest entry is old means nothing is draining it.
        $stale = $oldest && (time() - (int) $oldest) > 900;

        if ($waiting > 0 && $stale) {
            $mins = (int) round((time() - (int) $oldest) / 60);
            $this->bad(
                "{$waiting} job(s) waiting, the oldest for {$mins} minutes — no queue worker is running.",
                'Mailables implement ShouldQueue, so they are sitting in the jobs table unsent. '
                .'Run `php artisan queue:work` under supervisor/systemd so it stays up.',
            );
        } elseif ($waiting > 0) {
            $this->ok("{$waiting} job(s) queued and moving");
        } else {
            $this->ok('no queue backlog');
        }

        if ($failed > 0) {
            $this->bad(
                "{$failed} failed job(s) in failed_jobs.",
                'Inspect with `php artisan queue:failed`; each one is a notification nobody received.',
            );
        }
    }

    /* ── 3. Anything time-based needs the scheduler ──────────────────────── */

    private function checkScheduler(): void
    {
        // Laravel's scheduler leaves no universal heartbeat, so infer from the
        // most recent thing a scheduled job writes.
        if (! Schema::hasTable('notifications')) {
            return;
        }

        $this->line('  scheduler: no heartbeat is recorded, so this cannot be proved from the database.');
        $this->line('  Confirm `php artisan schedule:work` (or the cron entry) is running — without it,');
        $this->line('  nothing due, expiring, overdue or reminded ever fires.');
    }

    /* ── 4. Each tenant's own SMTP ───────────────────────────────────────── */

    private function checkTenantSmtp(): void
    {
        $this->newLine();
        $this->components->info('Settings → Email, per tenant');

        $tenants = Tenant::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->whereKey($t))
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('  No tenants found.');

            return;
        }

        foreach ($tenants as $tenant) {
            $s = TenantMailSetting::where('tenant_id', $tenant->id)->first();
            $label = "tenant {$tenant->id} ({$tenant->name})";

            if (! $s) {
                $this->bad("{$label}: no SMTP configured — every e-mail is refused.",
                    'Add the SMTP server under Settings → Email and send a test message.');

                continue;
            }
            if (! $s->enabled) {
                $this->bad("{$label}: SMTP is switched OFF.", 'Turn it on under Settings → Email.');

                continue;
            }
            if (empty($s->host) || empty($s->from_email)) {
                $this->bad("{$label}: half configured (host or From address missing).",
                    'Finish it under Settings → Email.');

                continue;
            }

            $this->ok("{$label}: {$s->host}:{$s->port} as {$s->from_email}");
        }
    }

    /* ── 5. Can each kind of account receive a bell at all? ──────────────── */

    private function checkBellReachability(): void
    {
        $this->newLine();
        $this->components->info('In-app bell, per role');

        $roles = User::query()
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->select('role')->distinct()->pluck('role');

        foreach ($roles as $role) {
            $users = User::where('role', $role)
                ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
                ->get(['id', 'email']);

            $withRows = $users->filter(fn ($u) => Notification::where('user_id', $u->id)->exists())->count();
            $noEmail = $users->filter(fn ($u) => empty($u->email))->count();

            $this->line(sprintf(
                '  %-20s %d account(s), %d have received notifications%s',
                $role, $users->count(), $withRows,
                $noEmail ? ", {$noEmail} have NO e-mail address" : '',
            ));

            if ($noEmail) {
                // Not fatal — the bell still works — but that person will never
                // get the e-mail leg of anything.
                $this->problems++;
            }
        }

        // Purchase vendors are not Users and keep their own notification table;
        // saying so here stops the next person concluding they were forgotten.
        if (Schema::hasTable('purchase_vendor_notifications')) {
            $n = DB::table('purchase_vendor_notifications')->count();
            $this->line("  purchase_vendor      own table, {$n} notification(s) — they authenticate as PurchaseVendor, not User");
        }
    }

    /* ── Output helpers ──────────────────────────────────────────────────── */

    private function ok(string $msg): void
    {
        $this->line("  <fg=green>OK</> {$msg}");
    }

    private function bad(string $problem, string $fix): void
    {
        $this->problems++;
        $this->line("  <fg=red>PROBLEM</> {$problem}");
        $this->line("          <fg=yellow>fix:</> {$fix}");
    }
}

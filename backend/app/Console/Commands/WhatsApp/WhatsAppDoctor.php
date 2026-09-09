<?php

namespace App\Console\Commands\WhatsApp;

use App\Services\WhatsApp\TenantWhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Answers "why is WhatsApp not sending" without anyone opening Business Manager.
 *
 * Every failure mode here has looked identical from the CRM side -- nothing
 * arrives -- while having completely different fixes: an expired token, a
 * template that was never approved, a recipient not on the test allow-list.
 * Reads only; sends nothing.
 */
class WhatsAppDoctor extends Command
{
    protected $signature = 'whatsapp:doctor {--tenant= : check a tenant\'s own sender instead of the platform one}';

    protected $description = 'Check the WhatsApp credentials, sender and templates';

    public function handle(TenantWhatsApp $tenants): int
    {
        $tenantId = $this->option('tenant');

        $client = $tenantId ? $tenants->clientFor((int) $tenantId) : $tenants->platformClient();

        if (! $client) {
            $this->error('No WhatsApp credentials at all — neither this workspace nor .env.');

            return self::FAILURE;
        }

        if ($tenantId) {
            $this->line('Sender source: <info>'.$tenants->sourceFor((int) $tenantId).'</info>');
        }

        $identity = $client->identity();
        if (! $identity->ok) {
            $this->error('Credentials rejected: '.$identity->error);

            return self::FAILURE;
        }

        $this->info('Sender');
        $this->line('  name    : '.($identity->raw['verified_name'] ?? '—'));
        $this->line('  number  : '.($identity->raw['display_phone_number'] ?? '—'));
        $this->line('  quality : '.($identity->raw['quality_rating'] ?? '—'));
        $this->line('  status  : '.($identity->raw['status'] ?? '—'));
        $this->line('  mode    : '.($identity->raw['account_mode'] ?? '—'));

        $this->numberHealth($identity->raw);
        $this->tokenLife();
        $this->templates();

        return self::SUCCESS;
    }

    /**
     * Two states worth fixing that are NOT, on their own, why sends fail.
     *
     * Both were outstanding on this account while a System User token sent
     * perfectly well, so do not read either as the cause of a refusal — a
     * "(#131005) Access denied" is a token or asset-permission problem and
     * regenerating the token is the right move for it.
     *
     * They still matter: the display name is what every recipient sees as the
     * sender, and an expired verification blocks changing it.
     */
    private function numberHealth(array $raw): void
    {
        $name = $raw['name_status'] ?? null;
        $code = $raw['code_verification_status'] ?? null;

        if ($name === 'DECLINED') {
            $this->newLine();
            $this->error('  Display name DECLINED.');
            $this->line('  Meta rejected the business name on this number. Submit a new one at');
            $this->line('  WhatsApp Manager > Phone numbers > (number) > Business profile.');
        }

        if ($code === 'EXPIRED') {
            $this->newLine();
            $this->error('  Phone number verification EXPIRED.');
            $this->line('  Re-verify at WhatsApp Manager > Phone numbers > (number) > Verify.');
        }

        if ($name === 'DECLINED' || $code === 'EXPIRED') {
            $this->line('  Neither of these stops messages going out — this account sends fine');
            $this->line('  with both outstanding. They decide what recipients see as the sender.');
        }
    }

    /**
     * A token copied from the Graph API Explorer expires in about an hour and
     * takes every notification with it, silently. Worth shouting about.
     */
    private function tokenLife(): void
    {
        $token = (string) config('whatsapp.cloud.token');
        $res = Http::withToken($token)->get('https://graph.facebook.com/v21.0/debug_token', [
            'input_token' => $token,
        ]);

        $d = $res->json('data') ?? [];
        if ($d === []) {
            return;
        }

        $this->newLine();
        $this->info('Token');
        $this->line('  type    : '.($d['type'] ?? '—'));

        $expires = (int) ($d['expires_at'] ?? 0);
        if ($expires === 0) {
            $this->line('  expires : <info>never</info>');

            return;
        }

        // Shown in the workspace's own clock, not UTC — an admin comparing this
        // against their watch should not have to do timezone arithmetic first.
        $when = \Carbon\Carbon::createFromTimestamp($expires)->setTimezone(config('app.display_timezone', 'Asia/Kolkata'));
        $left = (int) round(now()->diffInMinutes($when, false));

        $this->line('  expires : '.$when->toDateTimeString().' ('.($left > 0 ? $left.' min from now' : 'EXPIRED').')');

        if (($d['type'] ?? '') === 'USER') {
            $this->warn('  This is a USER token. Use a System User token from Business Settings');
            $this->warn('  (Business Settings > Users > System Users > Generate token, no expiry)');
            $this->warn('  or WhatsApp will stop sending on its own within the hour.');
        }
    }

    private function templates(): void
    {
        $waba = (string) config('whatsapp.cloud.waba_id');
        if ($waba === '') {
            return;
        }

        $res = Http::withToken((string) config('whatsapp.cloud.token'))
            ->get("https://graph.facebook.com/v21.0/{$waba}/message_templates", [
                'fields' => 'name,status,category,language', 'limit' => 50,
            ]);

        $rows = $res->json('data');
        if (! is_array($rows)) {
            return;
        }

        $wanted = (string) config('whatsapp.cloud.notification_template');
        $this->newLine();
        $this->info('Templates');

        foreach ($rows as $t) {
            $mark = $t['name'] === $wanted ? '→' : ' ';
            $this->line(sprintf('  %s %-32s %-10s %s', $mark, $t['name'], $t['status'], $t['language']));
        }

        $names = array_column($rows, 'name');
        if (! in_array($wanted, $names, true)) {
            $this->newLine();
            $this->error("The notification template '{$wanted}' does not exist on this account.");
            $this->line('  Every business-initiated WhatsApp needs it. Create it with:');
            $this->line('    php artisan whatsapp:template');
        }
    }
}

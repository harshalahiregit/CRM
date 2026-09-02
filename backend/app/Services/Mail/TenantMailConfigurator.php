<?php

namespace App\Services\Mail;

use App\Models\TenantMailSetting;
use Illuminate\Support\Facades\Mail;

/**
 * Makes the tenant's Settings → Email (TenantMailSetting) the ACTIVE default
 * mailer for the current request/command, so EVERY outgoing email — the direct
 * Mail:: sends (Helpdesk, HR, Inventory, Task) as well as the TenantMailer path
 * — goes through the SMTP the admin configured in the app, never the .env
 * account. When the tenant has no usable setting this is a no-op and the .env
 * mailer stays as the last-resort fallback.
 *
 * Applied by ConfigureTenantMail (HTTP, from the authenticated user's tenant)
 * and by the per-tenant loops in the SLA / reminder console commands.
 */
class TenantMailConfigurator
{
    public function applyForTenant(?int $tenantId): bool
    {
        if (! $tenantId) {
            return false;
        }

        // withoutGlobalScopes: console commands run with no authenticated user,
        // so the BelongsToTenant auth-based scope would otherwise hide the row.
        $s = TenantMailSetting::withoutGlobalScopes()->where('tenant_id', $tenantId)->first();
        if (! $s || ! $s->isUsable()) {
            return false;
        }

        config([
            'mail.default' => 'tenant',
            'mail.mailers.tenant' => [
                'transport'    => 'smtp',
                // Explicit scheme so ssl→smtps (implicit TLS on 465) and tls→
                // STARTTLS map correctly, mirroring TenantMailer.
                'scheme'       => TenantMailer::smtpScheme($s->encryption, $s->port),
                'host'         => $s->host,
                'port'         => (int) $s->port,
                'username'     => $s->username,
                'password'     => $s->password,   // decrypted by the model cast
                'encryption'   => $s->encryption === 'none' ? null : $s->encryption,
                'timeout'      => 15,
                // Panel-managed (Plesk/cPanel) self-signed certs: keep encryption,
                // skip the peer/hostname check when the admin unticked "Verify TLS".
                'verify_peer'  => (bool) ($s->verify_peer ?? true),
                'local_domain' => parse_url((string) config('app.url', 'http://localhost'), PHP_URL_HOST),
            ],
            // The visible From must match the SMTP identity (SPF/DKIM alignment),
            // so Mailables that don't set their own From use the tenant's sender.
            'mail.from' => [
                'address' => $s->from_email,
                'name'    => $s->from_name ?: $s->from_email,
            ],
        ]);

        // Laravel caches resolved mailers by name — purge so the new config is
        // rebuilt rather than a previous request's transport being reused.
        Mail::purge('tenant');

        return true;
    }
}

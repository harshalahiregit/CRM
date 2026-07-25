<?php

namespace App\Services\Mail;

use App\Exceptions\BusinessException;
use App\Mail\Settings\TestMail;
use App\Models\TenantMailSetting;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Tenant-aware mail dispatch — the single entry point for all outgoing mail
 * in tenant context (proposal submit, OTP, contract send, …).
 *
 * Resolution rule (mirrors the old CRM's send-time settings lookup, but
 * per-tenant): a usable, enabled TenantMailSetting builds a dynamic SMTP
 * mailer for that tenant; otherwise we fall back to the global .env mailer.
 * A tenant config that exists-and-is-enabled but FAILS surfaces the error —
 * never a silent fallback, or misconfigured tenants would never notice.
 */
class TenantMailer
{
    public function settingsFor(int $tenantId): ?TenantMailSetting
    {
        $s = TenantMailSetting::forTenant($tenantId)->first();

        return ($s && $s->isUsable()) ? $s : null;
    }

    /**
     * @param  string|array  $to  one address or a list
     * @param  array  $cc  list of addresses
     */
    public function send(int $tenantId, string|array $to, Mailable $mailable, array $cc = []): void
    {
        $settings = $this->settingsFor($tenantId);
        $mailerName = $this->configureMailer($settings);

        if ($settings) {
            $mailable->from($settings->from_email, $settings->from_name ?: $settings->from_email);
            if ($settings->reply_to) {
                $mailable->replyTo($settings->reply_to);
            }
        }

        try {
            $pending = Mail::mailer($mailerName)->to($to);
            if (! empty($cc)) {
                $pending->cc($cc);
            }
            $pending->send($mailable);
        } catch (TransportExceptionInterface $e) {
            Log::channel('errors')->error('Tenant mail send failed', [
                'tenant_id' => $tenantId,
                'mailer'    => $mailerName,
                'error'     => $e->getMessage(),
            ]);
            throw new BusinessException(
                'Email could not be sent: ' . $this->safeTransportMessage($e->getMessage()),
                502
            );
        }
    }

    public function testSend(int $tenantId, string $to): void
    {
        $this->send($tenantId, $to, new TestMail());
    }

    /**
     * Build (or purge+rebuild) the per-tenant dynamic mailer. Purging first is
     * load-bearing: Laravel caches resolved mailers, so without it a previous
     * tenant's transport would be silently reused.
     */
    private function configureMailer(?TenantMailSetting $settings): string
    {
        if (! $settings) {
            return config('mail.default');
        }

        Mail::purge('tenant');

        config(['mail.mailers.tenant' => [
            'transport'  => 'smtp',
            'host'       => $settings->host,
            'port'       => $settings->port,
            'username'   => $settings->username,
            'password'   => $settings->password,
            'encryption' => $settings->encryption === 'none' ? null : $settings->encryption,
            'timeout'    => 15,
        ]]);

        return 'tenant';
    }

    /**
     * Transport errors can echo credentials in rare cases — keep the useful
     * part (connection refused / auth failed / DNS) and cap the length.
     */
    private function safeTransportMessage(string $message): string
    {
        return mb_substr(preg_replace('/\s+/', ' ', $message), 0, 200);
    }
}

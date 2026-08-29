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

        $from = $this->effectiveFrom($settings);
        if ($from) {
            $mailable->from($from['email'], $from['name']);
        }
        if ($settings && $settings->reply_to) {
            $mailable->replyTo($settings->reply_to);
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

    /**
     * Send pre-rendered HTML through the tenant's own SMTP settings.
     *
     * NotificationService (vendor activation, kickoff MoM, HR notices) renders
     * its own HTML rather than a Mailable, so it could not use send() and was
     * calling the global Mail:: facade directly — meaning Settings -> Email was
     * silently ignored on every one of those mails. This gives that path the
     * same per-tenant resolution: tenant SMTP when configured, .env otherwise.
     *
     * Unlike send(), transport errors are left to the caller to catch, because
     * NotificationService's contract is to record a status and never throw.
     */
    public function sendRawHtml(
        ?int $tenantId,
        string|array $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $attachments = [],
    ): void {
        $settings   = $tenantId ? $this->settingsFor($tenantId) : null;
        $mailerName = $this->configureMailer($settings);
        $from       = $this->effectiveFrom($settings);

        Mail::mailer($mailerName)->send([], [], function ($m) use ($to, $subject, $html, $text, $settings, $from, $attachments) {
            $m->to($to)->subject($subject)->html($html);

            if ($text !== null && $text !== '') {
                $m->text($text);
            }
            // Raw in-memory attachments — the calendar invite that rides along
            // with a meeting invitation has no file on disk to attach.
            foreach ($attachments as $a) {
                if (! empty($a['data']) && ! empty($a['name'])) {
                    $m->attachData($a['data'], $a['name'], ['mime' => $a['mime'] ?? 'application/octet-stream']);
                }
            }
            if ($from) {
                $m->from($from['email'], $from['name']);
            }
            if ($settings && $settings->reply_to) {
                $m->replyTo($settings->reply_to);
            }
        });
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
    /**
     * ST1 — the effective From for an outgoing message. A signed-in user's own
     * sender identity (mail_from_email/name) overrides the tenant default; with
     * neither set this returns null and the mailer's config default From applies.
     *
     * @return array{email:string, name:string}|null
     */
    private function effectiveFrom(?TenantMailSetting $settings): ?array
    {
        $actor = auth()->user();
        if ($actor && ! empty($actor->mail_from_email)) {
            return ['email' => $actor->mail_from_email, 'name' => $actor->mail_from_name ?: $actor->mail_from_email];
        }
        if ($settings && ! empty($settings->from_email)) {
            return ['email' => $settings->from_email, 'name' => $settings->from_name ?: $settings->from_email];
        }

        return null;
    }

    private function configureMailer(?TenantMailSetting $settings): string
    {
        if (! $settings) {
            return config('mail.default');
        }

        Mail::purge('tenant');

        config(['mail.mailers.tenant' => [
            'transport'  => 'smtp',
            // Laravel 12 builds the transport from `scheme` first and only derives
            // an implicit-TLS scheme from `encryption` when encryption==='tls' AND
            // port===465 — so an 'ssl' setting (Hostinger's SSL/465) would silently
            // fall back to plaintext 'smtp' and fail. We set `scheme` explicitly so
            // ssl→smtps (implicit TLS) and tls→STARTTLS map correctly on any port.
            'scheme'     => self::smtpScheme($settings->encryption, $settings->port),
            'host'       => $settings->host,
            'port'       => $settings->port,
            'username'   => $settings->username,
            'password'   => $settings->password,
            'encryption' => $settings->encryption === 'none' ? null : $settings->encryption,
            'timeout'    => 15,
            // Symfony reads this from the transport options and, when false,
            // skips both peer and hostname checks. Needed for panel-managed
            // mail servers whose certificate is self-signed or issued for a
            // different hostname — otherwise STARTTLS fails outright.
            'verify_peer' => (bool) ($settings->verify_peer ?? true),
        ]]);

        return 'tenant';
    }

    /**
     * Map the stored encryption choice to a Symfony/Laravel SMTP transport scheme.
     *
     *   ssl        → smtps  (implicit TLS, e.g. Hostinger port 465)
     *   tls + 465  → smtps  (some hosts run implicit TLS on 465)
     *   tls (587…) → smtp   (opportunistic STARTTLS)
     *   none/other → smtp
     *
     * Public + static so it can be unit-tested directly.
     */
    public static function smtpScheme(?string $encryption, ?int $port): string
    {
        $enc = strtolower((string) $encryption);

        if ($enc === 'ssl') {
            return 'smtps';
        }
        if ($enc === 'tls') {
            return (int) $port === 465 ? 'smtps' : 'smtp';
        }

        return 'smtp';
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

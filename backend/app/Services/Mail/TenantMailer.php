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
 * Resolution rule: a usable, enabled TenantMailSetting builds a dynamic SMTP
 * mailer for that tenant. There is NO fallback. A tenant with no SMTP set up is
 * told so, in words that name the screen to go to; a tenant whose SMTP fails
 * gets the transport error. Neither is ever swallowed, because both of those
 * used to end at the global .env mailer — which is `env('MAIL_MAILER', 'log')`,
 * so on a deployment that never set it every message was written to a log file
 * and the person who pressed Send was told it worked.
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
     *
     * `$replyTo` overrides the tenant's own reply address for one message. It
     * exists for mail we send ON SOMEONE'S BEHALF: a compliance agency handed a
     * vendor's callback request must be able to hit reply and reach the vendor,
     * not our support inbox. Everything else leaves it null and keeps the
     * tenant reply-to.
     */
    public function sendRawHtml(
        ?int $tenantId,
        string|array $to,
        string $subject,
        string $html,
        ?string $text = null,
        array $attachments = [],
        ?string $replyTo = null,
    ): void {
        $settings   = $tenantId ? $this->settingsFor($tenantId) : null;
        $mailerName = $this->configureMailer($settings);
        $from       = $this->effectiveFrom($settings);

        Mail::mailer($mailerName)->send([], [], function ($m) use ($to, $subject, $html, $text, $settings, $from, $attachments, $replyTo) {
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
            // A per-message reply address wins: the point of it is that the
            // recipient should answer the person we are writing on behalf of.
            if ($replyTo) {
                $m->replyTo($replyTo);
            } elseif ($settings && $settings->reply_to) {
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

    /**
     * The tenant's own SMTP, or a refusal that says what to do about it.
     *
     * Public so the rule can be asserted directly and so a caller can pre-flight
     * before doing expensive work it is about to throw away.
     */
    public function requireSettings(int $tenantId): TenantMailSetting
    {
        $s = TenantMailSetting::forTenant($tenantId)->first();

        if (! $s) {
            throw new BusinessException(
                'Email is not set up yet. Add your SMTP server under Settings → Email, '
                .'then send a test message to confirm it works.', 422);
        }
        if (! $s->enabled) {
            throw new BusinessException(
                'Email is switched off. Turn it on under Settings → Email.', 422);
        }
        if (empty($s->host) || empty($s->from_email)) {
            throw new BusinessException(
                'Email is only half configured — it needs both an SMTP host and a From address. '
                .'Finish it under Settings → Email.', 422);
        }

        return $s;
    }

    private function configureMailer(?TenantMailSetting $settings): string
    {
        if (! $settings) {
            // NO .env FALLBACK. Falling through to the global mailer is how mail
            // disappeared without a word: config('mail.default') is
            // env('MAIL_MAILER', 'log'), so a deployment that never set
            // MAIL_MAILER wrote every message to a log file and reported success
            // to the person who pressed Send. Worse, when it IS set, mail leaves
            // from the .env account rather than the tenant's own domain, which
            // fails SPF/DKIM and lands in spam. The tenant's SMTP is the only
            // transport this application sends real mail through.
            //
            // Under `php artisan test` the transport is `array` and nothing goes
            // anywhere, so there is no operator to protect and no delivery to
            // misattribute -- the strict path is asserted through
            // requireSettings() instead.
            if (app()->runningUnitTests()) {
                return config('mail.default');
            }

            throw new BusinessException(
                'Email is not set up yet. Add your SMTP server under Settings → Email, '
                .'then send a test message to confirm it works.', 422);
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
            // Measured against a real host: 6s for the TCP+TLS handshake alone
            // and 11s for a complete send. 15s left almost no headroom, so a
            // slow day timed out mid-send and read as "the button does nothing".
            //
            // But it must also finish BEFORE PHP gives up on the request, or the
            // process is killed mid-socket and the caller gets a fatal error
            // instead of a message it can show. That is what an unreachable mail
            // host produced: max_execution_time is 30s under the dev server and
            // this timeout was 30s too, so "Maximum execution time of 30 seconds
            // exceeded … Smtp/Stream/SocketStream.php" reached the user as a
            // frozen Publish button. We keep a few seconds of headroom, so the
            // transport always loses the race and raises a real exception.
            'timeout'    => self::socketTimeout(),
            // Symfony reads this from the transport options and, when false,
            // skips both peer and hostname checks. Needed for panel-managed
            // mail servers whose certificate is self-signed or issued for a
            // different hostname — otherwise STARTTLS fails outright.
            'verify_peer' => (bool) ($settings->verify_peer ?? true),
        ]]);

        return 'tenant';
    }

    /**
     * How long a single SMTP conversation may take — and room for it to finish.
     *
     * The timeout itself stays generous, because it is measured: ~6s for the
     * TCP+TLS handshake and ~11s for a complete send against the live host, so
     * cutting it short turns a slow day into a message that never arrives.
     *
     * The bug was never the length; it was that PHP's own request limit is 30s
     * too. An unreachable mail server held the socket until BOTH expired at
     * once, and PHP won: "Maximum execution time of 30 seconds exceeded … in
     * Smtp/Stream/SocketStream.php" is a FATAL error, not an exception, so the
     * `catch` around every send never ran and the Publish button just froze.
     *
     * So we give the request more room than the transport needs. The transport
     * then always loses the race, throws something catchable, and the caller
     * reports "the mail server did not answer" instead of dying mid-socket.
     */
    private static function socketTimeout(): int
    {
        $timeout = max(5, (int) config('mail.tenant_timeout', 30));

        // 0 means "no limit" (CLI, some FPM pools) — nothing to extend.
        if ((int) ini_get('max_execution_time') > 0) {
            // Resets the counter as well as raising it, which is right: each
            // send deserves its own budget, not a share of one the request has
            // already spent elsewhere.
            @set_time_limit($timeout + 15);
        }

        return $timeout;
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

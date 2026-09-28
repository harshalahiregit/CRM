<?php

namespace App\Sire\Host;

use App\Services\Mail\TenantMailer;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Log;
use Sire\Contracts\SireNotificationProvider;
use Sire\Contracts\SireUserProvider;
use Sire\Dto\SireNotification;

/**
 * SIRE notifications, delivered by this CRM.
 *
 * Until this existed SIRE ran on its shipped provider, which writes the decision
 * to the log and delivers nothing -- deliberately, so a freshly installed module
 * does not start mailing people. The consequence was that filing or assigning an
 * issue told NOBODY: the bell stayed empty, no mail went out, and an issue was
 * only ever found by someone opening the dashboard.
 *
 * DO NOT RE-DERIVE THE AUDIENCE HERE.
 *
 * By the time SIRE calls this it has already decided who should hear about the
 * event and applied four rules a generic engine cannot know: the actor never
 * hears about their own action, recipients are de-duplicated, low-value events
 * are collapsed to one per recipient per issue per hour, and SLA notices fire
 * once per clock rather than once per sweep. Recomputing recipients from the
 * payload would double-send.
 *
 * TWO CHANNELS
 *
 *   in-app  always -- NotificationService drops a row in the user's bell.
 *   email   only for events that mean "you now have work", through the tenant's
 *           own SMTP via TenantMailer. A defect tracker that mails on every
 *           timeline event is one people filter into a folder, and then the
 *           mail that mattered is in that folder too.
 *
 * Delivery is best-effort and swallow-logged, matching the house rule: a
 * notification failure must never roll back the workflow transition that
 * triggered it.
 */
class HostNotificationProvider implements SireNotificationProvider
{
    /**
     * Events worth an email as well as a bell.
     *
     * Each one means a named person now has to do something, or that something
     * has gone wrong on a clock. Everything else -- comments, development
     * started, notes added -- is in-app only.
     */
    private const EMAIL_EVENTS = [
        'sire.report.assigned',
        'sire.report.reassigned',
        'sire.report.ready_for_qa',
        'sire.qa.failed',
        'sire.report.reopened',
        'sire.sla.warning',
        'sire.sla.breached',
    ];

    public function __construct(
        private readonly NotificationService $inApp,
        private readonly TenantMailer $mailer,
        private readonly SireUserProvider $users,
    ) {
    }

    public function send(SireNotification $notification): void
    {
        $this->sendMany([$notification]);
    }

    /** @param  array<int, SireNotification>  $notifications */
    public function sendMany(array $notifications): void
    {
        foreach ($notifications as $n) {
            $this->deliver($n);
        }
    }

    /**
     * accepts() stays true for everything.
     *
     * An unwired preference store must not silence SIRE: a missed "QA failed"
     * stalls an issue nobody is watching. Per-user muting belongs in the CRM's
     * own preference grid, not in a default here.
     */
    public function accepts(int $tenantId, int $userId, string $event): bool
    {
        return true;
    }

    private function deliver(SireNotification $n): void
    {
        try {
            $this->inApp->notify(
                userId:   $n->recipientId,
                tenantId: $n->tenantId,
                type:     $n->event,
                title:    $n->title,
                message:  $n->body !== '' ? $n->body : null,
                link:     $n->url,
            );
        } catch (\Throwable $e) {
            Log::warning("SIRE in-app notification failed ({$n->event} -> user {$n->recipientId}): {$e->getMessage()}");
        }

        if (! in_array($n->event, self::EMAIL_EVENTS, true)) {
            return;
        }

        try {
            $this->email($n);
        } catch (\Throwable $e) {
            // The tenant may simply have no SMTP configured, which is a normal
            // state and not an error worth shouting about.
            Log::warning("SIRE email failed ({$n->event} -> user {$n->recipientId}): {$e->getMessage()}");
        }
    }

    private function email(SireNotification $n): void
    {
        $identity = $this->users->lookup($n->tenantId, $n->recipientId);
        $address  = $identity?->email;

        if (! $address) {
            // SIRE's own user provider does not read email addresses unless the
            // host asks it to (config sire.user.email_field). Without one there
            // is nowhere to send, and that is a configuration fact, not a fault.
            return;
        }

        $number = (string) ($n->metadata['report_number'] ?? '');
        $subject = $number !== '' ? "[{$number}] {$n->title}" : $n->title;

        $url  = $n->url ? rtrim((string) config('app.frontend_url', config('app.url')), '/').$n->url : null;
        $body = e($n->body !== '' ? $n->body : $n->title);

        $html = '<p>'.e($n->title).'</p>'
            .'<p style="color:#555">'.$body.'</p>'
            .($url ? '<p><a href="'.e($url).'">Open '.e($number ?: 'the issue').'</a></p>' : '')
            .'<hr><p style="color:#888;font-size:12px">Sent by SIRE, the engineering issue register.</p>';

        $this->mailer->sendRawHtml(
            $n->tenantId,
            $address,
            $subject,
            $html,
            strip_tags($n->title."\n\n".$n->body."\n\n".($url ?? '')),
        );
    }
}
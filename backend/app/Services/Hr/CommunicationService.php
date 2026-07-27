<?php

namespace App\Services\Hr;

use App\Models\Hr\HrCandidate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SPK-1 Communication Center. A thin orchestration layer over the EXISTING
 * email (Mail), WhatsApp (WhatsAppService) and audit-log services — it renders
 * per-stage templates with variables replaced, lets HR preview/edit before
 * sending, records every send on the reusable audit trail, and aggregates a
 * unified communication history. No new tables, no workflow changes.
 */
class CommunicationService
{
    public function __construct(private WhatsAppService $whatsApp)
    {
    }

    /** Per-recruitment-stage message templates. {{vars}} auto-replaced. */
    public const TEMPLATES = [
        'application_received' => [
            'label'   => 'Application Received',
            'subject' => 'We received your application - {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nThank you for applying for {{job_title}} at {{company}}. Our team is reviewing your profile and will get back to you shortly.\n\nRegards,\n{{recruiter}}\n{{company}} Talent Team",
            'whatsapp'=> "Hi {{candidate_name}}, we received your application for {{job_title}} at {{company}}. Our team will review it and get back to you. – {{recruiter}}",
        ],
        'interview_scheduled' => [
            'label'   => 'Interview Scheduled',
            'subject' => 'Interview Scheduled - {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nYour interview for {{job_title}} at {{company}} is scheduled for {{interview_date}}. Please be available a few minutes early.\n\nBest of luck!\n{{recruiter}}",
            'whatsapp'=> "Hi {{candidate_name}}, your interview for {{job_title}} is scheduled for {{interview_date}}. All the best! – {{recruiter}}",
        ],
        'interview_reminder' => [
            'label'   => 'Interview Reminder',
            'subject' => 'Reminder: Interview for {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nThis is a reminder that your interview for {{job_title}} is coming up on {{interview_date}}. We look forward to speaking with you.\n\n{{recruiter}}",
            'whatsapp'=> "Reminder: {{candidate_name}}, your {{job_title}} interview is on {{interview_date}}. – {{recruiter}}",
        ],
        'selected' => [
            'label'   => 'Selected',
            'subject' => 'Great news about your application - {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nCongratulations! You have been selected for {{job_title}} at {{company}}. Our team will reach out with the next steps.\n\nWarm regards,\n{{recruiter}}",
            'whatsapp'=> "Congratulations {{candidate_name}}! You've been selected for {{job_title}} at {{company}}. Next steps to follow. – {{recruiter}}",
        ],
        'onboarding_invite' => [
            'label'   => 'Onboarding Invite',
            'subject' => 'Welcome aboard - Onboarding for {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nWelcome to {{company}}! Please complete your onboarding steps to get started for your {{job_title}} role.\n\nRegards,\n{{recruiter}}",
            'whatsapp'=> "Welcome to {{company}}, {{candidate_name}}! Please complete your onboarding for {{job_title}}. – {{recruiter}}",
        ],
        'offer_released' => [
            'label'   => 'Offer Released',
            'subject' => 'Your Offer Letter - {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nWe are delighted to release your offer for {{job_title}} at {{company}}. Please review and respond via the offer portal.\n\nRegards,\n{{recruiter}}",
            'whatsapp'=> "Hi {{candidate_name}}, your offer for {{job_title}} at {{company}} is ready. Please review and accept. – {{recruiter}}",
        ],
        'offer_reminder' => [
            'label'   => 'Offer Reminder',
            'subject' => 'Reminder: Your Offer for {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nA gentle reminder to review and respond to your offer for {{job_title}} at {{company}}.\n\nRegards,\n{{recruiter}}",
            'whatsapp'=> "Reminder: {{candidate_name}}, please review your offer for {{job_title}} at {{company}}. – {{recruiter}}",
        ],
        'joining_reminder' => [
            'label'   => 'Joining Reminder',
            'subject' => 'Reminder: Your Joining Date - {{job_title}}',
            'email'   => "Dear {{candidate_name}},\n\nA reminder that your joining date at {{company}} is {{joining_date}}. We look forward to welcoming you!\n\nRegards,\n{{recruiter}}",
            'whatsapp'=> "Hi {{candidate_name}}, reminder that your joining date at {{company}} is {{joining_date}}. See you soon! – {{recruiter}}",
        ],
    ];

    /* ─────────────── Variables ─────────────── */

    public function variables(HrCandidate $candidate): array
    {
        $candidate->loadMissing(['jobPosting', 'assignedRecruiter', 'offer', 'onboarding']);
        $interview = $candidate->interviewRounds()->orderByDesc('scheduled_at')->first();
        $joining   = $candidate->offer?->joining_date ?? $candidate->onboarding?->joining_date;

        return [
            'candidate_name' => $candidate->name,
            'job_title'      => $candidate->jobPosting?->title ?? 'the role',
            'company'        => Tenant::find($candidate->tenant_id)?->name ?? config('app.name'),
            'interview_date' => $interview?->scheduled_at ? Carbon::parse($interview->scheduled_at)->format('D, d M Y \a\t g:i A') : 'the scheduled time',
            'joining_date'   => $joining ? Carbon::parse($joining)->format('D, d M Y') : 'your joining date',
            'recruiter'      => $candidate->assignedRecruiter?->name ?? 'The Talent Team',
        ];
    }

    private function fill(string $text, array $vars): string
    {
        foreach ($vars as $k => $v) {
            $text = str_replace('{{'.$k.'}}', (string) $v, $text);
        }

        return $text;
    }

    /** Preview an email/WhatsApp for a stage event, variables already replaced. */
    public function preview(HrCandidate $candidate, string $channel, string $event): array
    {
        $tpl  = self::TEMPLATES[$event] ?? null;
        $vars = $this->variables($candidate);

        if (! $tpl) {
            return ['subject' => '', 'body' => '', 'variables' => $vars];
        }

        return [
            'event'     => $event,
            'label'     => $tpl['label'],
            'channel'   => $channel,
            'subject'   => $channel === 'email' ? $this->fill($tpl['subject'], $vars) : null,
            'body'      => $this->fill($channel === 'whatsapp' ? $tpl['whatsapp'] : $tpl['email'], $vars),
            'variables' => $vars,
        ];
    }

    /* ─────────────── Send ─────────────── */

    /** Send via the existing Mail / WhatsAppService, then record it on the audit trail. */
    public function send(HrCandidate $candidate, string $channel, string $event, ?string $subject, string $body, ?User $actor = null): array
    {
        $label = self::TEMPLATES[$event]['label'] ?? ucfirst(str_replace('_', ' ', $event));
        $status = 'Sent';

        if ($channel === 'email') {
            if (! $candidate->email) {
                $status = 'No email on file';
            } else {
                // A transport failure must still be recorded, otherwise the send
                // throws and the history shows nothing at all for the attempt.
                try {
                    Mail::html(nl2br(e($body)), function ($m) use ($candidate, $subject) {
                        $m->to($candidate->email)->subject($subject ?: 'Update on your application');
                    });
                } catch (\Throwable $e) {
                    $status = 'Failed';
                    Log::channel('hr')->error('Candidate email failed', [
                        'candidate_id' => $candidate->id, 'event' => $event, 'error' => $e->getMessage(),
                    ]);
                }
            }
            $candidate->recordAudit('Email Sent: '.$label, $actor, $subject, ['channel' => 'email', 'event' => $event, 'comm' => true, 'status' => $status]);
        } elseif ($channel === 'whatsapp') {
            $number = $candidate->getWhatsAppNumber();
            if (! $number) {
                $status = 'No number on file';
                $candidate->recordAudit('WhatsApp Sent: '.$label, $actor, null, ['channel' => 'whatsapp', 'event' => $event, 'comm' => true, 'status' => $status]);
            } else {
                // Reuses WhatsAppService (also writes hr_whatsapp_logs).
                $log = $this->whatsApp->send($number, $body, $event, $candidate->id, $candidate->tenant_id);
                $status = ucfirst($log->status);
                $candidate->recordAudit('WhatsApp Sent: '.$label, $actor, null, ['channel' => 'whatsapp', 'event' => $event, 'comm' => true, 'status' => $status, 'log_id' => $log->id]);
            }
        }

        Log::channel('hr')->info('Candidate communication sent', ['candidate_id' => $candidate->id, 'channel' => $channel, 'event' => $event, 'status' => $status]);

        return ['success' => true, 'channel' => $channel, 'event' => $event, 'status' => $status];
    }

    /**
     * Recorded status → SPK-1 delivery state (Sent / Pending / Failed / Delivered).
     * Anything that did not leave the system counts as Failed, never as Sent.
     */
    private function deliveryFromStatus(string $status): string
    {
        $s = strtolower($status);

        // Order matters: "undelivered" contains "deliver", so failures are matched first.
        return match (true) {
            str_contains($s, 'undeliver'), str_contains($s, 'fail'),
            str_contains($s, 'no email'), str_contains($s, 'no number') => 'Failed',
            str_contains($s, 'deliver'), str_contains($s, 'read')       => 'Delivered',
            str_contains($s, 'queue'), str_contains($s, 'pending')      => 'Pending',
            str_contains($s, 'schedul')                                 => 'Scheduled',
            default                                                     => 'Sent',
        };
    }

    /* ─────────────── Reminder automation ─────────────── */

    /**
     * Configure a reminder. Interview reminders reuse the existing
     * reminder_minutes + SendInterviewReminders scheduler; offer/joining
     * reminders are recorded on the audit trail (surfaced in history/timeline).
     */
    public function scheduleReminder(HrCandidate $candidate, string $type, int $days, ?User $actor = null): array
    {
        $minutes = max(0, $days) * 1440;

        if ($type === 'interview') {
            $round = $candidate->interviewRounds()->where('status', 'Scheduled')->orderByDesc('scheduled_at')->first();
            if ($round) {
                $round->update(['reminder_minutes' => $minutes]);   // reuses existing scheduler
            }
        }

        $candidate->recordAudit('Reminder Scheduled: '.ucfirst($type).' ('.$days.' day'.($days === 1 ? '' : 's').' before)', $actor, null, [
            'channel' => 'reminder', 'event' => $type.'_reminder', 'comm' => true, 'days' => $days, 'status' => 'Scheduled',
        ]);

        return ['success' => true, 'type' => $type, 'days' => $days];
    }

    /* ─────────────── History + delivery status ─────────────── */

    /** Unified communication history (WhatsApp logs + comm audit entries), newest first. */
    public function history(HrCandidate $candidate): array
    {
        $items = collect();

        // WhatsApp — from the existing hr_whatsapp_logs.
        foreach ($candidate->whatsappLogs()->latest()->get() as $log) {
            $delivery = $log->delivered_at ? 'Delivered' : $this->deliveryFromStatus((string) ($log->status ?? 'Queued'));
            $items->push([
                'channel'  => 'WhatsApp',
                'event'    => $this->pretty($log->event_type),
                'status'   => ucfirst($log->status),
                'delivery' => $delivery,
                'sent_by'  => 'System',
                'at'       => optional($log->sent_at ?? $log->created_at)->toIso8601String(),
                // Retry re-uses the existing send endpoint; these are the arguments
                // it needs. No open-tracking exists on this channel — `opened` is
                // null so the UI can say so rather than imply it was not opened.
                'channel_key' => 'whatsapp',
                'event_key'   => $log->event_type,
                'can_retry'   => $delivery === 'Failed' && ! empty($log->event_type),
                'opened'      => null,
            ]);
        }

        // Email / Notification / Reminder — from the candidate audit trail.
        foreach ($candidate->auditLogs()->with('actor')->get() as $a) {
            $meta = $a->metadata ?? [];
            if (! $this->isCommAction($a->action, $meta)) {
                continue;
            }
            $channel = $meta['channel'] ?? $this->channelFromAction($a->action);
            if ($channel === 'whatsapp') {
                continue; // already covered by the log above
            }
            $status = $meta['status'] ?? 'Sent';
            // Reflect what actually happened rather than assuming success: a failed
            // send or a missing address must not read as "Sent".
            $delivery = $channel === 'reminder'
                ? ($meta['sent'] ?? false ? 'Reminder Sent' : 'Reminder Pending')
                : $this->deliveryFromStatus($status);

            $items->push([
                'channel'  => ucfirst($channel),
                'event'    => $a->action,
                'status'   => $status,
                'delivery' => $delivery,
                'sent_by'  => $a->actor_name ?? 'System',
                'at'       => optional($a->created_at)->toIso8601String(),
                // Retry re-uses the existing send endpoint (POST /communicate); it
                // needs the raw channel + event, which the human-readable `event`
                // label above does not carry.
                'channel_key' => $channel,
                'event_key'   => $meta['event'] ?? null,
                'can_retry'   => $delivery === 'Failed'
                    && in_array($channel, ['email', 'whatsapp'], true)
                    && ! empty($meta['event']),
                // No open-tracking is implemented (no pixel, no provider webhook).
                // null means "unknown" — the UI must say so, never imply "not opened".
                'opened'      => null,
            ]);
        }

        $sorted = $items->sortByDesc('at')->values()->all();

        return [
            'history'  => $sorted,
            'delivery' => $this->deliverySummary($candidate),
        ];
    }

    /** Delivery-status summary chips (Email / WhatsApp / Meeting Link / Reminder). */
    private function deliverySummary(HrCandidate $candidate): array
    {
        $emailSent   = $candidate->auditLogs()->where('action', 'like', 'Email Sent:%')->exists()
            || $candidate->interviewRounds()->where('email_sent_candidate', true)->exists();
        $waSent      = $candidate->whatsappLogs()->exists();
        $meetLink    = $candidate->interviewRounds()->whereNotNull('meet_link')->exists();
        $reminder    = $candidate->interviewRounds()->where('reminder_minutes', '>', 0)->exists()
            || $candidate->auditLogs()->where('action', 'like', 'Reminder Scheduled:%')->exists();

        return [
            'email'       => $emailSent,
            'whatsapp'    => $waSent,
            'meeting_link'=> $meetLink,
            'reminder'    => $reminder,
        ];
    }

    private function isCommAction(?string $action, array $meta): bool
    {
        if (! empty($meta['comm'])) {
            return true;
        }
        $a = strtolower((string) $action);

        return (bool) preg_match('/(email|whatsapp|notification|reminder|interview scheduled|offer (sent|released|accepted)|onboarding (invitation|welcome|started)|congratulation|selected)/', $a);
    }

    private function channelFromAction(?string $action): string
    {
        $a = strtolower((string) $action);
        if (str_contains($a, 'whatsapp')) {
            return 'whatsapp';
        }
        if (str_contains($a, 'reminder')) {
            return 'reminder';
        }
        if (str_contains($a, 'email')) {
            return 'email';
        }

        return 'notification';
    }

    private function pretty(?string $event): string
    {
        return ucwords(str_replace(['_', '-'], ' ', (string) $event));
    }
}

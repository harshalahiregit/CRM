<?php

namespace App\Services\Shared;

use App\Models\Notification;
use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\MeetingDistribution;
use App\Models\User;
use App\Services\Helpdesk\Contracts\CustomerServiceContract;
use App\Services\Notifications\NotificationService;
use App\Support\FrontendUrl;
use App\Support\Shared\KickoffSubject;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The meeting's two outbound sends (Meeting.docx §1 "Schedule / Send Invitation"
 * and §13 "MOM Distribution"), and the per-recipient ledger behind them.
 *
 * Split out of KickoffMeetingService deliberately: that class already owns the
 * meeting lifecycle, and everything here is about WHO a send reaches and what
 * happened to it. It writes meeting_distributions rows so "Sent / Viewed /
 * Acknowledged" can be answered per person rather than per meeting.
 *
 * Nothing here throws: a meeting must still be created, and minutes must still
 * be approved, when the mail server is down. Every recipient's outcome is
 * recorded — including 'skipped' for someone with no address, which must never
 * read as delivered.
 */
class MeetingInviteService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * Send (or re-send) the invitation to everyone on the roster.
     *
     * Called automatically when a meeting is scheduled with a date, and again
     * from the "Send invitation" button after the roster or the time changes.
     *
     * @return array{sent:int, skipped:int, failed:int, in_app:int, recipients:int}
     */
    public function sendInvitations(KickoffMeeting $meeting, ?User $actor = null): array
    {
        $meeting->loadMissing(['attendees', 'kickoffable', 'agendaItems']);

        $subject = 'Meeting invitation — '.$meeting->title;
        $ics = $this->buildIcs($meeting);
        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'in_app' => 0, 'recipients' => 0];

        // A fresh invitation supersedes the previous one; the old rows are the
        // history of an earlier time/roster and would double-count the tracker.
        MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_INVITE)->delete();

        foreach ($this->recipients($meeting) as $r) {
            $counts['recipients']++;

            $status = MeetingDistribution::SKIPPED;
            if ($r['email']) {
                $result = $this->notifications->emailHtml(
                    $r['email'],
                    $subject,
                    $this->renderInvite($meeting, $r['name']),
                    ['category' => 'System', 'kickoff_meeting_id' => $meeting->id],
                    $this->inviteText($meeting, $r['name']),
                    $meeting->tenant_id,
                    // The calendar invite — §1's "Send Invitation" is not an
                    // e-mail somebody has to retype into their diary.
                    [['data' => $ics, 'name' => 'meeting.ics', 'mime' => 'text/calendar; charset=utf-8; method=REQUEST']],
                );
                $status = match ($result) {
                    'sent' => MeetingDistribution::SENT,
                    'failed' => MeetingDistribution::FAILED,
                    default => MeetingDistribution::SKIPPED,
                };
            }

            $counts[$status === MeetingDistribution::SENT ? 'sent'
                : ($status === MeetingDistribution::FAILED ? 'failed' : 'skipped')]++;

            MeetingDistribution::create([
                'tenant_id' => $meeting->tenant_id,
                'kickoff_meeting_id' => $meeting->id,
                'kind' => MeetingDistribution::KIND_INVITE,
                'kickoff_attendee_id' => $r['attendee_id'],
                'user_id' => $r['user_id'],
                'party' => $r['party'],
                'name' => $r['name'],
                'email' => $r['email'],
                'channel' => 'email',
                'status' => $status,
                'sent_at' => $status === MeetingDistribution::SENT ? now() : null,
            ]);

            // In-app notification for anyone with a Sangoe login (§13 "Sangoe
            // notification") — this reaches internal staff whose e-mail we do
            // not hold, which is most of them.
            if ($r['user_id'] && $this->notifyInApp(
                $meeting, (int) $r['user_id'],
                'Meeting invitation: '.$meeting->title,
                $this->whenLine($meeting),
            )) {
                $counts['in_app']++;
            }
        }

        $meeting->recordAudit('invitations_sent', $actor,
            "Invitations sent: {$counts['sent']} e-mailed, {$counts['in_app']} in-app, {$counts['skipped']} without an address");
        Log::channel('tpv')->info('Meeting invitations sent', ['meeting_id' => $meeting->id] + $counts);

        return $counts;
    }

    /**
     * Record one MOM recipient in the ledger and hand back the read token to put
     * in their link. Called by KickoffMeetingService as it distributes.
     */
    public function recordMomRecipient(
        KickoffMeeting $meeting,
        string $party,
        ?string $name,
        ?string $email,
        ?int $attendeeId = null,
        ?int $userId = null,
    ): MeetingDistribution {
        return MeetingDistribution::create([
            'tenant_id' => $meeting->tenant_id,
            'kickoff_meeting_id' => $meeting->id,
            'kind' => MeetingDistribution::KIND_MOM,
            'kickoff_attendee_id' => $attendeeId,
            'user_id' => $userId,
            'party' => $party,
            'name' => $name,
            'email' => $email,
            'channel' => 'email',
            'token' => Str::random(48),
            'status' => $email ? MeetingDistribution::SENT : MeetingDistribution::SKIPPED,
            'sent_at' => $email ? now() : null,
        ]);
    }

    /** Clear the previous MOM send before recording a new one. */
    public function resetMomLedger(KickoffMeeting $meeting): void
    {
        MeetingDistribution::where('kickoff_meeting_id', $meeting->id)
            ->where('kind', MeetingDistribution::KIND_MOM)->delete();
    }

    /**
     * Stamp one recipient as having opened the minutes. Idempotent — the first
     * view is the signal; re-reads do not move it.
     */
    public function markViewed(MeetingDistribution $row): void
    {
        if ($row->viewed_at === null) {
            $row->forceFill(['viewed_at' => now()])->saveQuietly();
        }
    }

    /** In-app notification for a Sangoe user. Never throws. */
    public function notifyInApp(KickoffMeeting $meeting, int $userId, string $title, string $message): bool
    {
        try {
            Notification::create([
                'tenant_id' => $meeting->tenant_id,
                'user_id' => $userId,
                'type' => 'meeting',
                'title' => $title,
                'message' => $message,
                'link' => '/app/tpv/kickoff/'.$meeting->id,
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::channel('tpv')->warning('Meeting in-app notification failed', [
                'meeting_id' => $meeting->id, 'user_id' => $userId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Everyone who should hear about this meeting, with their §13 party.
     *
     * The party is derived, not guessed: a roster row says whether it is our own
     * side or the other one, and an explicitly Management role is called out
     * because §13 lists Management as its own distribution group.
     *
     * @return array<int, array{name:?string, email:?string, party:string, attendee_id:?int, user_id:?int}>
     */
    public function recipients(KickoffMeeting $meeting): array
    {
        $meeting->loadMissing('attendees');
        $out = [];
        $seen = [];

        foreach ($meeting->attendees as $a) {
            $key = strtolower((string) ($a->email ?: 'row#'.$a->id));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $out[] = [
                'name' => $a->name,
                'email' => $a->email,
                'party' => $this->partyFor($a),
                'attendee_id' => $a->id,
                'user_id' => $a->user_id,
            ];
        }

        return $out;
    }

    /** §13's group for one roster row. */
    public function partyFor(KickoffAttendee $a): string
    {
        $role = strtolower((string) $a->role);

        if (str_contains($role, 'management') || str_contains($role, 'director')) {
            return MeetingDistribution::PARTY_MANAGEMENT;
        }
        if (str_contains($role, 'client') || str_contains($role, 'customer')) {
            return MeetingDistribution::PARTY_CLIENT;
        }
        if (str_contains($role, 'vendor') || str_contains($role, 'contractor')) {
            return MeetingDistribution::PARTY_VENDOR;
        }

        return strtolower((string) $a->side) === 'internal'
            ? MeetingDistribution::PARTY_INTERNAL
            : MeetingDistribution::PARTY_OTHER;
    }

    /** The customer on the meeting, when one is linked — a §13 recipient group. */
    public function clientRecipient(KickoffMeeting $meeting): ?array
    {
        if (! $meeting->client_id) {
            return null;
        }

        try {
            $c = app(CustomerServiceContract::class)
                ->getCustomer((int) $meeting->client_id, (int) $meeting->tenant_id);
        } catch (\Throwable $e) {
            return null;
        }

        if (! $c || empty($c['email'])) {
            return null;
        }

        return ['name' => $c['company'] ?? $c['name'] ?? 'Client', 'email' => $c['email']];
    }

    /* ── rendering ──────────────────────────────────────────────── */

    private function whenLine(KickoffMeeting $meeting): string
    {
        $when = $meeting->scheduled_at?->format('D, d M Y · h:i A') ?: 'Date to be confirmed';
        $where = $meeting->location ?: ($meeting->mode ? ucfirst($meeting->mode) : null);

        return $where ? $when.' — '.$where : $when;
    }

    private function renderInvite(KickoffMeeting $meeting, ?string $name): string
    {
        return view('emails.shared.meeting_invite', [
            'meeting' => $meeting,
            'recipientName' => $name ?: 'Sir/Madam',
            'whenLine' => $this->whenLine($meeting),
            'agendaItems' => $meeting->agendaItems,
            'subjectName' => KickoffSubject::nameOf($meeting->kickoffable),
            'url' => FrontendUrl::to('/app/tpv/kickoff/'.$meeting->id),
            'companyName' => config('app.name', 'Our Company'),
            'logoUrl' => config('mail.logo_url'),
        ])->render();
    }

    private function inviteText(KickoffMeeting $meeting, ?string $name): string
    {
        $lines = ['Dear '.($name ?: 'Sir/Madam').',', ''];
        $lines[] = 'You are invited to the following meeting.';
        $lines[] = '';
        $lines[] = 'Meeting: '.$meeting->title;
        $lines[] = 'Reference: '.($meeting->meeting_no ?: '#'.$meeting->id);
        $lines[] = 'Type: '.$meeting->meeting_type_label;
        $lines[] = 'When: '.$this->whenLine($meeting);
        if ($meeting->chairperson) {
            $lines[] = 'Chairperson: '.$meeting->chairperson;
        }

        foreach ($meeting->agendaItems as $i => $item) {
            if ($i === 0) {
                $lines[] = '';
                $lines[] = 'Agenda:';
            }
            $lines[] = ($i + 1).'. '.$item->item;
        }

        $lines[] = '';
        $lines[] = 'The calendar invite is attached.';

        return implode("\n", $lines);
    }

    /**
     * A minimal but valid iCalendar VEVENT.
     *
     * Hand-built rather than pulling in a library: the invite needs six fields,
     * and a new dependency for that is not a trade worth making. Times are
     * emitted in UTC (the trailing Z), which every calendar client localises.
     */
    private function buildIcs(KickoffMeeting $meeting): string
    {
        $start = $meeting->scheduled_at ?: now()->addDay();
        $end = $meeting->end_at
            ?: (clone $start)->addMinutes($meeting->duration_minutes ?: 60);

        $esc = fn ($v) => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], (string) $v);
        $stamp = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');

        $description = trim(implode('\n', array_filter([
            $meeting->meeting_type_label,
            $meeting->agenda ? 'Agenda: '.$meeting->agenda : null,
            $meeting->chairperson ? 'Chairperson: '.$meeting->chairperson : null,
        ])));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sangoe//Meetings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:meeting-'.$meeting->id.'@sangoe',
            'DTSTAMP:'.$stamp(now()),
            'DTSTART:'.$stamp($start),
            'DTEND:'.$stamp($end),
            'SUMMARY:'.$esc($meeting->title),
            'DESCRIPTION:'.$esc($description),
            'LOCATION:'.$esc($meeting->meeting_link ?: $meeting->location),
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}

<?php

namespace App\Services\Shared;

use App\Models\Project\ProjectMeeting;
use App\Models\Purchase\PurchaseContact;
use App\Models\Purchase\PurchaseKickoffMeeting;
use App\Models\Shared\MeetingDistribution;
use App\Models\User;
use App\Models\Vendor\VendorContact;
use App\Services\Mail\TenantMailer;
use App\Services\Notifications\NotificationService;
use App\Support\FrontendUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * "Send everyone the actual link, the moment I have it."
 *
 * The invitation deliberately carries no join link — it goes out days early,
 * when the only link that exists is meet.google.com/new, which opens a
 * DIFFERENT empty room for every person who clicks it. What was missing is the
 * send that happens afterwards: the organiser starts the call, pastes the real
 * room URL, and at that instant everyone invited should receive that URL.
 *
 * ── Whatever they are ────────────────────────────────────────────────────
 * A roster row often has no e-mail — it was typed as a name, or picked from a
 * directory that filled in everything except the address. Skipping those people
 * is what made "the link was sent" a statement nobody could rely on. So an
 * address is resolved, in order: the row's own e-mail, the linked login
 * account, the contact record it was picked from, and for the vendor's own row
 * the vendor master. Only when all four come back empty is the person reported
 * BY NAME as unreachable, so the admin can fix the record rather than discover
 * the gap in the meeting.
 *
 * ── One transport ────────────────────────────────────────────────────────
 * Everything goes through NotificationService → TenantMailer, which resolves
 * the tenant's own SMTP settings from the database. There is no .env fallback
 * and nothing here reads config('mail.*') for a host or a credential. A tenant
 * with no SMTP configured is told so, in the endpoint's own response, instead of
 * having its mail written to a log file and reported as sent.
 *
 * ── Both engines ─────────────────────────────────────────────────────────
 * Typed to Model, like {@see MeetingAttendanceGate}: the shared engine's roster
 * is `attendees` and Purchase's is `participants`, and everything else about
 * this send is identical. Two copies of it would drift, as every other pair of
 * meeting features on these two engines has.
 */
class MeetingLinkAnnouncer
{
    public function __construct(
        private NotificationService $notifications,
        private TenantMailer $mailer,
    ) {}

    /**
     * Send the room link to everyone, after the HTTP response has been flushed.
     *
     * The organiser pastes the link while the meeting is starting; opening one
     * SMTP session per recipient inline is exactly how publishing a meeting used
     * to time out at thirty seconds with the work already committed. Nothing
     * waits on the result, so `terminating` costs the caller nothing.
     *
     * The counts the caller returns are therefore the PLANNED send — who it will
     * reach and who it cannot — computed synchronously and cheaply. That is the
     * half the admin has to act on.
     */
    public function announceAfterResponse(Model $meeting, ?User $actor = null): array
    {
        $plan = $this->plan($meeting);

        app()->terminating(function () use ($meeting, $actor) {
            try {
                $this->announce($meeting, $actor);
            } catch (\Throwable $e) {
                Log::warning('Meeting link announcement failed', [
                    'meeting' => $meeting::class.'#'.$meeting->getKey(),
                    'error' => $e->getMessage(),
                ]);
            }
        });

        return $plan;
    }

    /**
     * Who this send will reach, without sending anything.
     *
     * @return array{recipients:int, reachable:int, unreachable:array<int,array{name:string,reason:string}>, smtp_ready:bool, smtp_reason:?string}
     */
    public function plan(Model $meeting): array
    {
        $smtpReason = $this->smtpReason($meeting);
        $recipients = $this->recipients($meeting);

        $unreachable = [];
        foreach ($recipients as $r) {
            if (! $r['email']) {
                $unreachable[] = [
                    'name' => $r['name'] ?: 'An unnamed participant',
                    'reason' => 'No e-mail address on this person, their login or their contact record.',
                ];
            }
        }

        if ($smtpReason) {
            // Nobody is reachable when the transport itself is not configured,
            // and saying "sent to 7 people" in that state is the exact lie this
            // whole path exists to stop telling.
            $unreachable = array_map(
                fn ($r) => ['name' => $r['name'] ?: 'An unnamed participant', 'reason' => $smtpReason],
                $recipients,
            );
        }

        return [
            'recipients' => count($recipients),
            'reachable' => count($recipients) - count($unreachable),
            'unreachable' => array_values($unreachable),
            'smtp_ready' => $smtpReason === null,
            'smtp_reason' => $smtpReason,
        ];
    }

    /**
     * Do the send.
     *
     * @return array{sent:int, skipped:int, failed:int, in_app:int, recipients:int, unreachable:array<int,array{name:string,reason:string}>, smtp_ready:bool, smtp_reason:?string}
     */
    public function announce(Model $meeting, ?User $actor = null): array
    {
        $link = (string) $meeting->meeting_link;

        // An instant-start URL is not a room. Mailing it would put every
        // recipient in an empty meeting of their own, which is worse than
        // sending nothing, so this send simply does not happen until there is
        // a real room to send.
        if ($link === '' || OnlineMeetingService::isInstant($link)) {
            return [
                'sent' => 0, 'skipped' => 0, 'failed' => 0, 'in_app' => 0,
                'recipients' => 0, 'unreachable' => [],
                'smtp_ready' => false,
                'smtp_reason' => 'The meeting has no shared room link yet, so nothing was sent.',
            ];
        }

        $smtpReason = $this->smtpReason($meeting);
        $engine = $this->engineOf($meeting);
        $recipients = $this->recipients($meeting);

        $counts = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'in_app' => 0, 'recipients' => 0];
        $unreachable = [];

        // A fresh announcement supersedes the last one: the ledger answers
        // "does this person have the CURRENT room?", not how many rooms we have
        // been through.
        MeetingDistribution::where('kickoff_meeting_id', $meeting->getKey())
            ->where('engine', $engine)
            ->where('kind', MeetingDistribution::KIND_LINK)
            ->delete();

        $subject = 'Joining link — '.($meeting->title ?: 'your meeting');
        $ics = $this->buildIcs($meeting, $link);

        // Set the moment the mail server fails to answer, so the remaining
        // recipients are not each made to wait for the same dead socket.
        $transportDown = null;

        foreach ($recipients as $r) {
            $counts['recipients']++;

            $status = MeetingDistribution::SKIPPED;
            $error = null;

            if ($smtpReason) {
                $error = $smtpReason;
            } elseif ($r['email'] && $transportDown) {
                // The mail server already failed to answer once. Trying the rest
                // one at a time only multiplies the wait: an unreachable host
                // costs a full socket timeout EACH, so a meeting with seven
                // participants hung the Publish button for seven timeouts and
                // then reported the same fault seven times. One attempt is
                // enough to know the server is down; the rest are told so
                // immediately and can be re-sent from the meeting page once it
                // is back.
                $error = $transportDown;
            } elseif ($r['email']) {
                $url = $this->meetingUrlFor($meeting, $r['party']);
                $result = $this->notifications->emailHtml(
                    $r['email'],
                    $subject,
                    $this->renderHtml($meeting, $r['name'], $link, $url),
                    ['category' => 'System', 'kickoff_meeting_id' => $meeting->getKey()],
                    $this->renderText($meeting, $r['name'], $link, $url),
                    $meeting->tenant_id,
                    [['data' => $ics, 'name' => 'meeting.ics', 'mime' => 'text/calendar; charset=utf-8; method=REQUEST']],
                );
                $status = match ($result) {
                    'sent' => MeetingDistribution::SENT,
                    'failed' => MeetingDistribution::FAILED,
                    default => MeetingDistribution::SKIPPED,
                };

                if ($status === MeetingDistribution::FAILED) {
                    $transportDown = 'The mail server did not answer. Check Settings → Email, '
                        .'then use "Resend link to everyone" on this meeting.';
                }
            }

            $counts[$status === MeetingDistribution::SENT ? 'sent'
                : ($status === MeetingDistribution::FAILED ? 'failed' : 'skipped')]++;

            if ($status !== MeetingDistribution::SENT) {
                $unreachable[] = [
                    'name' => $r['name'] ?: ($r['email'] ?: 'An unnamed participant'),
                    'reason' => $error
                        ?: ($r['email']
                            ? 'The mail server refused the message for '.$r['email'].'.'
                            : 'No e-mail address on this person, their login or their contact record.'),
                ];
            }

            MeetingDistribution::create([
                'tenant_id' => $meeting->tenant_id,
                'kickoff_meeting_id' => $meeting->getKey(),
                'engine' => $engine,
                'kind' => MeetingDistribution::KIND_LINK,
                'kickoff_attendee_id' => $engine === MeetingDistribution::ENGINE_SHARED ? $r['attendee_id'] : null,
                'user_id' => $r['user_id'],
                'party' => $r['party'],
                'name' => $r['name'],
                'email' => $r['email'],
                'channel' => 'email',
                'status' => $status,
                'error' => $error,
                'sent_at' => $status === MeetingDistribution::SENT ? now() : null,
            ]);

            // The bell as well, for anyone with a login. It reaches internal
            // staff whose address we do not hold, which is most of them, and it
            // is the one channel that cannot be misconfigured.
            if ($r['user_id'] && $this->notifyInApp($meeting, (int) $r['user_id'], $r['party'])) {
                $counts['in_app']++;
            }
        }

        $counts['unreachable'] = array_values($unreachable);
        $counts['smtp_ready'] = $smtpReason === null;
        $counts['smtp_reason'] = $smtpReason;

        if (method_exists($meeting, 'recordAudit')) {
            $meeting->recordAudit('meeting_link_sent', $actor,
                "Joining link sent: {$counts['sent']} e-mailed, {$counts['in_app']} in-app"
                .($counts['unreachable'] ? ' — not reached: '.implode(', ', array_column($counts['unreachable'], 'name')) : ''));
        }

        Log::info('Meeting joining link announced', [
            'meeting' => $meeting::class.'#'.$meeting->getKey(),
        ] + array_diff_key($counts, ['unreachable' => 1]));

        return $counts;
    }

    /**
     * Everyone who should get the link, with an address found wherever it lives.
     *
     * @return array<int, array{name:?string, email:?string, party:string, attendee_id:?int, user_id:?int}>
     */
    public function recipients(Model $meeting): array
    {
        if ($meeting instanceof ProjectMeeting) {
            return $this->projectRecipients($meeting);
        }

        $roster = $this->rosterName($meeting);
        $meeting->loadMissing([$roster, 'creator']);

        $out = [];
        $seen = [];

        $add = function (?string $name, ?string $email, string $party, ?int $attendeeId, ?int $userId) use (&$out, &$seen) {
            // De-duplicated by address where there is one, and by roster row
            // where there is not — otherwise every address-less person would
            // collapse into a single "recipient" and the admin would be told
            // about one unreachable name instead of four.
            $key = $email ? strtolower($email) : ('row#'.$attendeeId.'#user'.$userId.'#'.$name);
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'name' => $name,
                'email' => $email,
                'party' => $party,
                'attendee_id' => $attendeeId,
                'user_id' => $userId,
            ];
        };

        foreach ($meeting->{$roster} as $a) {
            $add(
                $a->name,
                $this->addressOf($a),
                $this->partyOf($a),
                (int) $a->id,
                $a->user_id ? (int) $a->user_id : null,
            );
        }

        // The vendor the meeting is ABOUT. Frequently not on the roster at all
        // — the organiser lists the people who will speak — and they are the one
        // party the link is least optional for.
        $vendor = $this->vendorOf($meeting);
        if ($vendor) {
            $add(
                $vendor->company_name ?? $vendor->name ?? 'Vendor',
                $vendor->email ?: null,
                MeetingDistribution::PARTY_VENDOR,
                null,
                $vendor->user_id ? (int) $vendor->user_id : null,
            );
        }

        if ($meeting->creator) {
            $add(
                $meeting->creator->name,
                $meeting->creator->email ?: null,
                MeetingDistribution::PARTY_INTERNAL,
                null,
                (int) $meeting->creator->id,
            );
        }

        return $out;
    }

    /**
     * A project meeting's people.
     *
     * There is no roster table here: `participants` is one free-text column
     * somebody typed, holding names, addresses, or both, separated by whatever
     * they felt like. So each entry is taken as written and resolved the same
     * way everything else is — an address is used as-is, a bare name is looked
     * up among this tenant's users, and anyone left is reported by name rather
     * than dropped. The project manager who created the meeting is added too.
     *
     * @return array<int, array{name:?string, email:?string, party:string, attendee_id:?int, user_id:?int}>
     */
    private function projectRecipients(ProjectMeeting $meeting): array
    {
        $meeting->loadMissing('creator');

        $out = [];
        $seen = [];

        $add = function (?string $name, ?string $email, ?int $userId) use (&$out, &$seen) {
            $key = $email ? strtolower($email) : 'name#'.strtolower((string) $name);
            if ($key === 'name#' || isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $out[] = [
                'name' => $name,
                'email' => $email,
                'party' => MeetingDistribution::PARTY_INTERNAL,
                'attendee_id' => null,
                'user_id' => $userId,
            ];
        };

        foreach (preg_split('/[,;\n\r]+/', (string) $meeting->participants) ?: [] as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }

            if (filter_var($entry, FILTER_VALIDATE_EMAIL)) {
                $user = User::where('tenant_id', $meeting->tenant_id)->where('email', $entry)->first();
                $add($user?->name ?: $entry, $entry, $user?->id);

                continue;
            }

            // A typed name. One exact match in this tenant is a resolution; two
            // is an ambiguity, and guessing between two colleagues is worse than
            // telling the admin to put an address on the meeting.
            $matches = User::where('tenant_id', $meeting->tenant_id)->where('name', $entry)->limit(2)->get();
            $add($entry, $matches->count() === 1 ? $matches->first()->email : null,
                $matches->count() === 1 ? $matches->first()->id : null);
        }

        if ($meeting->creator) {
            $add($meeting->creator->name, $meeting->creator->email ?: null, (int) $meeting->creator->id);
        }

        return $out;
    }

    /* ── resolving an address ────────────────────────────────────────────── */

    /**
     * The row's address, or the best one the database can give for this person.
     *
     * Four places, in falling order of authority: what was typed on the row, the
     * login account it is linked to, the contact record it was picked from, and
     * — via the caller — the vendor master. Each is a real relationship already
     * stored on the row; nothing is guessed from a name.
     */
    private function addressOf(Model $row): ?string
    {
        if (! empty($row->email)) {
            return $row->email;
        }

        if ($row->user_id && ($user = User::find($row->user_id)) && $user->email) {
            return $user->email;
        }

        if (! empty($row->vendor_contact_id)) {
            $c = VendorContact::find($row->vendor_contact_id);
            if ($c && $c->email) {
                return $c->email;
            }
        }

        if (! empty($row->purchase_contact_id)) {
            $c = PurchaseContact::find($row->purchase_contact_id);
            if ($c && $c->email) {
                return $c->email;
            }
        }

        return null;
    }

    private function partyOf(Model $row): string
    {
        $stored = strtolower((string) $row->party);
        if (in_array($stored, [MeetingDistribution::PARTY_VENDOR, MeetingDistribution::PARTY_CLIENT], true)) {
            return $stored;
        }

        $role = strtolower((string) $row->role);
        if (str_contains($role, 'management') || str_contains($role, 'director')) {
            return MeetingDistribution::PARTY_MANAGEMENT;
        }
        if (str_contains($role, 'client') || str_contains($role, 'customer')) {
            return MeetingDistribution::PARTY_CLIENT;
        }
        if (str_contains($role, 'vendor') || str_contains($role, 'contractor')) {
            return MeetingDistribution::PARTY_VENDOR;
        }

        return strtolower((string) $row->side) === 'internal'
            ? MeetingDistribution::PARTY_INTERNAL
            : MeetingDistribution::PARTY_OTHER;
    }

    /* ── engine differences, in one place ────────────────────────────────── */

    public function engineOf(Model $meeting): string
    {
        return match (true) {
            $meeting instanceof PurchaseKickoffMeeting => MeetingDistribution::ENGINE_PURCHASE,
            $meeting instanceof ProjectMeeting => MeetingDistribution::ENGINE_PROJECT,
            default => MeetingDistribution::ENGINE_SHARED,
        };
    }

    private function rosterName(Model $meeting): string
    {
        return method_exists($meeting, 'attendees') ? 'attendees' : 'participants';
    }

    private function vendorOf(Model $meeting): ?object
    {
        if (method_exists($meeting, 'vendor')) {
            return $meeting->vendor;
        }
        if (method_exists($meeting, 'kickoffable')) {
            return $meeting->kickoffable;
        }

        return null;
    }

    /** Where this recipient opens the meeting — a vendor has no staff console. */
    private function meetingUrlFor(Model $meeting, string $party): string
    {
        if ($meeting instanceof ProjectMeeting) {
            // The meeting lives on the project's own Meetings tab; there is no
            // page of its own to link to.
            return FrontendUrl::to('/app/projects/'.$meeting->project_id);
        }

        if ($party !== MeetingDistribution::PARTY_VENDOR) {
            return $this->engineOf($meeting) === MeetingDistribution::ENGINE_PURCHASE
                ? FrontendUrl::to('/app/purchase/kickoff/'.$meeting->getKey())
                : FrontendUrl::to('/app/tpv/kickoff/'.$meeting->getKey());
        }

        return $this->engineOf($meeting) === MeetingDistribution::ENGINE_PURCHASE
            ? FrontendUrl::to('/purchase-portal/governance')
            : FrontendUrl::to('/vendor-portal/governance');
    }

    /**
     * Why mail cannot leave this tenant, in words naming the screen to fix it.
     *
     * Asked BEFORE the send rather than discovered per recipient: the answer is
     * the same for all of them, and "SMTP is not configured" repeated seven
     * times reads as seven different faults.
     */
    private function smtpReason(Model $meeting): ?string
    {
        try {
            $this->mailer->requireSettings((int) $meeting->tenant_id);

            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }

    private function notifyInApp(Model $meeting, int $userId, string $party): bool
    {
        try {
            \App\Models\Notification::create([
                'tenant_id' => $meeting->tenant_id,
                'user_id' => $userId,
                'type' => 'meeting',
                'title' => 'Joining link ready: '.$meeting->title,
                'message' => 'The organiser has shared the meeting room. '.$this->whenLine($meeting),
                'link' => match (true) {
                    $meeting instanceof ProjectMeeting => '/app/projects/'.$meeting->project_id,
                    $party === MeetingDistribution::PARTY_VENDOR => $this->engineOf($meeting) === MeetingDistribution::ENGINE_PURCHASE
                        ? '/purchase-portal/governance' : '/vendor-portal/governance',
                    default => $this->engineOf($meeting) === MeetingDistribution::ENGINE_PURCHASE
                        ? '/app/purchase/kickoff/'.$meeting->getKey()
                        : '/app/tpv/kickoff/'.$meeting->getKey(),
                },
            ]);

            return true;
        } catch (\Throwable $e) {
            Log::warning('Meeting link in-app notification failed', [
                'meeting' => $meeting::class.'#'.$meeting->getKey(),
                'user_id' => $userId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /* ── rendering ───────────────────────────────────────────────────────── */

    private function whenLine(Model $meeting): string
    {
        $when = $meeting->scheduled_at?->format('D, d M Y · h:i A T') ?: 'Date to be confirmed';
        $where = $meeting->location ?: ($meeting->mode ? ucfirst($meeting->mode) : null);

        return $where ? $when.' — '.$where : $when;
    }

    private function renderHtml(Model $meeting, ?string $name, string $link, string $url): string
    {
        return view('emails.shared.meeting_link', [
            'meeting' => $meeting,
            'recipientName' => $name ?: 'Sir/Madam',
            'whenLine' => $this->whenLine($meeting),
            'joinLink' => $link,
            'platformLabel' => OnlineMeetingService::LABELS[$meeting->meeting_platform] ?? 'Online meeting',
            'url' => $url,
            'companyName' => config('app.name', 'Our Company'),
            'logoUrl' => config('mail.logo_url'),
        ])->render();
    }

    private function renderText(Model $meeting, ?string $name, string $link, string $url): string
    {
        $lines = ['Dear '.($name ?: 'Sir/Madam').',', ''];
        $lines[] = 'The joining link for the meeting below is now available.';
        $lines[] = '';
        $lines[] = 'Meeting: '.$meeting->title;
        $lines[] = 'Reference: '.($meeting->meeting_no ?: '#'.$meeting->getKey());
        $lines[] = 'When: '.$this->whenLine($meeting);
        if ($meeting->location) {
            $lines[] = 'Where: '.$meeting->location;
        }
        $lines[] = '';
        $lines[] = 'Join here: '.$link;
        if ($meeting->meeting_passcode) {
            $lines[] = 'Passcode: '.$meeting->meeting_passcode;
        }
        $lines[] = '';
        $lines[] = 'The meeting, its agenda and its minutes are here: '.$url;
        $lines[] = 'A calendar invite is attached.';

        return implode("\n", $lines);
    }

    /**
     * The calendar entry, this time WITH the room in it.
     *
     * The invitation's .ics deliberately points at the CRM: it is built days in
     * advance, when the only link is an instant-start URL that would put every
     * diary owner in a room of their own. This one is built from a real room the
     * organiser is already sitting in, so LOCATION is the place to put it — it is
     * what makes the "Join" button appear in Google Calendar and Outlook.
     */
    private function buildIcs(Model $meeting, string $link): string
    {
        $start = $meeting->scheduled_at ?: now();
        $end = $meeting->end_at ?: (clone $start)->addMinutes($meeting->duration_minutes ?: 60);

        $esc = fn ($v) => str_replace(["\\", "\n", ',', ';'], ['\\\\', '\\n', '\\,', '\\;'], (string) $v);
        $stamp = fn ($d) => $d->copy()->utc()->format('Ymd\THis\Z');

        $description = trim(implode('\n', array_filter([
            $meeting->meeting_type_label ?? null,
            'Join: '.$link,
            $meeting->meeting_passcode ? 'Passcode: '.$meeting->meeting_passcode : null,
        ])));

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Sangoe//Meetings//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:REQUEST',
            'BEGIN:VEVENT',
            'UID:meeting-'.$this->engineOf($meeting).'-'.$meeting->getKey().'@sangoe',
            'SEQUENCE:1',
            'DTSTAMP:'.$stamp(now()),
            'DTSTART:'.$stamp($start),
            'DTEND:'.$stamp($end),
            'SUMMARY:'.$esc($meeting->title),
            'DESCRIPTION:'.$esc($description),
            'LOCATION:'.$esc($meeting->location ?: $link),
            'URL:'.$link,
            'STATUS:CONFIRMED',
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode("\r\n", $lines)."\r\n";
    }
}

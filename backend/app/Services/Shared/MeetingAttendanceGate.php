<?php

namespace App\Services\Shared;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * What the join link costs, and what it never should have.
 *
 * The invitation used to carry the Google Meet / Zoom link in the e-mail, the
 * calendar attachment and every payload the portal read, so the CRM was a thing
 * people walked past on their way to the call. The answer then was to withhold
 * the link until somebody marked attendance.
 *
 * ── That price is no longer charged, and here is why ────────────────────
 * The link is now SENT — MeetingLinkAnnouncer e-mails the real room URL, with a
 * calendar attachment, to every participant the moment the organiser pastes it.
 * Once that is true, withholding the same URL from the person's own meeting
 * page protects nothing: it is already in their inbox. All it achieves is that
 * the CRM is the slowest way to reach a link everybody already has, which is a
 * worse version of the problem the gate was built for. So a REAL room link is
 * in the payload for anyone who can see the meeting.
 *
 * ── The rule that IS load-bearing ───────────────────────────────────────
 * An instant-start URL — meet.google.com/new and its Zoom and Teams twins — is
 * not a room. It opens a different, empty meeting for every person who clicks
 * it. That one is still held by the host alone, and everyone else is told the
 * room has not been shared yet (`link_pending`) rather than handed a meeting of
 * their own. Withholding it is a kindness; withholding a real room was a toll.
 *
 * Marking attendance still works and is still offered. It is now what it always
 * should have been: a record, not a turnstile.
 *
 * ── What this cannot claim ──────────────────────────────────────────────
 * Pressing a button in the CRM is not proof of sitting through a call held on
 * somebody else's servers. It is evidence that this account opened this meeting
 * at this time, and that is exactly how it is recorded and labelled. Deciding
 * whether the person was actually there is the organiser's job, which is why
 * they get the attendance log to review.
 *
 * ── Who is not gated ────────────────────────────────────────────────────
 * The organiser who created the meeting, and an admin. Both can open the
 * meeting for editing and read the link out of the form they typed it into, so
 * withholding it from them buys nothing and costs them the ability to host.
 * A vendor is never exempt.
 */
class MeetingAttendanceGate
{
    /**
     * A link is offered only while the meeting is still going to happen.
     *
     * "Not expired" is the wrong test and was the old one: a CANCELLED meeting
     * is not expired either, and kept handing out a working link.
     */
    public const JOINABLE_STATES = ['upcoming', 'live'];

    public function __construct(private MeetingJoinRecorder $recorder)
    {
    }

    /** Is there a link to hand out at all, and is the meeting still live or ahead? */
    public function joinable(Model $meeting): bool
    {
        return (bool) $meeting->meeting_link
            && in_array($meeting->timing_state, self::JOINABLE_STATES, true);
    }

    /**
     * Runs the meeting rather than attends it.
     *
     * created_by is the account that scheduled it. Both engines carry the
     * column, and on both it is the person who chose the platform and generated
     * or pasted the link in the first place.
     */
    public function hosts(Model $meeting, $viewer): bool
    {
        if (! $viewer instanceof User) {
            return false;   // a portal vendor never hosts
        }

        return ((int) $meeting->created_by === (int) $viewer->id)
            || $viewer->role === 'admin';
    }

    /** Has this viewer already marked attendance? */
    public function marked(Model $meeting, $viewer): bool
    {
        $row = $this->recorder->rosterRowFor($meeting, $viewer);

        return $row ? (bool) $row->attended : false;
    }

    /** The link, or null when it has not been earned. */
    public function linkFor(Model $meeting, $viewer): ?string
    {
        return $this->stateFor($meeting, $viewer)['meeting_link'];
    }

    /**
     * The three fields every meeting payload carries, so the portal and the
     * staff console do not each invent their own answer.
     *
     *   meeting_link         the link, or null — null means not earned yet
     *   has_meeting_link     a link EXISTS, whether or not this viewer may see it
     *   attendance_marked    this viewer has already marked attendance
     *   can_mark_attendance  the button should be offered
     *
     * has_meeting_link is the difference between "there is no online meeting"
     * and "there is one you have not unlocked". Without it every gated screen
     * has to render the first when it means the second, which reads as a
     * missing link rather than a waiting action.
     */
    public function stateFor(Model $meeting, $viewer): array
    {
        if (! $this->joinable($meeting)) {
            return [
                'meeting_link' => null,
                'has_meeting_link' => (bool) $meeting->meeting_link,
                'attendance_marked' => false,
                'can_mark_attendance' => false,
                'link_pending' => false,
                // No link yet: the host can still paste one. Ended: nothing to set.
                'can_set_link' => ! $meeting->meeting_link && $this->hosts($meeting, $viewer),
            ];
        }

        $row = $this->recorder->rosterRowFor($meeting, $viewer);
        $marked = $row ? (bool) $row->attended : false;
        $hosts = $this->hosts($meeting, $viewer);

        // An instant-start link (meet.google.com/new) is not a room: each
        // person who opens it lands in a meeting of their own. Only the host
        // may hold it — to start the call and paste the real room back.
        // Everyone else waits for that, and is told so.
        if (OnlineMeetingService::isInstant($meeting->meeting_link) && ! $hosts) {
            return [
                'meeting_link' => null,
                'has_meeting_link' => false,
                'link_pending' => true,
                'can_set_link' => false,
                'attendance_marked' => $marked,
                'can_mark_attendance' => ! $marked,
            ];
        }

        return [
            /*
             * A REAL room is given to everyone who can see the meeting.
             *
             * This used to be ($marked || $hosts). That made sense while the
             * link existed nowhere else — marking was the only way to obtain it,
             * so it was a price worth charging. It is not the case any more:
             * the moment the organiser pastes the room, MeetingLinkAnnouncer
             * e-mails that exact URL to every participant, with a calendar
             * attachment carrying it. Keeping the lock here would withhold from
             * a person's CRM page a link already sitting in their inbox — which
             * does not protect the register, it just makes the CRM the slower
             * way to do the same thing.
             *
             * The instant-link rule above is untouched, and is the one that was
             * ever load-bearing: meet.google.com/new is not a room, and handing
             * it out is a real harm rather than a lost data point.
             */
            'meeting_link' => $meeting->meeting_link,
            'has_meeting_link' => true,
            'link_pending' => false,
            // Tells the host to start the call and paste the real room link.
            'link_is_instant' => $hosts && OnlineMeetingService::isInstant($meeting->meeting_link),
            'can_set_link' => $hosts,
            'attendance_marked' => $marked,
            // Still offered to the organiser: they attend their own meetings,
            // and a register that cannot record the person who called the
            // meeting is the gap this whole thing exists to close. What being
            // the organiser buys is the link without waiting, not exemption
            // from the record.
            'can_mark_attendance' => ! $marked,
        ];
    }

    /**
     * Mark attendance and hand over the link.
     *
     * @param  Model  $meeting  a KickoffMeeting or PurchaseKickoffMeeting
     * @param  User|Model  $viewer  a staff account, or a portal vendor identity
     * @param  array{name?:string,email?:string,organisation?:string,role?:string}  $identity
     *         who to put on the roster when this viewer has no row yet, and the
     *         caller knows them well enough to name them — see mark()'s note.
     * @return array{meeting_link:?string, attendance_marked:bool, can_mark_attendance:bool, recorded:bool}
     */
    public function mark(Model $meeting, $viewer, Request $request, array $identity = []): array
    {
        abort_unless($this->joinable($meeting), 404, 'This meeting is not open to join.');

        /*
         * Somebody invited but never typed onto the roster.
         *
         * The recorder will not invent a row, and it is right not to: a row
         * built from a login guesses at a name. But refusing here would leave
         * that person unable to ever reach the link, and the roster is often
         * incomplete on purpose — a meeting scheduled for a vendor commonly has
         * no participant row for the vendor at all, which is why the invitation
         * code adds them separately.
         *
         * So the caller — which knows whether this viewer belongs on this
         * meeting, and knows their real name and address — may pass an identity
         * to seat them with. Nothing is guessed here.
         */
        if ($identity && ! $this->recorder->rosterRowFor($meeting, $viewer)) {
            $this->seat($meeting, $viewer, $identity);
        }

        $result = $this->recorder->record($meeting, $viewer, $request);

        return $this->stateFor($meeting->fresh(), $viewer) + ['recorded' => $result['recorded']];
    }

    /** Put this person on the roster so their attendance has somewhere to land. */
    private function seat(Model $meeting, $viewer, array $identity): void
    {
        $roster = method_exists($meeting, 'attendees') ? 'attendees' : 'participants';

        $meeting->{$roster}()->create(array_filter([
            'tenant_id' => $meeting->tenant_id,
            // The login account, so the row can be found again. A vendor seated
            // without one is only ever matchable by e-mail, and a vendor record
            // with no e-mail address would be seated and then locked out by the
            // very row that was created for them.
            'user_id' => $viewer instanceof User ? $viewer->id : ($viewer->user_id ?? null),
            'name' => $identity['name'] ?? null,
            'email' => $identity['email'] ?? null,
            'organisation' => $identity['organisation'] ?? null,
            'role' => $identity['role'] ?? null,
            'side' => $identity['side'] ?? null,
            // Which column of the attendance sheet to seat them in. Staff mark
            // their own attendance and the caller says 'internal', so they
            // belong under Organiser rather than in the grid's "not yet placed"
            // row — which is where every self-marked staff member landed
            // otherwise, on a sheet they had just added themselves to.
            'party' => $identity['party']
                ?? (($identity['side'] ?? null) === 'internal' ? 'organiser' : null),
        ], fn ($v) => $v !== null && $v !== ''));
    }
}

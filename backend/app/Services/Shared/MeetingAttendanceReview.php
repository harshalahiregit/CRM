<?php

namespace App\Services\Shared;

use App\Models\User;
use App\Support\Shared\AttendanceVerdict;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The organiser's review of who actually attended.
 *
 * The CRM cannot see a call held on Google Meet, Zoom or Teams. What it can see
 * is somebody marking attendance here — which is also how they got the joining
 * link ({@see MeetingAttendanceGate}) — and that is a CLAIM, not proof. Somebody
 * can mark attendance, take the link and never open it.
 *
 * So the organiser has the final word, and the design rule that follows is the
 * important one: **the verdict is written beside the claim, never over it.**
 * "Punched CRM attendance but did not join the call" is only a sentence anyone
 * can write if both halves survive — the claim, with its timestamp and device,
 * and the organiser's contradiction of it. Writing the verdict into
 * `attendance_status` would have destroyed the evidence being contradicted.
 *
 * ── Who may review ──────────────────────────────────────────────────────
 * The organiser who called the meeting, and an admin. Not every member of
 * staff: this decision can cost somebody their attendance record, and "final
 * approval authority" means one person's, not anyone who can open the page.
 *
 * ── What it does not do ─────────────────────────────────────────────────
 * It does not touch `attended`, `attendance_status`, `attendance_source`,
 * `joined_at` or `remark`. Those belong to the attendee's side of the record and
 * are what the review is judging.
 */
class MeetingAttendanceReview
{
    /**
     * May this person pass judgement on this meeting's register?
     *
     * created_by is the account that scheduled it — the organiser, on both
     * engines.
     */
    public function mayReview(Model $meeting, $actor): bool
    {
        if (! $actor instanceof User) {
            return false;   // a vendor never reviews; they are the reviewed
        }

        return ((int) $meeting->created_by === (int) $actor->id)
            || $actor->role === 'admin';
    }

    /**
     * Record verdicts for some or all of the roster.
     *
     * @param  Model  $meeting  a KickoffMeeting or PurchaseKickoffMeeting
     * @param  array<int, array{id:int, verdict:?string, verdict_from?:?string, verdict_to?:?string, verdict_note?:?string}>  $rows
     * @return array{reviewed:int, present:int, partial:int, absent:int, cleared:int, contradicted:int}
     */
    public function review(Model $meeting, array $rows, User $actor): array
    {
        abort_unless($this->mayReview($meeting, $actor), 403,
            'Only the meeting organiser or an admin can decide attendance.');

        $roster = method_exists($meeting, 'attendees') ? 'attendees' : 'participants';
        $existing = $meeting->{$roster}()->get()->keyBy('id');

        $counts = ['reviewed' => 0, 'present' => 0, 'partial' => 0, 'absent' => 0, 'cleared' => 0, 'contradicted' => 0];
        $now = Carbon::now();

        DB::transaction(function () use ($rows, $existing, $actor, $now, &$counts) {
            foreach ($rows as $row) {
                $person = $existing->get((int) ($row['id'] ?? 0));
                if (! $person) {
                    // Silently skipping would let a stale form quietly review
                    // somebody else's meeting.
                    abort(422, 'Attendee '.($row['id'] ?? '?').' is not on this meeting.');
                }

                $verdict = $row['verdict'] ?? null;

                // Clearing a verdict is a legitimate action — an organiser who
                // decided too early must be able to take it back to "not yet
                // reviewed", which is a different state from Complete_Absent.
                if ($verdict === null || $verdict === '') {
                    $person->forceFill([
                        'verdict' => null, 'verdict_from' => null, 'verdict_to' => null,
                        'verdict_note' => null, 'verdict_by' => null, 'verdict_at' => null,
                    ])->save();
                    $counts['cleared']++;

                    continue;
                }

                abort_unless(AttendanceVerdict::isValid($verdict), 422, "Unknown attendance verdict [{$verdict}].");

                [$from, $to] = $this->window($verdict, $row);

                $person->forceFill([
                    'verdict' => $verdict,
                    'verdict_from' => $from,
                    'verdict_to' => $to,
                    'verdict_note' => $this->note($row),
                    'verdict_by' => $actor->id,
                    'verdict_at' => $now,
                ])->save();

                $counts['reviewed']++;
                match ($verdict) {
                    AttendanceVerdict::FULLY_PRESENT => $counts['present']++,
                    AttendanceVerdict::PARTIAL_ABSENT => $counts['partial']++,
                    AttendanceVerdict::COMPLETE_ABSENT => $counts['absent']++,
                };

                if (AttendanceVerdict::contradictsClaim((bool) $person->attended, $verdict)) {
                    $counts['contradicted']++;
                }
            }
        });

        $summary = "Attendance reviewed: {$counts['present']} fully present, {$counts['partial']} partial, "
            ."{$counts['absent']} absent"
            .($counts['contradicted'] ? ", {$counts['contradicted']} contradicting a CRM attendance mark" : '');

        if (method_exists($meeting, 'recordAudit')) {
            $meeting->recordAudit('attendance_reviewed', $actor, $summary);
        }

        Log::info('Meeting attendance reviewed', [
            'meeting' => $meeting::class.'#'.$meeting->getKey(),
            'actor_id' => $actor->id,
        ] + $counts);

        return $counts;
    }

    /**
     * The roster as the review screen and the minutes need it: what the person
     * claimed, and what the organiser decided, side by side.
     */
    public function register(Model $meeting): array
    {
        $roster = method_exists($meeting, 'attendees') ? 'attendees' : 'participants';

        return $meeting->{$roster}()->get()->map(function ($a) {
            $from = $a->verdict_from ? Carbon::parse($a->verdict_from) : null;
            $to = $a->verdict_to ? Carbon::parse($a->verdict_to) : null;

            return [
                'id' => (int) $a->id,
                'name' => $a->name,
                'email' => $a->email,
                'role' => $a->role,
                'organisation' => $a->organisation,
                'side' => $a->side,

                // ── what the attendee's own actions say ──────────────────
                'claimed_attended' => (bool) $a->attended,
                'attendance_status' => $a->attendance_status,
                'attendance_source' => $a->attendance_source,
                'joined_at' => optional($a->joined_at)->toIso8601String(),
                'left_at' => optional($a->left_at)->toIso8601String(),
                'seconds_in_call' => $a->seconds_in_call ? (int) $a->seconds_in_call : null,
                // The device/time stamp written when they marked attendance —
                // the answer to "how do we know?", which is the organiser's
                // first question. Purchase's roster has no remark column.
                'claim_evidence' => $a->getAttribute('remark'),

                // ── what the organiser decided ───────────────────────────
                'verdict' => $a->verdict,
                'verdict_label' => AttendanceVerdict::label($a->verdict),
                'verdict_from' => optional($from)->toIso8601String(),
                'verdict_to' => optional($to)->toIso8601String(),
                'verdict_minutes' => AttendanceVerdict::minutes($from, $to),
                'verdict_note' => $a->verdict_note,
                'verdict_at' => optional($a->verdict_at)->toIso8601String(),
                'reviewed' => $a->verdict !== null,

                // Computed, never stored — so it cannot drift from the two
                // records it compares.
                'contradicts_claim' => AttendanceVerdict::contradictsClaim((bool) $a->attended, $a->verdict),
            ];
        })->all();
    }

    /* ── internals ──────────────────────────────────────────────────────── */

    /**
     * The active window, for a partial verdict only.
     *
     * A window on Fully_Present or Complete_Absent is noise at best and a
     * contradiction at worst — "absent, from 9:30 to 10:00" — so it is dropped
     * rather than stored.
     */
    private function window(string $verdict, array $row): array
    {
        if (! AttendanceVerdict::needsWindow($verdict)) {
            return [null, null];
        }

        $from = ! empty($row['verdict_from']) ? Carbon::parse($row['verdict_from']) : null;
        $to = ! empty($row['verdict_to']) ? Carbon::parse($row['verdict_to']) : null;

        // Required, not optional. Partial is the one slab whose whole purpose is
        // the times; accepting it without them produces a record that says a
        // person was partly absent and refuses to say when.
        abort_unless($from && $to, 422,
            'Partial Absent needs the times the person was actually in the meeting.');
        abort_unless($to->greaterThan($from), 422,
            'The time they left must be after the time they joined.');

        return [$from, $to];
    }

    private function note(array $row): ?string
    {
        $note = trim((string) ($row['verdict_note'] ?? ''));

        return $note === '' ? null : mb_substr($note, 0, 2000);
    }
}

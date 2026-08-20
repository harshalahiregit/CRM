<?php

namespace Database\Seeders;

use App\Models\Shared\KickoffAttendee;
use App\Models\Shared\KickoffMeeting;
use App\Models\Shared\KickoffMeetingSubject;
use App\Models\Shared\KickoffMomItem;
use App\Models\Shared\MeetingAgendaItem;
use App\Models\Shared\MeetingDecision;
use App\Models\Shared\MeetingIssue;
use App\Models\User;
use App\Models\Vendor\Vendor;
use App\Services\Shared\KickoffMeetingService;
use Illuminate\Database\Seeder;

/**
 * Demo data for the whole Meetings engine (Phase A, slices 1–6).
 *
 * Drives the REAL KickoffMeetingService for everything, so it doubles as an
 * end-to-end smoke test: agenda templates, the action engine (every lifecycle
 * state), decisions, issues + a real Issue→Incident conversion, carry-forward
 * (with the no-double-carry link), the MOM approval workflow (Draft / Pending /
 * Approved / Distributed), acknowledgement, attendance, delayed/cancelled
 * transitions, and a spread of dates for the calendar + a per-vendor history.
 *
 * Idempotent-ish: it removes its own prior "[DEMO]" meetings first, so re-running
 * refreshes rather than duplicates.
 *
 * Run:  php artisan db:seed --class=MeetingsEngineDemoSeeder
 */
class MeetingsEngineDemoSeeder extends Seeder
{
    private KickoffMeetingService $svc;
    private int $tid;
    private User $actor;

    public function run(): void
    {
        // Keep distribution e-mails out of the way when seeding.
        config(['mail.default' => 'log']);

        $vendor = Vendor::query()->first();
        if (! $vendor) {
            $this->command?->warn('No vendors found — cannot seed meetings.');
            return;
        }

        $this->tid   = $vendor->tenant_id;
        $this->actor = User::where('tenant_id', $this->tid)->where('role', 'admin')->first()
            ?? User::where('tenant_id', $this->tid)->firstOrFail();
        auth()->login($this->actor);

        $this->svc = app(KickoffMeetingService::class);

        $vendors = Vendor::where('tenant_id', $this->tid)->orderBy('id')->take(3)->get();
        $v1 = $vendors[0];
        $v2 = $vendors[1] ?? $v1;

        $this->cleanup();
        $this->command?->info("Seeding Meetings-engine demo data for tenant {$this->tid} (vendor: {$v1->company_name})…");

        // ── M1 — Kickoff, fully worked through: actions in every state, decisions,
        //         issues (one escalated to an incident), MOM approved + distributed
        //         + acknowledged. Its open action/issue seed the carry-forward. ──
        $m1 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'kickoff',
            'title' => "[DEMO] Kickoff — {$v1->company_name}",
            'scheduled_at' => now()->subDays(21)->setTime(10, 0)->toDateTimeString(),
            'duration_minutes' => 90, 'mode' => 'onsite', 'city' => 'Mumbai', 'venue' => 'Site Office, Gate 1',
            'agenda' => 'Standard kickoff — scope, HSE, documentation, schedule.',
            'attendees' => $this->people($v1->company_name),
            'agenda_items' => $this->templateAgenda('kickoff'),
            'mom_items' => [
                ['description' => 'Submit updated insurance certificates', 'responsible' => 'Rajesh Kumar', 'responsible_org' => 'Vendor', 'priority' => 'High', 'target_date' => now()->addDays(7)->toDateString()],
                ['description' => 'Complete site induction for all workers', 'responsible' => 'Anita Shah', 'responsible_org' => 'PMC', 'priority' => 'Medium', 'target_date' => now()->addDays(3)->toDateString()],
                ['description' => 'Provide method statement for scaffolding', 'responsible' => 'Vikram Singh', 'priority' => 'High'],
                ['description' => 'Share emergency contact list', 'responsible' => 'Rajesh Kumar', 'priority' => 'Low'],
            ],
            'decisions' => [
                ['decision' => 'Work starts only after induction sign-off', 'decided_by' => 'Rajesh Kumar', 'impact' => 'Schedule', 'status' => 'Active', 'effective_date' => now()->subDays(20)->toDateString()],
                ['decision' => 'Scaffolding sub-vendor must be pre-approved', 'decided_by' => 'Anita Shah', 'impact' => 'Safety', 'status' => 'Superseded'],
            ],
            'issues' => [
                ['title' => 'Insurance certificate expired', 'description' => 'Public-liability cover lapsed last month.', 'category' => 'Compliance', 'severity' => 'High', 'owner' => 'Rajesh Kumar', 'due_date' => now()->addDays(5)->toDateString()],
                ['title' => 'Minor first-aid case on site', 'description' => 'Worker cut finger; treated on site.', 'category' => 'Safety', 'severity' => 'Medium', 'owner' => 'Anita Shah'],
            ],
        ], $this->actor);

        // Seeds for the carry-forward demo (both still Open).
        $carryActionId = $m1->momItems->first()->id;
        $carryIssueId  = $m1->issues->first()->id;

        $this->attend($m1);
        $m1 = $this->svc->transition($m1, 'Completed', ['minutes' => 'Meeting held; actions assigned and owners agreed.'], $this->actor);

        // Actions across the lifecycle: [0] Open, [1] In Progress, [2] Pending
        // Verification, [3] Open→Pending→Closed (with a verification note).
        $items = $m1->momItems->values();
        $this->svc->progressAction($items[1], ['status' => 'In_Progress'], $this->actor);
        $this->svc->progressAction($items[2], ['status' => 'Pending_Verification'], $this->actor);
        $it3 = $this->svc->progressAction($items[3], ['status' => 'Pending_Verification'], $this->actor);
        $this->svc->progressAction($it3, ['status' => 'Closed', 'note' => 'Emergency contact list verified and circulated.'], $this->actor);

        // Escalate the second issue into a real HSSE incident (Minor, no stop-work).
        $issues = $m1->issues->values();
        $this->svc->convertIssueToIncident($issues[1], ['type' => 'First Aid', 'severity' => 'Minor', 'stop_work' => false], $this->actor);

        // MOM: submit → approve → distribute → acknowledge. (mom_path is set
        // directly so seeding does not depend on the PDF engine — see note below.)
        $this->stubMomDoc($m1);
        $m1 = $this->svc->submitMomForApproval($m1, $this->actor);
        $m1 = $this->svc->decideMom($m1, 'approve', 'Approved by the project manager.', $this->actor);
        $m1 = $this->svc->publishForAck($m1, $this->actor);
        $this->svc->acknowledge($m1->fresh(), ['name' => 'Rajesh Kumar', 'comment' => 'Minutes agreed.'], ['ip' => '127.0.0.1']);
        $this->say('M1 kickoff (completed · acknowledged · issue→incident)');

        // ── M2 — HSE, Completed, MOM Pending Approval (tester can approve/return) ──
        $m2 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'hse',
            'title' => "[DEMO] HSE Meeting — {$v1->company_name}",
            'scheduled_at' => now()->subDays(10)->setTime(9, 30)->toDateTimeString(),
            'duration_minutes' => 60, 'mode' => 'onsite', 'city' => 'Mumbai', 'venue' => 'Muster Point',
            'attendees' => $this->people($v1->company_name),
            'agenda_items' => $this->templateAgenda('hse'),
            'mom_items' => [
                ['description' => 'Close out open near-miss actions', 'responsible' => 'Anita Shah', 'priority' => 'High', 'target_date' => now()->addDays(2)->toDateString()],
                ['description' => 'Replace worn PPE for two workers', 'responsible' => 'Vikram Singh', 'priority' => 'Medium'],
                ['description' => 'Investigate scaffold non-conformance', 'responsible' => 'Vikram Singh', 'priority' => 'High'],
            ],
            'issues' => [
                ['title' => 'Housekeeping poor near stores', 'category' => 'Safety', 'severity' => 'Low', 'owner' => 'Vikram Singh'],
            ],
        ], $this->actor);
        $this->attend($m2);
        $m2 = $this->svc->transition($m2, 'Completed', ['minutes' => 'HSE review completed; corrective actions raised.'], $this->actor);
        // Cover the remaining action states: In Progress, Cancelled, and a
        // reopened one (Open → Pending → Closed → Reopened).
        $m2items = $m2->momItems->values();
        $this->svc->progressAction($m2items[0], ['status' => 'In_Progress'], $this->actor);
        $this->svc->progressAction($m2items[1], ['status' => 'Cancelled'], $this->actor);
        $pend = $this->svc->progressAction($m2items[2], ['status' => 'Pending_Verification'], $this->actor);
        $clsd = $this->svc->progressAction($pend, ['status' => 'Closed', 'note' => 'Closed, then reopened for follow-up.'], $this->actor);
        $this->svc->progressAction($clsd, ['status' => 'Reopened'], $this->actor);
        $this->svc->submitMomForApproval($m2, $this->actor); // → Pending Approval
        $this->say('M2 HSE (completed · MOM pending approval)');

        // ── M3 — Weekly Coordination, Completed, CARRIES FORWARD M1's open items ──
        $m3 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'weekly_coordination',
            'title' => "[DEMO] Weekly Coordination — {$v1->company_name}",
            'scheduled_at' => now()->subDays(3)->setTime(11, 0)->toDateTimeString(),
            'duration_minutes' => 45, 'mode' => 'online', 'meeting_platform' => 'google_meet',
            'attendees' => $this->people($v1->company_name),
            'agenda_items' => $this->templateAgenda('weekly_coordination'),
            'mom_items' => [
                ['description' => 'Submit updated insurance certificates', 'responsible' => 'Rajesh Kumar', 'priority' => 'High', 'carried_from_id' => $carryActionId],
                ['description' => 'Review weekly progress vs plan', 'responsible' => 'Rajesh Kumar'],
            ],
            'issues' => [
                ['title' => 'Insurance certificate expired', 'category' => 'Compliance', 'severity' => 'High', 'owner' => 'Rajesh Kumar', 'carried_from_id' => $carryIssueId],
            ],
        ], $this->actor);
        $this->svc->transition($m3, 'Completed', ['minutes' => 'Weekly coordination; carried-forward items reviewed.'], $this->actor);
        $this->say('M3 weekly (completed · carried forward M1 open action + issue · MOM draft)');

        // ── M4 — Progress Review, Scheduled (future, this month) ──
        $m4 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'progress_review',
            'title' => "[DEMO] Progress Review — {$v1->company_name}",
            'scheduled_at' => now()->addDays(5)->setTime(15, 0)->toDateTimeString(),
            'duration_minutes' => 60, 'mode' => 'onsite', 'city' => 'Mumbai', 'venue' => 'Conference Room B',
            'attendees' => $this->people($v1->company_name),
            'agenda_items' => $this->templateAgenda('progress_review'),
        ], $this->actor);
        $this->say('M4 progress review (scheduled · future)');

        // ── M5 — Toolbox, Scheduled (near future) ──
        $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'toolbox',
            'title' => "[DEMO] Toolbox Talk — {$v1->company_name}",
            'scheduled_at' => now()->addDays(2)->setTime(8, 0)->toDateTimeString(),
            'duration_minutes' => 20, 'mode' => 'onsite', 'city' => 'Mumbai', 'venue' => 'Work Front 3',
            'attendees' => $this->people($v1->company_name),
            'agenda_items' => $this->templateAgenda('toolbox'),
        ], $this->actor);
        $this->say('M5 toolbox (scheduled)');

        // ── M6 — Vendor Review, Delayed (with reason + new date) ──
        $m6 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'vendor_review',
            'title' => "[DEMO] Vendor Review — {$v1->company_name}",
            'scheduled_at' => now()->subDays(2)->setTime(14, 0)->toDateTimeString(),
            'duration_minutes' => 60, 'mode' => 'onsite', 'city' => 'Mumbai', 'venue' => 'HO Meeting Room',
            'attendees' => $this->people($v1->company_name),
            'agenda_items' => $this->templateAgenda('vendor_review'),
        ], $this->actor);
        $this->svc->transition($m6, 'Delayed', ['delay_reason' => 'Vendor requested a reschedule due to travel.', 'scheduled_at' => now()->addDays(4)->setTime(14, 0)->toDateTimeString()], $this->actor);
        $this->say('M6 vendor review (delayed)');

        // ── M7 — Closure, Cancelled ──
        $m7 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v1->id, 'meeting_type' => 'closure',
            'title' => "[DEMO] Closure Meeting — {$v1->company_name}",
            'scheduled_at' => now()->addDays(7)->setTime(16, 0)->toDateTimeString(),
            'duration_minutes' => 45, 'mode' => 'onsite', 'city' => 'Mumbai', 'venue' => 'HO',
            'agenda_items' => $this->templateAgenda('closure'),
        ], $this->actor);
        $this->svc->transition($m7, 'Cancelled', [], $this->actor);
        $this->say('M7 closure (cancelled)');

        // ── M8 — v2 Kickoff, Completed, MOM APPROVED (tester can distribute) ──
        $m8 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v2->id, 'meeting_type' => 'kickoff',
            'title' => "[DEMO] Kickoff — {$v2->company_name}",
            'scheduled_at' => now()->subDays(1)->setTime(10, 0)->toDateTimeString(),
            'duration_minutes' => 90, 'mode' => 'onsite', 'city' => 'Pune', 'venue' => 'Plant Gate',
            'attendees' => $this->people($v2->company_name),
            'agenda_items' => $this->templateAgenda('kickoff'),
            'mom_items' => [
                ['description' => 'Issue site access passes', 'responsible' => 'Rajesh Kumar', 'priority' => 'High'],
            ],
        ], $this->actor);
        $this->attend($m8);
        $m8 = $this->svc->transition($m8, 'Completed', ['minutes' => 'Kickoff held; awaiting approval to distribute.'], $this->actor);
        $this->stubMomDoc($m8);
        $m8 = $this->svc->submitMomForApproval($m8, $this->actor);
        $this->svc->decideMom($m8, 'approve', null, $this->actor); // Approved, NOT distributed
        $this->say('M8 kickoff v2 (completed · MOM approved · ready to distribute)');

        // ── M9 — v2 HSE, Completed, MOM DISTRIBUTED but NOT acknowledged ──
        $m9 = $this->svc->schedule([
            'subject_type' => 'vendor', 'subject_id' => $v2->id, 'meeting_type' => 'hse',
            'title' => "[DEMO] HSE Meeting — {$v2->company_name}",
            'scheduled_at' => now()->subDays(1)->setTime(9, 0)->toDateTimeString(),
            'duration_minutes' => 60, 'mode' => 'onsite', 'city' => 'Pune', 'venue' => 'Muster Point',
            'attendees' => $this->people($v2->company_name),
            'agenda_items' => $this->templateAgenda('hse'),
            'mom_items' => [
                ['description' => 'Update the site risk assessment', 'responsible' => 'Anita Shah', 'priority' => 'High'],
            ],
        ], $this->actor);
        $this->attend($m9);
        $m9 = $this->svc->transition($m9, 'Completed', ['minutes' => 'HSE meeting held; minutes issued for acknowledgement.'], $this->actor);
        $this->stubMomDoc($m9);
        $m9 = $this->svc->submitMomForApproval($m9, $this->actor);
        $m9 = $this->svc->decideMom($m9, 'approve', null, $this->actor);
        $this->svc->publishForAck($m9, $this->actor); // Distributed, awaiting ack
        $this->say('M9 HSE v2 (completed · MOM distributed · awaiting acknowledgement)');

        $count = KickoffMeeting::forTenant($this->tid)->where('title', 'like', '[DEMO]%')->count();
        $this->command?->info("Done — {$count} demo meetings across vendors #{$v1->id}".($v2->id !== $v1->id ? " & #{$v2->id}" : '').'.');
    }

    /** Standard attendee list, so every meeting has present/late/absent variety. */
    private function people(string $org): array
    {
        return [
            ['name' => 'Rajesh Kumar', 'role' => 'Project Manager', 'organisation' => $org],
            ['name' => 'Anita Shah',   'role' => 'HSE Lead',        'organisation' => 'PMC'],
            ['name' => 'Vikram Singh', 'role' => 'Site Supervisor', 'organisation' => $org],
        ];
    }

    /** The config template agenda for a type, shaped for the schedule() payload. */
    private function templateAgenda(string $type): array
    {
        return array_map(fn ($t) => [
            'item'             => $t['item'],
            'description'      => $t['description'] ?? null,
            'duration_minutes' => $t['duration_minutes'] ?? null,
            'priority'         => $t['priority'] ?? null,
        ], config("meetings.templates.{$type}", []));
    }

    /** Mark attendance — first present, second late, third absent. */
    private function attend(KickoffMeeting $m): void
    {
        $states = [KickoffAttendee::PRESENT, KickoffAttendee::LATE, KickoffAttendee::ABSENT];
        $rows = [];
        foreach ($m->attendees->values() as $i => $a) {
            $rows[] = ['id' => $a->id, 'attendance_status' => $states[$i % 3]];
        }
        if ($rows) {
            $this->svc->markAttendance($m, $rows, $this->actor);
        }
    }

    private function say(string $line): void
    {
        $this->command?->line("  • {$line}");
    }

    /**
     * Attach a MOM document without invoking the (uninstalled) PDF engine, so the
     * distribution/acknowledgement demo works and View/Download show a real, if
     * placeholder, PDF. Writes a hand-built minimal-but-valid PDF to the store.
     */
    private function stubMomDoc(KickoffMeeting $m): void
    {
        $path = "tenant-{$this->tid}/meeting-{$m->id}/mom-demo.pdf";
        \Illuminate\Support\Facades\Storage::disk('kickoff_docs')
            ->put($path, $this->minimalPdf('DEMO Minutes of Meeting  —  Meeting #'.$m->id));
        $m->update(['mom_path' => $path]);
    }

    /** A minimal, spec-valid single-page PDF (correct xref offsets), no library. */
    private function minimalPdf(string $text): string
    {
        $esc    = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = "BT /F1 18 Tf 60 780 Td ({$esc}) Tj ET";

        $objs = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            4 => '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            5 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];

        $pdf     = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objs as $n => $body) {
            $offsets[$n] = strlen($pdf);
            $pdf .= "{$n} 0 obj\n{$body}\nendobj\n";
        }

        $xref  = strlen($pdf);
        $count = count($objs) + 1;
        $pdf  .= "xref\n0 {$count}\n0000000000 65535 f \n";
        for ($i = 1; $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size {$count} /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";

        return $pdf;
    }

    /** Remove prior demo meetings and their children so a re-run refreshes. */
    private function cleanup(): void
    {
        $ids = KickoffMeeting::withTrashed()->where('tenant_id', $this->tid)
            ->where('title', 'like', '[DEMO]%')->pluck('id');

        if ($ids->isEmpty()) {
            return;
        }

        KickoffMomItem::whereIn('kickoff_meeting_id', $ids)->delete();
        MeetingIssue::whereIn('kickoff_meeting_id', $ids)->delete();
        MeetingDecision::whereIn('kickoff_meeting_id', $ids)->delete();
        MeetingAgendaItem::whereIn('kickoff_meeting_id', $ids)->delete();
        KickoffAttendee::whereIn('kickoff_meeting_id', $ids)->delete();
        KickoffMeetingSubject::whereIn('kickoff_meeting_id', $ids)->delete();
        KickoffMeeting::withTrashed()->whereIn('id', $ids)->forceDelete();

        $this->command?->warn("Removed {$ids->count()} prior [DEMO] meeting(s).");
    }
}

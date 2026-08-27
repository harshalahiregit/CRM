<?php

namespace App\Services\SangoeTrack;

/**
 * The admin half of the SangoeTrack API, named the way the CRM talks about it.
 *
 * Every method here maps to an endpoint that is already driving a screen in
 * SangoeTrack's own Flutter admin app, so these are proven against real data
 * rather than inferred from their controllers.
 *
 * Two limits are inherent to their API and are NOT bugs in this class:
 *
 *   liveAttendance()   is TODAY ONLY. Their endpoint accepts no date, so there
 *                      is no history to ask for until we add one on their side.
 *   pending*()         is PENDING ONLY. Once something is approved it leaves the
 *                      queue for good — they have no endpoint that returns past
 *                      decisions, so the CRM cannot show an approval history yet.
 *
 * Both are called out on the screens rather than hidden, so an empty list never
 * gets misread as "nothing ever happened".
 */
class SangoeTrackAdmin
{
    public function __construct(private readonly SangoeTrackClient $client) {}

    /* ─────────────────────────── overview ─────────────────────────── */

    /**
     * Counters for the top of the attendance board: present, absent, late,
     * on_leave, and the month's approved reimbursement total.
     */
    public function dashboard(): array
    {
        return $this->client->call('admin_dashboard', $this->scope());
    }

    /**
     * Every employee's attendance for TODAY.
     *
     * Their payload carries clock_in_selfie, clock_in_lat and clock_in_lng,
     * which their own admin app fetches and then never displays. The CRM shows
     * them — it is the one thing here that is better on our side for free.
     *
     * @param  string  $status  all | present | absent | late | on_leave
     */
    public function liveAttendance(string $status = 'all'): array
    {
        return $this->client->call('admin_attendance_details', $this->scope([
            'status' => $status,
        ]));
    }

    /* ─────────────────────────── approvals ────────────────────────── */

    /**
     * All four queues in one call: leaves, raises, reimbursements, advances.
     * Pending only — see the class docblock.
     */
    public function pendingApprovals(): array
    {
        return $this->client->call('admin_pending_approvals', $this->scope());
    }

    public function pendingSettlements(): array
    {
        return $this->client->call('admin_pending_settlements', $this->scope());
    }

    public function decideLeave(int $leaveId, string $status, ?string $remark = null): array
    {
        return $this->client->call('admin_approve_leave', $this->scope([
            'leave_id' => $leaveId,
            'status'   => $status,
            'remark'   => $remark,
        ]));
    }

    /**
     * Approving a correction does two further things on their side: it writes or
     * updates the real attendance row, and it pushes a notification to the
     * employee's phone. That is the entire reason this goes through their API
     * instead of our own database connection.
     */
    public function decideCorrection(int $raiseId, string $status, ?string $remark = null): array
    {
        return $this->client->call('admin_approve_raise', $this->scope([
            'raise_id' => $raiseId,
            'status'   => $status,
            'remark'   => $remark,
        ]));
    }

    public function decideReimbursement(int $reimbursementId, string $status, ?string $remark = null): array
    {
        return $this->client->call('admin_approve_reimbursement', $this->scope([
            'reimbursement_id' => $reimbursementId,
            'status'           => $status,
            'remark'           => $remark,
        ]));
    }

    public function decideAdvance(int $advanceId, string $status, ?string $remark = null, ?float $amount = null): array
    {
        return $this->client->call('admin_approve_advance', $this->scope(array_filter([
            'advance_id' => $advanceId,
            'status'     => $status,
            'remark'     => $remark,
            // Their approve endpoint doubles as approve-with-modification: an
            // amount here approves for less than was asked for.
            'amount'     => $amount,
        ], static fn ($v) => $v !== null)));
    }

    /**
     * Record money actually leaving the company.
     *
     * The field names are THEIRS: payment_mode and utr_reference. This used to
     * send `mode` and `reference`, which their validator rejected — so every
     * disbursement failed, and nobody noticed because nobody had released money
     * through the CRM yet.
     *
     * @param  string  $mode  cash | bank_transfer | cheque | upi
     */
    public function disburseAdvance(
        int $advanceId,
        string $mode,
        ?string $reference = null,
        ?string $disbursedOn = null,
        ?string $notes = null,
    ): array {
        return $this->client->call('admin_disburse_advance', $this->scope(array_filter([
            'advance_id'    => $advanceId,
            'payment_mode'  => $mode,
            'utr_reference' => $reference,
            'disbursed_on'  => $disbursedOn,
            'notes'         => $notes,
        ], static fn ($v) => $v !== null)));
    }

    public function reviewSettlement(int $settlementId, string $status, ?string $remark = null): array
    {
        return $this->client->call('admin_review_settlement', $this->scope([
            'settlement_id' => $settlementId,
            'status'        => $status,
            'remark'        => $remark,
        ]));
    }

    /* ─────────────────────────── people ───────────────────────────── */

    public function employees(): array
    {
        return $this->client->call('admin_employees_list', $this->scope());
    }

    /** Roles this account is allowed to hand out — theirs, not ours. */
    public function assignableRoles(): array
    {
        return $this->client->call('admin_assignable_roles', $this->scope());
    }

    /**
     * @param  array{name: string, email: string, mobile_no?: string, role?: string, password?: string}  $data
     */
    public function createEmployee(array $data): array
    {
        return $this->client->call('admin_create_employee', $this->scope($data));
    }

    /**
     * SangoeTrack generates the password itself, emails it to the employee, and
     * returns it as `temp_password`. It takes no password — one sent here was
     * silently discarded, so the admin handed over something that never worked.
     */
    public function resetPassword(int $employeeUserId): array
    {
        return $this->client->call('admin_reset_password', $this->scope([
            'employee_user_id' => $employeeUserId,
        ]));
    }

    /* ─────────────────────────── payroll ──────────────────────────── */

    public function payrollOverview(): array
    {
        return $this->client->call('admin_payroll_overview', $this->scope());
    }

    public function setSalary(int $employeeId, float $salary, ?int $salaryType = null): array
    {
        return $this->client->call('admin_set_salary', $this->scope(array_filter([
            'employee_id' => $employeeId,
            'salary'      => $salary,
            'salary_type' => $salaryType,
        ], static fn ($v) => $v !== null)));
    }

    /* ─────────────────────────── reporting ────────────────────────── */

    /**
     * Their monthly report arrives PRE-FORMATTED for a phone screen — rupee
     * symbols inside strings, column labels, text alignment. It renders as-is
     * and cannot be sorted or restyled. A real data endpoint is on the build
     * list; until then this is what exists.
     *
     * @param  string  $month  YYYY-MM
     */
    public function reports(string $month): array
    {
        return $this->client->call('admin_reports', $this->scope(['month' => $month]));
    }

    public function reportsSummary(string $month): array
    {
        return $this->client->call('admin_reports_summary', $this->scope(['month' => $month]));
    }

    /* ─────────────────────────── demo requests ────────────────────── */

    public function demoRequests(): array
    {
        return $this->client->call('admin_demo_requests', $this->scope());
    }

    public function updateDemoRequest(int $id, string $status, ?string $notes = null): array
    {
        return $this->client->call('admin_update_demo_request', $this->scope([
            'id'     => $id,
            'status' => $status,
            'notes'  => $notes,
        ]));
    }

    /* ─────────────────────────── holidays ─────────────────────────── */

    public function holidays(): array
    {
        return $this->client->call('holidays', $this->scope());
    }

    /* ─────────────────────────── history ──────────────────────────── */

    /**
     * The records SangoeTrack's mobile API cannot reach.
     *
     * Its endpoints answer "what is waiting on me" — pending only, today only —
     * which is right for a phone and leaves this CRM unable to say what happened
     * last month. These five are read-only endpoints added on their side.
     *
     * Every one accepts: status, employee, from, to, page, per_page.
     * Advances also takes `type`; leaves also takes `leave_type`.
     * Omitting the range defaults to this month (attendance) or this year.
     *
     * @param  array<string, mixed>  $filters
     */
    public function attendanceHistory(array $filters = []): array
    {
        return $this->client->call('history_attendance', $this->scope($filters));
    }

    public function correctionHistory(array $filters = []): array
    {
        return $this->client->call('history_corrections', $this->scope($filters));
    }

    public function leaveHistory(array $filters = []): array
    {
        return $this->client->call('history_leaves', $this->scope($filters));
    }

    /** Also returns whole-set totals per status, not per-page ones. */
    public function reimbursementHistory(array $filters = []): array
    {
        return $this->client->call('history_reimbursements', $this->scope($filters));
    }

    public function advanceHistory(array $filters = []): array
    {
        return $this->client->call('history_advances', $this->scope($filters));
    }

    /* ─────────────────────────── settings ─────────────────────────── */

    /**
     * The four endpoints below are ones we added on SangoeTrack rather than
     * ones it shipped with — they live in a route file of their own there, so
     * nothing the published mobile app calls had to be changed.
     *
     * They read and write exactly where SangoeTrack's own web admin does, so a
     * change made here shows up there and neither interface can drift.
     */
    public function hrmSettings(): array
    {
        return $this->client->call('crm_hrm_settings', $this->scope());
    }

    /**
     * Only the keys present are written; anything omitted is left as it was, so
     * a partial save cannot blank settings it never mentioned.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveHrmSettings(array $values): array
    {
        return $this->client->call('crm_hrm_settings_save', $this->scope($values));
    }

    public function whatsappSettings(): array
    {
        return $this->client->call('crm_whatsapp_settings', $this->scope());
    }

    /**
     * @param  array<string, bool>  $values
     */
    public function saveWhatsappSettings(array $values): array
    {
        return $this->client->call('crm_whatsapp_settings_save', $this->scope($values));
    }

    /* ─────────────────────────── internals ────────────────────────── */

    /**
     * Their endpoints all expect workspace_id in the body, so it is added once
     * here rather than at twenty call sites.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function scope(array $payload = []): array
    {
        return $payload + ['workspace_id' => (int) config('sangoetrack.workspace_id')];
    }
}

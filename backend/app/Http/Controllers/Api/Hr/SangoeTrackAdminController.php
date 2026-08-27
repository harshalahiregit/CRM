<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\SangoeTrack\SangoeTrackAdmin;
use App\Services\SangoeTrack\SangoeTrackException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * The CRM's side of the SangoeTrack admin screens.
 *
 * The browser never talks to track.sangoe.in directly. It calls here, and this
 * calls them. That is deliberate on three counts:
 *
 *   - no CORS change is needed on their side, which would mean editing and
 *     deploying a file on the live system people clock into every morning;
 *   - the SangoeTrack login stays on the server instead of sitting in a browser
 *     where anyone with devtools can lift it;
 *   - our own auth and role gate apply, so who may approve a leave is decided by
 *     code we control.
 *
 * Every action currently reaches SangoeTrack as ONE shared account, so their
 * records will name that account as the approver rather than the individual CRM
 * user. That was a deliberate choice to keep the first release simple. It is
 * fine while these screens are read-mostly and becomes a real problem once
 * approvals are in daily use — at which point each CRM admin needs their own
 * SangoeTrack login and this class passes their token instead.
 */
class SangoeTrackAdminController extends Controller
{
    public function __construct(private readonly SangoeTrackAdmin $track) {}

    /* ─────────────────────────── overview ─────────────────────────── */

    public function dashboard(): JsonResponse
    {
        return $this->relay(fn () => $this->track->dashboard());
    }

    /**
     * Today's attendance for everyone.
     *
     * There is no date parameter because SangoeTrack does not offer one. The
     * screen says so rather than rendering an empty table that reads as "nobody
     * came to work".
     */
    public function attendance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => 'nullable|in:all,present,absent,late,on_leave',
        ]);

        return $this->relay(fn () => $this->track->liveAttendance($data['status'] ?? 'all'));
    }

    /* ─────────────────────────── approvals ────────────────────────── */

    public function pendingApprovals(): JsonResponse
    {
        return $this->relay(fn () => $this->track->pendingApprovals());
    }

    public function pendingSettlements(): JsonResponse
    {
        return $this->relay(fn () => $this->track->pendingSettlements());
    }

    public function decideLeave(Request $request): JsonResponse
    {
        $data = $this->decision($request, ['leave_id' => 'required|integer']);

        return $this->relay(fn () => $this->track->decideLeave(
            $data['leave_id'], $data['status'], $data['remark'] ?? null
        ), 'leave', $data);
    }

    public function decideCorrection(Request $request): JsonResponse
    {
        $data = $this->decision($request, ['raise_id' => 'required|integer']);

        return $this->relay(fn () => $this->track->decideCorrection(
            $data['raise_id'], $data['status'], $data['remark'] ?? null
        ), 'attendance correction', $data);
    }

    public function decideReimbursement(Request $request): JsonResponse
    {
        $data = $this->decision($request, ['reimbursement_id' => 'required|integer']);

        return $this->relay(fn () => $this->track->decideReimbursement(
            $data['reimbursement_id'], $data['status'], $data['remark'] ?? null
        ), 'reimbursement', $data);
    }

    public function decideAdvance(Request $request): JsonResponse
    {
        $data = $this->decision($request, [
            'advance_id' => 'required|integer',
            // Present when approving for less than was requested.
            'amount'     => 'nullable|numeric|min:0',
        ]);

        return $this->relay(fn () => $this->track->decideAdvance(
            $data['advance_id'], $data['status'], $data['remark'] ?? null,
            isset($data['amount']) ? (float) $data['amount'] : null
        ), 'advance', $data);
    }

    public function disburseAdvance(Request $request): JsonResponse
    {
        $data = $request->validate([
            'advance_id' => 'required|integer',
            'mode'       => 'required|in:cash,cheque,bank_transfer',
            // The cheque number or UTR. Required for everything except cash,
            // because a bank transfer with no reference cannot be reconciled.
            'reference'  => 'required_unless:mode,cash|nullable|string|max:120',
        ]);

        return $this->relay(fn () => $this->track->disburseAdvance(
            $data['advance_id'], $data['mode'], $data['reference'] ?? null
        ), 'advance disbursement', $data);
    }

    public function reviewSettlement(Request $request): JsonResponse
    {
        $data = $this->decision($request, ['settlement_id' => 'required|integer']);

        return $this->relay(fn () => $this->track->reviewSettlement(
            $data['settlement_id'], $data['status'], $data['remark'] ?? null
        ), 'settlement', $data);
    }

    /* ─────────────────────────── people ───────────────────────────── */

    public function employees(): JsonResponse
    {
        return $this->relay(fn () => $this->track->employees());
    }

    public function assignableRoles(): JsonResponse
    {
        return $this->relay(fn () => $this->track->assignableRoles());
    }

    public function createEmployee(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'      => 'required|string|max:150',
            'email'     => 'required|email|max:190',
            'mobile_no' => 'nullable|string|max:30',
            'role'      => 'nullable|string|max:60',
            'password'  => 'nullable|string|min:8|max:100',
        ]);

        return $this->relay(fn () => $this->track->createEmployee($data), 'employee creation', [
            'email' => $data['email'],
        ]);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_user_id' => 'required|integer',
            'password'         => 'required|string|min:8|max:100',
        ]);

        // The password itself is deliberately kept out of the audit context.
        return $this->relay(
            fn () => $this->track->resetPassword($data['employee_user_id'], $data['password']),
            'password reset',
            ['employee_user_id' => $data['employee_user_id']]
        );
    }

    /* ─────────────────────────── payroll ──────────────────────────── */

    public function payrollOverview(): JsonResponse
    {
        return $this->relay(fn () => $this->track->payrollOverview());
    }

    public function setSalary(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => 'required|integer',
            'salary'      => 'required|numeric|min:0',
            'salary_type' => 'nullable|integer',
        ]);

        return $this->relay(fn () => $this->track->setSalary(
            $data['employee_id'], (float) $data['salary'], $data['salary_type'] ?? null
        ), 'salary change', $data);
    }

    /* ─────────────────────────── reporting ────────────────────────── */

    public function reports(Request $request): JsonResponse
    {
        $data = $request->validate([
            'month' => ['required', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        return $this->relay(fn () => $this->track->reports($data['month']));
    }

    /* ─────────────────────────── demo requests ────────────────────── */

    public function demoRequests(): JsonResponse
    {
        return $this->relay(fn () => $this->track->demoRequests());
    }

    public function updateDemoRequest(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id'     => 'required|integer',
            'status' => 'required|string|max:40',
            'notes'  => 'nullable|string|max:2000',
        ]);

        return $this->relay(fn () => $this->track->updateDemoRequest(
            $data['id'], $data['status'], $data['notes'] ?? null
        ), 'demo request update', $data);
    }

    /* ─────────────────────────── holidays ─────────────────────────── */

    public function holidays(): JsonResponse
    {
        return $this->relay(fn () => $this->track->holidays());
    }

    /* ─────────────────────────── settings ─────────────────────────── */

    public function settings(): JsonResponse
    {
        // Both halves in one response so the screen renders once rather than
        // filling in twice. If WhatsApp is unreachable the HRM half still shows.
        return $this->relay(function () {
            $hrm = $this->track->hrmSettings();

            try {
                $whatsapp = $this->track->whatsappSettings();
            } catch (SangoeTrackException $e) {
                $whatsapp = ['data' => null];
            }

            return [
                'hrm'      => $hrm['data'] ?? [],
                'whatsapp' => $whatsapp['data'] ?? null,
            ];
        });
    }

    public function saveSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'employee_prefix'        => 'required|string|max:20',
            'company_start_time'     => 'required|string|max:10',
            'company_end_time'       => 'required|string|max:10',
            'hr_notification_email'  => 'nullable|email|max:150',
            'max_shift_hours'        => 'nullable|integer|min:1|max:24',
            'ip_restrict'            => 'nullable|in:on,off',
            'leave_paid_days'        => 'nullable|integer|min:0|max:365',
            'leave_casual_days'      => 'nullable|integer|min:0|max:365',
            'leave_unpaid_days'      => 'nullable|integer|min:0|max:365',
            'leave_comp_off_days'    => 'nullable|integer|min:0|max:365',
            'advance_manager_limit'  => 'nullable|numeric|min:0',
            // Accounts sits above manager in the approval chain, so its ceiling
            // cannot be the lower of the two. Checked here as well as on their
            // side so the message arrives without a round trip.
            'advance_accounts_limit' => 'nullable|numeric|min:0|gte:advance_manager_limit',
        ], [
            'advance_accounts_limit.gte' =>
                'The accounts approval limit must be at least the manager approval limit.',
        ]);

        return $this->relay(fn () => $this->track->saveHrmSettings($data), 'settings change', $data);
    }

    public function saveWhatsappSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'whatsapp_global_enabled' => 'required|boolean',
            'notify_leave'            => 'required|boolean',
            'notify_reimbursement'    => 'required|boolean',
            'notify_attendance_raise' => 'required|boolean',
            'notify_clock_reminder'   => 'required|boolean',
        ]);

        return $this->relay(fn () => $this->track->saveWhatsappSettings($data), 'whatsapp settings change', $data);
    }

    /* ─────────────────────────── internals ────────────────────────── */

    /**
     * The shape every decision endpoint shares.
     *
     * @param  array<string, string>  $extra
     * @return array<string, mixed>
     */
    private function decision(Request $request, array $extra): array
    {
        return $request->validate($extra + [
            'status' => 'required|in:approved,rejected',
            'remark' => 'nullable|string|max:1000',
        ]);
    }

    /**
     * Run a SangoeTrack call and turn its failures into something the CRM can
     * show a person.
     *
     * SangoeTrack answers a refused request with HTTP 200 and `status: 0`, which
     * the client turns into an exception — so a rejected approval surfaces here
     * as a 502 with their message rather than as a false success. Anything that
     * changed state on their side is logged with who did it, because their own
     * records will only show the shared service account.
     *
     * @param  callable(): array<string, mixed>  $call
     * @param  array<string, mixed>  $context
     */
    private function relay(callable $call, ?string $action = null, array $context = []): JsonResponse
    {
        try {
            $result = $call();
        } catch (SangoeTrackException $e) {
            Log::channel('hr')->warning('SangoeTrack call failed', [
                'action'  => $action,
                'user_id' => auth()->id(),
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage(),
                'source'  => 'sangoetrack',
            ], 502);
        }

        if ($action !== null) {
            // Our own trail of who actually did this, since theirs cannot say.
            Log::channel('hr')->info('SangoeTrack '.$action, [
                'user_id' => auth()->id(),
                'context' => $context,
            ]);
        }

        return response()->json($result);
    }
}

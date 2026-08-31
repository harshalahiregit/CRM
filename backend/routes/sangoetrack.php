<?php

use App\Http\Controllers\Api\Hr\SangoeTrackAdminController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| SangoeTrack — the CRM's window onto track.sangoe.in
|--------------------------------------------------------------------------
|
| These sit under /api/hr/track/ so they read as part of HR without colliding
| with anything the existing HR module owns. Nothing here touches a CRM table:
| every route relays to SangoeTrack, which stays the single owner of attendance,
| leave, reimbursement, advance and payroll data.
|
| Kept in its own file rather than appended to hr.php so that this integration
| can be read, reviewed and reverted as one piece.
|
| Gated to admin, hr and manager. The role middleware also lets a staff user
| through on a matching internal_role, which is how an HR executive reaches
| these without being given the blanket admin role.
|
| WORTH KNOWING before adding to this file: SangoeTrack's own admin API has a
| single coarse permission gate — any account that passes it may approve leave
| AND disburse money AND reset passwords. Their web admin checks ~190 individual
| permissions; their API checks one. So the gate below is the only meaningful
| restriction on these actions, which is why it is stated per route group rather
| than assumed.
|
*/

Route::middleware(['auth:sanctum', 'role:admin,hr,manager'])
    ->prefix('hr/track')
    ->group(function () {

        /* ── overview ─────────────────────────────────────────────── */
        Route::get('dashboard',  [SangoeTrackAdminController::class, 'dashboard']);
        // Today only — SangoeTrack accepts no date parameter here.
        Route::get('attendance', [SangoeTrackAdminController::class, 'attendance']);

        /* ── approval queues (pending only — see the controller) ──── */
        Route::get('approvals',   [SangoeTrackAdminController::class, 'pendingApprovals']);
        Route::get('settlements', [SangoeTrackAdminController::class, 'pendingSettlements']);

        /* ── decisions ────────────────────────────────────────────── */
        Route::post('leave/decide',          [SangoeTrackAdminController::class, 'decideLeave']);
        Route::post('correction/decide',     [SangoeTrackAdminController::class, 'decideCorrection']);
        Route::post('reimbursement/decide',  [SangoeTrackAdminController::class, 'decideReimbursement']);
        Route::post('advance/decide',        [SangoeTrackAdminController::class, 'decideAdvance']);
        Route::post('advance/disburse',      [SangoeTrackAdminController::class, 'disburseAdvance']);
        Route::post('settlement/review',     [SangoeTrackAdminController::class, 'reviewSettlement']);

        /* ── people ───────────────────────────────────────────────── */
        Route::get('employees',       [SangoeTrackAdminController::class, 'employees']);
        Route::get('roles',           [SangoeTrackAdminController::class, 'assignableRoles']);
        Route::post('employees',      [SangoeTrackAdminController::class, 'createEmployee']);
        Route::post('employees/password', [SangoeTrackAdminController::class, 'resetPassword']);

        /* ── payroll ──────────────────────────────────────────────── */
        Route::get('payroll',         [SangoeTrackAdminController::class, 'payrollOverview']);
        Route::post('payroll/salary', [SangoeTrackAdminController::class, 'setSalary']);

        /* ── reporting ────────────────────────────────────────────── */
        // Arrives pre-formatted for a phone screen; the UI renders it as sent.
        Route::get('reports', [SangoeTrackAdminController::class, 'reports']);

        /* ── demo requests ────────────────────────────────────────── */
        Route::get('demo-requests',  [SangoeTrackAdminController::class, 'demoRequests']);
        Route::post('demo-requests', [SangoeTrackAdminController::class, 'updateDemoRequest']);

        /* ── holidays ─────────────────────────────────────────────── */
        // The original read, still used by the calendar-shaped view.
        Route::get('holidays', [SangoeTrackAdminController::class, 'holidays']);
        // The editable set: rows with ids, and the three writes. These change
        // company-wide reference data — one wrong holiday moves everybody's
        // leave calculation — so they are gated the same as everything else and
        // the actions are logged with who did them.
        Route::get('holidays/list',      [SangoeTrackAdminController::class, 'holidayList']);
        Route::post('holidays',          [SangoeTrackAdminController::class, 'createHoliday']);
        Route::put('holidays',           [SangoeTrackAdminController::class, 'updateHoliday']);
        Route::delete('holidays',        [SangoeTrackAdminController::class, 'deleteHoliday']);

        /* ── history ──────────────────────────────────────────────── */
        // Read-only. The queues above are pending-only and attendance is
        // today-only; these are the only way to see what already happened.
        Route::get('history/attendance',     [SangoeTrackAdminController::class, 'attendanceHistory']);
        Route::get('history/corrections',    [SangoeTrackAdminController::class, 'correctionHistory']);
        Route::get('history/leaves',         [SangoeTrackAdminController::class, 'leaveHistory']);
        Route::get('history/reimbursements', [SangoeTrackAdminController::class, 'reimbursementHistory']);
        Route::get('history/advances',       [SangoeTrackAdminController::class, 'advanceHistory']);

        /* ── settings ─────────────────────────────────────────────── */
        // These relay to endpoints added on SangoeTrack for this CRM, not ones
        // it shipped with. They will 404 until that side is deployed — the
        // screen says so rather than showing an empty form.
        Route::get('settings',           [SangoeTrackAdminController::class, 'settings']);
        Route::post('settings',          [SangoeTrackAdminController::class, 'saveSettings']);
        Route::post('settings/whatsapp', [SangoeTrackAdminController::class, 'saveWhatsappSettings']);
    });

<?php

use App\Http\Controllers\Api\Hr\HRDashboardController;
use App\Http\Controllers\Api\Hr\ManpowerRequestController;
use App\Http\Controllers\Api\Hr\JobPostingController;
use App\Http\Controllers\Api\Hr\CandidateController;
use App\Http\Controllers\Api\Hr\CandidateNoteController;
use App\Http\Controllers\Api\Hr\CandidateDocumentController;
use App\Http\Controllers\Api\Hr\ResumeController;
use App\Http\Controllers\Api\Hr\InterviewController;
use App\Http\Controllers\Api\Hr\InterviewQuestionController;
use App\Http\Controllers\Api\Hr\OfferController;
use App\Http\Controllers\Api\Hr\OnboardingChecklistController;
use App\Http\Controllers\Api\Hr\PoshCaseController;
use App\Http\Controllers\Api\Hr\PoshCaseWorkController;
use App\Http\Controllers\Api\Hr\PoshReportController;
use App\Http\Controllers\Api\Hr\PoshCommitteeController;
use App\Http\Controllers\Api\Hr\OnboardingController;
use App\Http\Controllers\Api\Hr\EmployeeAssetController;
use App\Http\Controllers\Api\Hr\EmployeeController;
use App\Http\Controllers\Api\Hr\AttendanceController;
use App\Http\Controllers\Api\Hr\MyAttendanceController;
use App\Http\Controllers\Api\Hr\AdvanceController;
use App\Http\Controllers\Api\Hr\AttendanceReportController;
use App\Http\Controllers\Api\Hr\MyAdvanceController;
use App\Http\Controllers\Api\Hr\ApprovalWorkflowController;
use App\Http\Controllers\Api\Hr\ClearanceDepartmentController;
use App\Http\Controllers\Api\Hr\AttendanceCorrectionController;
use App\Http\Controllers\Api\Hr\MyAttendanceCorrectionController;
use App\Http\Controllers\Api\Hr\DemoRequestController;
use App\Http\Controllers\Api\Hr\HrSettingsController;
use App\Http\Controllers\Api\Hr\MyLeaveController;
use App\Http\Controllers\Api\Hr\MyReimbursementController;
use App\Http\Controllers\Api\Hr\ReimbursementController;
use App\Http\Controllers\Api\Hr\SangoeTrackSyncController;
use App\Http\Controllers\Api\Hr\ExitInterviewController;
use App\Http\Controllers\Api\Hr\OrganizationController;
use App\Http\Controllers\Api\Hr\SalaryComponentController;
use App\Http\Controllers\Api\Hr\SalaryStructureController;
use App\Http\Controllers\Api\Hr\EmployeeSalaryController;
use App\Http\Controllers\Api\Hr\SalaryReportController;
use App\Http\Controllers\Api\Hr\StatutoryRuleController;
use App\Http\Controllers\Api\Hr\InvestmentDeclarationController;
use App\Http\Controllers\Api\Hr\ShiftController;
use App\Http\Controllers\Api\Hr\WorkplaceController;
use App\Http\Controllers\Api\Hr\LoanController;
use App\Http\Controllers\Api\Hr\EmployeeMovementController;
use App\Http\Controllers\Api\Hr\EmployeeLifecycleController;
use App\Http\Controllers\Api\Hr\OrgChartController;
use App\Http\Controllers\Api\Hr\EmployeeScoreController;
use App\Http\Controllers\Api\Hr\ExitQuestionnaireController;
use App\Http\Controllers\Api\Hr\VariableEarningController;
use App\Http\Controllers\Api\Hr\DirectoryController;
use App\Http\Controllers\Api\Hr\LetterController;
use App\Http\Controllers\Api\Hr\PayrollRunController;
use App\Http\Controllers\Api\Hr\PayrollWorkflowController;
use App\Http\Controllers\Api\Hr\PayslipController;
use App\Http\Controllers\Api\Hr\PayrollReportController;
use App\Http\Controllers\Api\Hr\StatutoryRegisterController;
use Illuminate\Support\Facades\Route;

// ── HR Module Routes (Sanctum) ──────────────────────────────────────────
Route::middleware('auth:sanctum')->prefix('hr')->group(function () {

    // Dashboard — company-wide headcount, attrition and attendance figures.
    // Sat in this auth-only group with no check inside the controller either, so
    // any signed-in account could read them.
    Route::get('/dashboard', [HRDashboardController::class, 'index'])
        ->middleware('permission:hr_attendance,view_global');

    // Manpower Requests — L1/L2 Approval Workflow → HR Queue → JD → Job Posting
    Route::get('/manpower-requests',                            [ManpowerRequestController::class, 'index']);
    Route::get('/manpower-requests/stats',                      [ManpowerRequestController::class, 'stats']);
    Route::get('/manpower-requests/queue',                      [ManpowerRequestController::class, 'queue']);
    Route::get('/manpower-requests/pending-count',              [ManpowerRequestController::class, 'pendingCount']);
    Route::get('/manpower-requests/projects',                   [ManpowerRequestController::class, 'projects']);
    Route::get('/manpower-requests/form-options',               [ManpowerRequestController::class, 'formOptions']);
    Route::get('/manpower-requests/pending-approvals',          [ManpowerRequestController::class, 'pendingApprovals']);
    Route::get('/manpower-requests/jd-templates',               [ManpowerRequestController::class, 'jdTemplates']);
    Route::post('/manpower-requests',                           [ManpowerRequestController::class, 'store']);
    Route::get('/manpower-requests/{manpowerRequest}',          [ManpowerRequestController::class, 'show']);
    Route::put('/manpower-requests/{manpowerRequest}',          [ManpowerRequestController::class, 'update']);
    Route::delete('/manpower-requests/{manpowerRequest}',       [ManpowerRequestController::class, 'destroy']);
    // L1/L2 Approval actions
    Route::post('/manpower-requests/{manpowerRequest}/submit',      [ManpowerRequestController::class, 'submit']);
    Route::post('/manpower-requests/{manpowerRequest}/approve-l1',  [ManpowerRequestController::class, 'approveL1']);
    Route::post('/manpower-requests/{manpowerRequest}/reject-l1',   [ManpowerRequestController::class, 'rejectL1']);
    Route::post('/manpower-requests/{manpowerRequest}/approve-l2',  [ManpowerRequestController::class, 'approveL2']);
    Route::post('/manpower-requests/{manpowerRequest}/reject-l2',   [ManpowerRequestController::class, 'rejectL2']);
    Route::post('/manpower-requests/{manpowerRequest}/send-back',   [ManpowerRequestController::class, 'sendBack']);
    Route::post('/manpower-requests/{manpowerRequest}/reconsider',  [ManpowerRequestController::class, 'reconsider']);
    // HR Queue actions (post-approval): convert → publish → hiring → close
    Route::post('/manpower-requests/{manpowerRequest}/generate-jd',   [ManpowerRequestController::class, 'generateJd']);
    Route::post('/manpower-requests/{manpowerRequest}/template-jd',   [ManpowerRequestController::class, 'templateJd']);
    Route::post('/manpower-requests/{manpowerRequest}/analyze-jd',    [ManpowerRequestController::class, 'analyzeJd']);
    Route::post('/manpower-requests/{manpowerRequest}/jd-improvement-decision', [ManpowerRequestController::class, 'jdImprovementDecision']);
    Route::post('/manpower-requests/{manpowerRequest}/convert-to-jd', [ManpowerRequestController::class, 'convertToJd']);
    Route::post('/manpower-requests/{manpowerRequest}/publish',       [ManpowerRequestController::class, 'publish']);
    Route::post('/manpower-requests/{manpowerRequest}/close',         [ManpowerRequestController::class, 'close']);
    Route::patch('/manpower-requests/{manpowerRequest}/assign-manager', [ManpowerRequestController::class, 'assignManager']);

    // Job Postings — Recruitment Workspace
    Route::get('/jobs',                            [JobPostingController::class, 'index']);
    Route::get('/jobs/stats',                      [JobPostingController::class, 'stats']);
    Route::get('/jobs/channels',                   [JobPostingController::class, 'channels']);
    Route::post('/jobs/bulk',                      [JobPostingController::class, 'bulk']);
    Route::post('/jobs/analyze-jd',                [JobPostingController::class, 'analyzeJd']);
    Route::post('/jobs',                           [JobPostingController::class, 'store']);
    Route::get('/jobs/{jobPosting}',               [JobPostingController::class, 'show']);
    Route::put('/jobs/{jobPosting}',               [JobPostingController::class, 'update']);
    Route::patch('/jobs/{jobPosting}/status',      [JobPostingController::class, 'updateStatus']);
    Route::patch('/jobs/{jobPosting}/external-id', [JobPostingController::class, 'updateExternalId']);
    Route::delete('/jobs/{jobPosting}',            [JobPostingController::class, 'destroy']);
    // Lifecycle actions
    Route::post('/jobs/{jobPosting}/publish',      [JobPostingController::class, 'publish']);
    Route::post('/jobs/{jobPosting}/unpublish',    [JobPostingController::class, 'unpublish']);
    Route::post('/jobs/{jobPosting}/pause',        [JobPostingController::class, 'pause']);
    Route::post('/jobs/{jobPosting}/close',        [JobPostingController::class, 'close']);
    Route::post('/jobs/{jobPosting}/cancel',       [JobPostingController::class, 'cancel']);
    Route::post('/jobs/{jobPosting}/duplicate',    [JobPostingController::class, 'duplicate']);
    // Distribution channels (Career Portal, and future LinkedIn/Naukri/Indeed/TrulyTalents)
    Route::post('/jobs/{jobPosting}/publish-to',            [JobPostingController::class, 'publishTo']);
    Route::post('/jobs/{jobPosting}/publish-channels',      [JobPostingController::class, 'publishChannels']);
    Route::delete('/jobs/{jobPosting}/publish-to/{channel}',[JobPostingController::class, 'unpublishFrom']);
    // #13 — reconcile the ledger with what the channel currently reports.
    Route::post('/jobs/{jobPosting}/sync/{channel}',        [JobPostingController::class, 'syncChannel']);

    // Candidates
    Route::get('/candidates',                           [CandidateController::class, 'index']);
    Route::get('/candidates/recruiters',                [CandidateController::class, 'recruiters']);
    Route::post('/candidates',                          [CandidateController::class, 'store']);
    Route::post('/candidates/linkedin-parse',           [CandidateController::class, 'linkedinParse']);
    Route::get('/candidates/{candidate}/score',         [CandidateController::class, 'score']);
    Route::get('/candidates/{candidate}/journey',       [CandidateController::class, 'journey']);
    Route::get('/candidates/{candidate}/communications', [CandidateController::class, 'communications']);
    Route::get('/candidates/{candidate}/communication-preview', [CandidateController::class, 'communicationPreview']);
    Route::post('/candidates/{candidate}/communicate',  [CandidateController::class, 'communicate']);
    Route::post('/candidates/{candidate}/reminder',     [CandidateController::class, 'scheduleReminder']);
    Route::get('/candidates/{candidate}',               [CandidateController::class, 'show']);
    Route::put('/candidates/{candidate}',               [CandidateController::class, 'update']);
    Route::patch('/candidates/{candidate}/stage',       [CandidateController::class, 'updateStage']);
    Route::patch('/candidates/{candidate}/decision',    [CandidateController::class, 'updateDecision']);
    Route::patch('/candidates/{candidate}/assign',      [CandidateController::class, 'assign']);
    Route::delete('/candidates/{candidate}',            [CandidateController::class, 'destroy']);
    // Resume upload / download / delete
    Route::post('/candidates/{candidate}/resume',       [ResumeController::class, 'upload']);
    Route::get('/candidates/{candidate}/resume',        [ResumeController::class, 'download']);
    Route::delete('/candidates/{candidate}/resume',     [ResumeController::class, 'delete']);
    // #15 — re-read an already-uploaded resume for Dept / Designation / Present Co.
    // / Reference. Runs automatically on upload; this is the button for the
    // resumes that were already on disk before that existed.
    Route::post('/candidates/{candidate}/resume/extract', [ResumeController::class, 'extract']);
    // Collaborative notes thread
    Route::get('/candidates/{candidate}/notes',                 [CandidateNoteController::class, 'index']);
    Route::post('/candidates/{candidate}/notes',                [CandidateNoteController::class, 'store']);
    Route::delete('/candidates/{candidate}/notes/{note}',       [CandidateNoteController::class, 'destroy']);
    // Documents (typed, beyond the primary resume)
    Route::get('/candidates/{candidate}/documents',             [CandidateDocumentController::class, 'index']);
    Route::post('/candidates/{candidate}/documents',            [CandidateDocumentController::class, 'store']);
    Route::get('/candidates/{candidate}/documents/{document}',  [CandidateDocumentController::class, 'download']);
    Route::delete('/candidates/{candidate}/documents/{document}',[CandidateDocumentController::class, 'destroy']);

    // Interviews
    Route::get('/interviews',                               [InterviewController::class, 'index']);
    Route::get('/interviews/stats',                         [InterviewController::class, 'stats']);
    Route::get('/interview-panel/users',                    [InterviewController::class, 'panelUsers']);
    Route::get('/interview-panel/organizations',            [InterviewController::class, 'panelOrganizations']);
    // #10 — interview question bank, sets, AI generation and round integration.
    // Declared BEFORE /interviews/{interviewRound} so "question-bank" is never
    // captured as a round id.
    Route::get('/interview-questions/meta',        [InterviewQuestionController::class, 'meta']);
    Route::get('/interview-questions/sets',        [InterviewQuestionController::class, 'sets']);
    Route::post('/interview-questions/sets',       [InterviewQuestionController::class, 'storeSet']);
    Route::put('/interview-questions/sets/{id}',   [InterviewQuestionController::class, 'updateSet'])->whereNumber('id');
    Route::delete('/interview-questions/sets/{id}',[InterviewQuestionController::class, 'destroySet'])->whereNumber('id');
    Route::post('/interview-questions/generate',   [InterviewQuestionController::class, 'generate']);
    Route::post('/interview-questions/generated',  [InterviewQuestionController::class, 'storeGenerated']);
    Route::get('/interview-questions',             [InterviewQuestionController::class, 'index']);
    Route::post('/interview-questions',            [InterviewQuestionController::class, 'store']);
    Route::put('/interview-questions/{id}',        [InterviewQuestionController::class, 'update'])->whereNumber('id');
    Route::patch('/interview-questions/{id}/toggle', [InterviewQuestionController::class, 'toggle'])->whereNumber('id');
    Route::delete('/interview-questions/{id}',     [InterviewQuestionController::class, 'destroy'])->whereNumber('id');

    Route::get('/interviews/{interviewRound}/questions',    [InterviewQuestionController::class, 'roundQuestions']);
    Route::post('/interviews/{interviewRound}/questions',   [InterviewQuestionController::class, 'attach']);
    Route::post('/interviews/{interviewRound}/questions/evaluate', [InterviewQuestionController::class, 'evaluate']);
    Route::delete('/interviews/{interviewRound}/questions/{roundQuestionId}', [InterviewQuestionController::class, 'detach'])->whereNumber('roundQuestionId');

    Route::get('/interviews/{interviewRound}/email-preview', [InterviewController::class, 'emailPreview']);
    Route::post('/interviews',                              [InterviewController::class, 'store']);
    Route::get('/interviews/{interviewRound}',              [InterviewController::class, 'show']);
    Route::put('/interviews/{interviewRound}',              [InterviewController::class, 'update']);
    Route::patch('/interviews/{interviewRound}/feedback',   [InterviewController::class, 'recordFeedback']);
    Route::patch('/interviews/{interviewRound}/cancel',     [InterviewController::class, 'cancel']);
    Route::post('/interviews/{interviewRound}/meet-link',   [InterviewController::class, 'generateMeetLink']);
    Route::post('/interviews/{interviewRound}/notify',      [InterviewController::class, 'sendNotification']);
    Route::delete('/interviews/{interviewRound}',           [InterviewController::class, 'destroy']);

    // Offers
    Route::get('/offers',                           [OfferController::class, 'index']);
    Route::get('/offers/joining-buckets',           [OfferController::class, 'joiningBuckets']);
    Route::post('/offers',                          [OfferController::class, 'store']);
    Route::get('/offers/{offer}',                   [OfferController::class, 'show']);
    Route::patch('/offers/{offer}/send',            [OfferController::class, 'send']);
    Route::patch('/offers/{offer}/status',          [OfferController::class, 'updateStatus']);
    Route::patch('/offers/{offer}/confirm-joining', [OfferController::class, 'confirmJoining']);
    Route::patch('/offers/{offer}/regenerate',      [OfferController::class, 'regenerate']);
    // Lifecycle: approval · withdraw · revise · extend · history
    Route::patch('/offers/{offer}/submit-approval', [OfferController::class, 'submitForApproval']);
    Route::patch('/offers/{offer}/approve',         [OfferController::class, 'approve']);
    Route::patch('/offers/{offer}/withdraw',        [OfferController::class, 'withdraw']);
    Route::patch('/offers/{offer}/revise',          [OfferController::class, 'revise']);
    Route::patch('/offers/{offer}/extend',          [OfferController::class, 'extend']);
    Route::get('/offers/{offer}/revisions',         [OfferController::class, 'revisions']);
    // Authenticated, tenant-scoped offer letter for HR — replaces staff reading
    // the candidate's public /api/offer/{token}/letter route.
    Route::get('/offers/{offer}/letter',            [OfferController::class, 'letter']);
    // Controlled reissue / revocation of the candidate's portal credential.
    Route::post('/offers/{offer}/portal-link',      [OfferController::class, 'issuePortalLink']);
    Route::delete('/offers/{offer}/portal-link',    [OfferController::class, 'revokePortalLink']);
    Route::delete('/offers/{offer}',                [OfferController::class, 'destroy']);

    // Onboarding
    Route::get('/onboarding',                           [OnboardingController::class, 'index']);
    Route::post('/onboarding',                          [OnboardingController::class, 'store']);
    Route::get('/onboarding/{onboarding}',              [OnboardingController::class, 'show']);
    Route::patch('/onboarding/{onboarding}/verify',     [OnboardingController::class, 'verify']);
    Route::get('/onboarding/{onboarding}/documents/{document}', [OnboardingController::class, 'downloadDocument']);
    Route::patch('/onboarding/{onboarding}/documents/{document}/verify', [OnboardingController::class, 'verifyDocument']);
    Route::patch('/onboarding/{onboarding}/step',       [OnboardingController::class, 'toggleStep']);
    // The candidate's portal credential. Issuing returns the raw link ONCE and
    // revokes whatever was live before it; there is deliberately no GET, because
    // the raw value is not stored and cannot be handed back a second time.
    Route::post('/onboarding/{onboarding}/portal-link',   [OnboardingController::class, 'issuePortalLink']);
    Route::delete('/onboarding/{onboarding}/portal-link', [OnboardingController::class, 'revokePortalLink']);

    Route::delete('/onboarding/{onboarding}',           [OnboardingController::class, 'destroy']);

    // #37 — the employee's Projects / Tasks / Tickets / KB, with jump links.
    // Read-only aggregation over the existing modules; nothing is duplicated.
    Route::get('/employees/{employeeId}/lifecycle', [EmployeeLifecycleController::class, 'show'])->whereNumber('employeeId');
    Route::get('/employees/{employeeId}/loans',     [EmployeeLifecycleController::class, 'employeeLoans'])->whereNumber('employeeId');

    // #39/#40 — employee overall score and insights. Declared before the
    // generic /employees/{employee} routes so the sub-paths bind correctly.
    Route::get('/employees/{employee}/score',              [EmployeeScoreController::class, 'show'])->whereNumber('employee');
    Route::get('/employees/{employee}/score/preview',      [EmployeeScoreController::class, 'preview'])->whereNumber('employee');
    Route::post('/employees/{employee}/score/recalculate', [EmployeeScoreController::class, 'recalculate'])->whereNumber('employee');
    Route::post('/employees/{employee}/insights',          [EmployeeScoreController::class, 'insights'])->whereNumber('employee');

    // #29 — derived on read from reporting_manager_id; there is no chart to store.
    Route::get('/org-chart', [OrgChartController::class, 'index']);

    // #38 — loan recovery visibility across the payroll ecosystem. Read-only:
    // no payroll figure is touched, so a current run cannot be affected.
    Route::get('/loans/recovery/outstanding',   [EmployeeLifecycleController::class, 'outstandingLoans']);
    Route::get('/loans/{loanId}/recovery',      [EmployeeLifecycleController::class, 'loanRecovery'])->whereNumber('loanId');
    Route::get('/payroll/runs/{runId}/loan-recovery', [EmployeeLifecycleController::class, 'runRecovery'])->whereNumber('runId');

    // Employee movements — department transfer (#41) and promotion/demotion (#42),
    // plus the skill-fit analysis (#43). Declared before /employees/{employee}.
    Route::get('/movements/meta',              [EmployeeMovementController::class, 'meta']);
    Route::get('/movements',                   [EmployeeMovementController::class, 'index']);
    Route::post('/movements',                  [EmployeeMovementController::class, 'store']);
    Route::post('/movements/recommendations/{id}/action', [EmployeeMovementController::class, 'actionRecommendation'])->whereNumber('id');
    Route::get('/movements/employees/{employeeId}',       [EmployeeMovementController::class, 'history'])->whereNumber('employeeId');
    Route::get('/employees/{employeeId}/skills',          [EmployeeMovementController::class, 'skills'])->whereNumber('employeeId');
    Route::put('/employees/{employeeId}/skills',          [EmployeeMovementController::class, 'updateSkills'])->whereNumber('employeeId');
    Route::post('/employees/{employeeId}/skills/preview', [EmployeeMovementController::class, 'previewSkills'])->whereNumber('employeeId');

    // Employees
    // Static segments must precede /employees/{employee}, or the binding swallows them.
    Route::get('/employees/work-states',     [EmployeeController::class, 'workStates']);
    Route::get('/employees/stats',          [EmployeeController::class, 'stats']);
    Route::get('/employees',                [EmployeeController::class, 'index']);
    Route::post('/employees',               [EmployeeController::class, 'store']);
    Route::get('/employees/{employee}/profile', [EmployeeController::class, 'profile']);
    // The extended record. Declared beside /profile so both sit under the same
    // tenant assertion the controller makes.
    Route::get('/employees/{employee}/detail',  [EmployeeController::class, 'detail']);
    Route::put('/employees/{employee}/detail',  [EmployeeController::class, 'updateDetail']);

    /*
    | Entry-to-exit letters.
    |
    | The offer, appointment and confirmation ends of the list were built; the
    | exit end was not, so the one document a departing person actually needs —
    | the thing their next employer asks for — was typed by hand.
    |
    | Each refuses to issue early, because of what it ASSERTS: a relieving
    | letter states that dues are settled, and issuing one before clearance
    | means certifying that in writing to a third party who will rely on it.
    */
    /*
    | Where the staff and employee directories disagree.
    |
    | The instruction was one directory, not two. They are not merged, because
    | they are not duplicates: `users` is a login account and `hr_employees` is
    | an employment record, neither contains the other, and Tasks, Helpdesk and
    | ticket threads all resolve their assignable-people lists from the staff
    | side. The complaint was that somebody is added in one place and missing
    | from the other — which is a reconciliation problem, solved by showing the
    | gap rather than by a migration across four other modules.
    */
    Route::get('/directory/reconciliation', [DirectoryController::class, 'reconciliation']);
    Route::post('/employees/{employee}/link-login', [DirectoryController::class, 'link'])->whereNumber('employee');
    Route::post('/employees/{employee}/provision-login', [DirectoryController::class, 'provision'])->whereNumber('employee');

    Route::get('/employees/{employee}/letters', [LetterController::class, 'available'])->whereNumber('employee');
    Route::get('/employees/{employee}/letters/{type}', [LetterController::class, 'download'])
        ->whereNumber('employee')
        ->whereIn('type', \App\Services\Hr\LetterService::TYPES);

    // Exit Interview (SPK-1) — internal form, reuses the employee record for prefill.
    Route::get('/exit-interviews',                        [ExitInterviewController::class, 'index']);
    Route::get('/employees/{employee}/exit-interview',    [ExitInterviewController::class, 'show']);
    Route::post('/employees/{employee}/exit-interview',   [ExitInterviewController::class, 'store']);

    // #44 — exit questionnaire templates. `resolve` is intentionally ungated:
    // the leaver filling in the form is not an HR user.
    Route::get('/exit-questionnaires/resolve',      [ExitQuestionnaireController::class, 'resolve']);
    Route::get('/exit-questionnaires',              [ExitQuestionnaireController::class, 'index']);
    Route::get('/exit-questionnaires/{id}',         [ExitQuestionnaireController::class, 'show'])->whereNumber('id');
    Route::post('/exit-questionnaires',             [ExitQuestionnaireController::class, 'store']);
    Route::put('/exit-questionnaires/{id}',         [ExitQuestionnaireController::class, 'update'])->whereNumber('id');
    Route::delete('/exit-questionnaires/{id}',      [ExitQuestionnaireController::class, 'destroy'])->whereNumber('id');

    // #31 — commissions and incentives.
    Route::get('/variable-earnings/components',     [VariableEarningController::class, 'components']);
    Route::get('/variable-earnings',                [VariableEarningController::class, 'index']);
    Route::post('/variable-earnings',               [VariableEarningController::class, 'store']);
    Route::put('/variable-earnings/{id}',           [VariableEarningController::class, 'update'])->whereNumber('id');
    Route::post('/variable-earnings/{id}/approve',  [VariableEarningController::class, 'approve'])->whereNumber('id');
    Route::post('/variable-earnings/{id}/reject',   [VariableEarningController::class, 'reject'])->whereNumber('id');
    Route::delete('/variable-earnings/{id}',        [VariableEarningController::class, 'destroy'])->whereNumber('id');
    Route::get('/employees/{employee}/attendance', [AttendanceController::class, 'employeeAttendance']);

    // Assets — read-only views onto the Inventory register. HRMS owns no asset data.
    //
    // Gated: this is the serial number of the laptop and phone issued to a named
    // colleague. The controller makes no check of its own, so until now any
    // signed-in account could walk the employee ids and read the lot.
    Route::get('/employees/{employee}/assets/summary', [EmployeeAssetController::class, 'summary'])
        ->middleware('permission:hr_attendance,view_global');
    Route::get('/employees/{employee}/assets/{asset}', [EmployeeAssetController::class, 'show'])
        ->where('asset', '[0-9]+')->middleware('permission:hr_attendance,view_global');
    Route::get('/employees/{employee}/assets',         [EmployeeAssetController::class, 'index'])
        ->middleware('permission:hr_attendance,view_global');

    Route::get('/employees/{employee}',     [EmployeeController::class, 'show']);
    Route::put('/employees/{employee}',     [EmployeeController::class, 'update']);
    Route::delete('/employees/{employee}',  [EmployeeController::class, 'destroy']);

    // ── Organization Setup — Department / Designation / Grade / Role masters ──
    Route::get('/organization/overview',  [OrganizationController::class, 'overview']);
    Route::get('/organization/options',   [OrganizationController::class, 'options']);
    // ONE shared master-data feed consumed by every Recruitment dropdown.
    Route::get('/master-data',            [OrganizationController::class, 'masterData']);
    Route::get('/organization/hierarchy', [OrganizationController::class, 'hierarchy']);

    Route::get('/departments',          [OrganizationController::class, 'departments']);
    Route::post('/departments',         [OrganizationController::class, 'storeDepartment']);
    Route::put('/departments/{id}',     [OrganizationController::class, 'updateDepartment']);
    Route::delete('/departments/{id}',  [OrganizationController::class, 'destroyDepartment']);

    Route::get('/designations',         [OrganizationController::class, 'designations']);
    Route::post('/designations',        [OrganizationController::class, 'storeDesignation']);
    Route::put('/designations/{id}',    [OrganizationController::class, 'updateDesignation']);
    Route::delete('/designations/{id}', [OrganizationController::class, 'destroyDesignation']);

    Route::get('/grades',               [OrganizationController::class, 'grades']);
    Route::post('/grades',              [OrganizationController::class, 'storeGrade']);
    Route::put('/grades/{id}',          [OrganizationController::class, 'updateGrade']);
    Route::delete('/grades/{id}',       [OrganizationController::class, 'destroyGrade']);

    Route::get('/org-roles',            [OrganizationController::class, 'roles']);
    Route::post('/org-roles',           [OrganizationController::class, 'storeRole']);
    Route::put('/org-roles/{id}',       [OrganizationController::class, 'updateRole']);
    Route::delete('/org-roles/{id}',    [OrganizationController::class, 'destroyRole']);

    // Employment Types — Permanent, Contract, Intern, or whatever this company
    // calls them. An org master like the four above, managed on the same screen.
    Route::get('/employment-types',         [OrganizationController::class, 'employmentTypes']);
    Route::post('/employment-types',        [OrganizationController::class, 'storeEmploymentType']);
    Route::put('/employment-types/{id}',    [OrganizationController::class, 'updateEmploymentType']);
    Route::delete('/employment-types/{id}', [OrganizationController::class, 'destroyEmploymentType']);

    // ── Payroll → Salary Components master (Phase 1). No hard delete — status toggle only.
    //    The /payroll/* prefix reserves the namespace for future phases (structures, etc.).
    Route::get('/payroll/salary-components',              [SalaryComponentController::class, 'index']);
    Route::post('/payroll/salary-components',             [SalaryComponentController::class, 'store']);
    Route::put('/payroll/salary-components/{id}',         [SalaryComponentController::class, 'update']);
    Route::patch('/payroll/salary-components/{id}/status',[SalaryComponentController::class, 'updateStatus']);

    // Payroll → Salary Structures (Phase 2). Composes components into a computed CTC.
    // Enterprise Salary Engine adds live preview + duplicate (additive).
    Route::get('/payroll/salary-structures',                 [SalaryStructureController::class, 'index']);
    Route::post('/payroll/salary-structures/preview',        [SalaryStructureController::class, 'preview']);
    Route::get('/payroll/salary-structures/{id}',            [SalaryStructureController::class, 'show'])->whereNumber('id');
    Route::post('/payroll/salary-structures',               [SalaryStructureController::class, 'store']);
    Route::post('/payroll/salary-structures/{id}/duplicate',[SalaryStructureController::class, 'duplicate'])->whereNumber('id');
    Route::put('/payroll/salary-structures/{id}',           [SalaryStructureController::class, 'update'])->whereNumber('id');
    Route::patch('/payroll/salary-structures/{id}/status',  [SalaryStructureController::class, 'updateStatus'])->whereNumber('id');

    // Payroll → Employee Salary Assignment (Phase 3). Frozen snapshot; single active per employee.
    // Enterprise Salary Engine adds the read-only revision ledger (additive).
    Route::get('/payroll/employees/{employeeId}/salary',                 [EmployeeSalaryController::class, 'show']);
    Route::get('/payroll/employees/{employeeId}/salary/revisions',       [EmployeeSalaryController::class, 'revisions']);
    Route::post('/payroll/employees/{employeeId}/salary',                [EmployeeSalaryController::class, 'store']);
    Route::put('/payroll/employees/{employeeId}/salary/{id}',            [EmployeeSalaryController::class, 'update'])->whereNumber('id');
    Route::patch('/payroll/employees/{employeeId}/salary/{id}/status',   [EmployeeSalaryController::class, 'updateStatus'])->whereNumber('id');

    // Payroll → Payroll Processing (Phase 4). Monthly runs + frozen snapshots.
    Route::get('/payroll/runs',                 [PayrollRunController::class, 'index']);
    Route::post('/payroll/runs',                [PayrollRunController::class, 'store']);
    Route::get('/payroll/runs/{id}',            [PayrollRunController::class, 'show']);
    Route::post('/payroll/runs/{id}/process',   [PayrollRunController::class, 'process']);
    Route::get('/payroll/runs/{id}/records',    [PayrollRunController::class, 'records']);
    Route::get('/payroll/records/{id}/lines',   [PayrollRunController::class, 'recordLines'])->whereNumber('id');
    Route::patch('/payroll/runs/{id}/status',   [PayrollRunController::class, 'updateStatus']);

    /*
    | The stepped run: Pre-check → Inputs → Calculate → Approve → Disburse.
    |
    | These sit BESIDE /process rather than replacing it. `process` still does
    | the arithmetic and is still what a plain one-click month calls; these add
    | the question of who chose the employees and who agreed to the amounts.
    | A run that never touches them behaves exactly as it did before.
    */
    Route::get('/payroll/runs/{id}/precheck',      [PayrollWorkflowController::class, 'precheck'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/employees',    [PayrollWorkflowController::class, 'selectEmployees'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/confirm-inputs', [PayrollWorkflowController::class, 'confirmInputs'])->whereNumber('id');

    Route::get('/payroll/runs/{id}/adjustments',   [PayrollWorkflowController::class, 'adjustments'])->whereNumber('id');
    Route::post('/payroll/records/{id}/adjustments', [PayrollWorkflowController::class, 'addAdjustment'])->whereNumber('id');
    Route::delete('/payroll/adjustments/{id}',     [PayrollWorkflowController::class, 'removeAdjustment'])->whereNumber('id');

    Route::post('/payroll/runs/{id}/approve',      [PayrollWorkflowController::class, 'approve'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/reject',       [PayrollWorkflowController::class, 'reject'])->whereNumber('id');

    Route::post('/payroll/records/{id}/payment',   [PayrollWorkflowController::class, 'markPayment'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/payments',     [PayrollWorkflowController::class, 'markAllPayments'])->whereNumber('id');
    Route::post('/payroll/runs/{id}/release-payslips', [PayrollWorkflowController::class, 'releasePayslips'])->whereNumber('id');
    Route::post('/payroll/records/{id}/payslip-visibility', [PayrollWorkflowController::class, 'setPayslipVisibility'])->whereNumber('id');

    // Payroll → Payslips (Phase 5). Generated from a completed run; PDF via dompdf.
    Route::get('/payroll/payslips',                    [PayslipController::class, 'index']);
    Route::get('/payroll/payslips/{id}',               [PayslipController::class, 'show']);
    Route::get('/payroll/payslips/{id}/download',       [PayslipController::class, 'download']);
    Route::post('/payroll/runs/{id}/generate-payslips', [PayslipController::class, 'generate']);
    Route::get('/employees/{employeeId}/payslips',      [PayslipController::class, 'employeePayslips']);

    /*
    |--------------------------------------------------------------------------
    | HR Operations — Shift, Workplace, Loan & Advance
    |--------------------------------------------------------------------------
    | Static segments precede {id} throughout, or route-model binding swallows them.
    */

    // Shift Management. Assignment and history share one endpoint family because
    // they share one table — history is simply the superseded assignments.
    Route::get('/shifts/meta',                    [ShiftController::class, 'meta']);
    Route::get('/shifts/roster',                  [ShiftController::class, 'roster']);
    Route::get('/shifts/rotations',               [ShiftController::class, 'rotations']);
    Route::post('/shifts/rotations',              [ShiftController::class, 'saveRotation']);
    Route::put('/shifts/rotations/{id}',          [ShiftController::class, 'saveRotation'])->whereNumber('id');
    Route::delete('/shifts/rotations/{id}',       [ShiftController::class, 'destroyRotation'])->whereNumber('id');
    Route::post('/shifts/assign',                 [ShiftController::class, 'assign']);
    Route::get('/shifts/employees/{employeeId}/history',  [ShiftController::class, 'history'])->whereNumber('employeeId');
    Route::get('/shifts/employees/{employeeId}/for-date', [ShiftController::class, 'forDate'])->whereNumber('employeeId');
    Route::get('/shifts',                         [ShiftController::class, 'index']);
    Route::post('/shifts',                        [ShiftController::class, 'store']);
    Route::get('/shifts/{id}',                    [ShiftController::class, 'show'])->whereNumber('id');
    Route::put('/shifts/{id}',                    [ShiftController::class, 'update'])->whereNumber('id');
    Route::delete('/shifts/{id}',                 [ShiftController::class, 'destroy'])->whereNumber('id');

    // Workplace Management — Branch → Office → Floor, plus seating.
    Route::get('/workplace/meta',                 [WorkplaceController::class, 'meta']);
    Route::get('/workplace/tree',                 [WorkplaceController::class, 'tree']);
    Route::get('/workplace/branches',             [WorkplaceController::class, 'branches']);
    Route::post('/workplace/branches',            [WorkplaceController::class, 'saveBranch']);
    Route::put('/workplace/branches/{id}',        [WorkplaceController::class, 'saveBranch'])->whereNumber('id');
    Route::delete('/workplace/branches/{id}',     [WorkplaceController::class, 'destroyBranch'])->whereNumber('id');
    Route::get('/workplace/offices',              [WorkplaceController::class, 'offices']);
    Route::post('/workplace/offices',             [WorkplaceController::class, 'saveOffice']);
    Route::put('/workplace/offices/{id}',         [WorkplaceController::class, 'saveOffice'])->whereNumber('id');
    Route::delete('/workplace/offices/{id}',      [WorkplaceController::class, 'destroyOffice'])->whereNumber('id');
    Route::get('/workplace/floors',               [WorkplaceController::class, 'floors']);
    Route::post('/workplace/floors',              [WorkplaceController::class, 'saveFloor']);
    Route::put('/workplace/floors/{id}',          [WorkplaceController::class, 'saveFloor'])->whereNumber('id');
    Route::delete('/workplace/floors/{id}',       [WorkplaceController::class, 'destroyFloor'])->whereNumber('id');
    Route::get('/workplace/seating',              [WorkplaceController::class, 'seating']);
    Route::post('/workplace/assign',              [WorkplaceController::class, 'assign']);
    Route::get('/workplace/employees/{employeeId}/history', [WorkplaceController::class, 'history'])->whereNumber('employeeId');

    // Employee Loan & Salary Advance. An advance is a loan type, not a second module.
    Route::get('/loans/meta',                     [LoanController::class, 'meta']);
    Route::post('/loans/eligibility',             [LoanController::class, 'checkEligibility']);
    Route::get('/loans/stats',                    [LoanController::class, 'stats']);
    Route::post('/loans/preview',                 [LoanController::class, 'preview']);
    Route::get('/loans/types',                    [LoanController::class, 'types']);
    Route::post('/loans/types',                   [LoanController::class, 'saveType']);
    Route::put('/loans/types/{id}',               [LoanController::class, 'saveType'])->whereNumber('id');
    Route::delete('/loans/types/{id}',            [LoanController::class, 'destroyType'])->whereNumber('id');
    Route::get('/loans',                          [LoanController::class, 'index']);
    Route::post('/loans',                         [LoanController::class, 'save']);
    Route::get('/loans/{id}',                     [LoanController::class, 'show'])->whereNumber('id');
    Route::put('/loans/{id}',                     [LoanController::class, 'save'])->whereNumber('id');
    Route::post('/loans/{id}/submit',             [LoanController::class, 'submit'])->whereNumber('id');
    Route::post('/loans/{id}/approve',            [LoanController::class, 'approve'])->whereNumber('id');
    Route::post('/loans/{id}/reject',             [LoanController::class, 'reject'])->whereNumber('id');
    Route::post('/loans/{id}/disburse',           [LoanController::class, 'disburse'])->whereNumber('id');
    Route::post('/loans/{id}/close',              [LoanController::class, 'close'])->whereNumber('id');
    Route::post('/loans/{id}/cancel',             [LoanController::class, 'cancel'])->whereNumber('id');
    Route::post('/loans/{id}/installments/{installmentId}/waive', [LoanController::class, 'waiveInstallment'])->whereNumber('id')->whereNumber('installmentId');

    // Payroll → Statutory rule book. Every rate/ceiling/slab is configured here;
    // none is hardcoded. Static segments precede {id} so they are not swallowed.
    Route::get('/payroll/statutory/meta',      [StatutoryRuleController::class, 'meta']);
    Route::put('/payroll/statutory/defaults',  [StatutoryRuleController::class, 'saveDefaults']);
    Route::get('/payroll/statutory/rules',     [StatutoryRuleController::class, 'index']);
    Route::post('/payroll/statutory/rules',    [StatutoryRuleController::class, 'store']);
    Route::put('/payroll/statutory/rules/{id}',   [StatutoryRuleController::class, 'update'])->whereNumber('id');
    Route::delete('/payroll/statutory/rules/{id}', [StatutoryRuleController::class, 'destroy'])->whereNumber('id');

    // Payroll → Investment declarations + Form-16-ready data (Phase 2 tax).
    // Static segments precede {id}. Save/submit are open to HR users so an employee
    // can maintain their own claim; verify/reject/reopen require HR management.
    Route::get('/payroll/declarations/meta',   [InvestmentDeclarationController::class, 'meta']);
    Route::get('/payroll/declarations',        [InvestmentDeclarationController::class, 'index']);
    Route::get('/payroll/declarations/employee/{employeeId}', [InvestmentDeclarationController::class, 'forEmployee'])->whereNumber('employeeId');
    Route::get('/payroll/declarations/{id}',   [InvestmentDeclarationController::class, 'show'])->whereNumber('id');
    Route::put('/payroll/declarations/{id}',   [InvestmentDeclarationController::class, 'save'])->whereNumber('id');
    Route::post('/payroll/declarations/{id}/submit',  [InvestmentDeclarationController::class, 'submit'])->whereNumber('id');
    Route::post('/payroll/declarations/{id}/verify',  [InvestmentDeclarationController::class, 'verify'])->whereNumber('id');
    Route::post('/payroll/declarations/{id}/reject',  [InvestmentDeclarationController::class, 'reject'])->whereNumber('id');
    Route::post('/payroll/declarations/{id}/reopen',  [InvestmentDeclarationController::class, 'reopen'])->whereNumber('id');

    // Form-16-READY data. Not a Form 16 — that comes from TRACES.
    Route::get('/payroll/form16/{employeeId}/years', [InvestmentDeclarationController::class, 'form16Years'])->whereNumber('employeeId');
    Route::get('/payroll/form16/{employeeId}',       [InvestmentDeclarationController::class, 'form16'])->whereNumber('employeeId');

    // Payroll → Reports & Analytics (Phase 6). Read-only over existing frozen data.
    Route::get('/payroll/reports/filters',     [PayrollReportController::class, 'filterOptions']);
    Route::get('/payroll/reports/summary',     [PayrollReportController::class, 'summary']);
    Route::get('/payroll/reports/employees',   [PayrollReportController::class, 'employees']);
    Route::get('/payroll/reports/departments', [PayrollReportController::class, 'departments']);
    Route::get('/payroll/reports/components',  [PayrollReportController::class, 'components']);
    Route::get('/payroll/reports/trends',      [PayrollReportController::class, 'trends']);

    // Statutory registers — the documents a month is FILED with, as opposed to
    // the reports above, which are for reading. Keyed by payroll run because a
    // register must reflect what was actually paid, not a fresh calculation.
    Route::get('/payroll/runs/{run}/registers/pf',   [StatutoryRegisterController::class, 'pf'])->whereNumber('run');
    Route::get('/payroll/runs/{run}/registers/esic', [StatutoryRegisterController::class, 'esic'])->whereNumber('run');
    Route::get('/payroll/runs/{run}/registers/pt',   [StatutoryRegisterController::class, 'pt'])->whereNumber('run');
    Route::get('/payroll/runs/{run}/registers/lwf',  [StatutoryRegisterController::class, 'lwf'])->whereNumber('run');

    // The salary transfer advice. Separate from the registers because it moves
    // money rather than reporting on it.
    Route::get('/payroll/runs/{run}/bank-advice',     [StatutoryRegisterController::class, 'bankAdvice'])->whereNumber('run');
    Route::get('/payroll/runs/{run}/bank-advice.csv', [StatutoryRegisterController::class, 'bankAdviceCsv'])->whereNumber('run');
    Route::get('/payroll/reports/export',      [PayrollReportController::class, 'export']);

    // Enterprise Salary Reports (Phase 2) — read-only over structures/snapshots/revisions.
    Route::get('/payroll/salary-reports/meta',              [SalaryReportController::class, 'meta']);
    Route::get('/payroll/salary-reports/summary',           [SalaryReportController::class, 'summary']);
    Route::get('/payroll/salary-reports/{report}/export',   [SalaryReportController::class, 'export']);
    Route::get('/payroll/salary-reports/{report}',          [SalaryReportController::class, 'show']);

    // Attendance
    Route::get('/attendance/stats',          [AttendanceController::class, 'stats']);
    Route::get('/attendance/export',         [AttendanceController::class, 'export']);
    Route::get('/attendance',                [AttendanceController::class, 'index']);
    Route::post('/attendance',               [AttendanceController::class, 'storeManual']);
    // ── Self service ────────────────────────────────────────────────────
    // Clocking YOURSELF in, from the CRM dashboard or the HR module. No
    // employee_id is accepted: the employee is resolved from the token, so these
    // can only ever touch the caller's own record. Being a linked employee is the
    // authorisation — nobody needs a grant to be themselves.
    // ── My expense claims ───────────────────────────────────────────────
    // No employee_id anywhere: the claim is found by id AND owner, so a guessed
    // id returns 404 rather than somebody else's receipts.
    Route::get('/me/reimbursements',              [MyReimbursementController::class, 'index']);
    Route::post('/me/reimbursements',             [MyReimbursementController::class, 'store']);
    Route::get('/me/reimbursements/{id}',         [MyReimbursementController::class, 'show']);
    Route::post('/me/reimbursements/{id}/reply',  [MyReimbursementController::class, 'reply']);
    Route::post('/me/reimbursements/{id}/accept', [MyReimbursementController::class, 'accept']);
    // Declared AFTER /{id} but the path is longer, so there is no ambiguity:
    // Laravel matches on segment count first. The bytes of a receipt, reachable
    // only through a claim the caller owns.
    Route::get('/me/reimbursements/{id}/attachments/{attachmentId}', [MyReimbursementController::class, 'attachment']);

    // ── My advances ─────────────────────────────────────────────────────
    // Same guarantee as the claims above: no employee_id is accepted anywhere.
    // 'outstanding' is declared BEFORE /{id} so it is never captured as a record
    // id — the trap this file already notes for sync-sangoetrack.
    Route::get('/me/advances',                  [MyAdvanceController::class, 'index']);
    Route::get('/me/advances/outstanding',      [MyAdvanceController::class, 'outstanding']);
    Route::post('/me/advances',                 [MyAdvanceController::class, 'store']);
    Route::get('/me/advances/{id}',             [MyAdvanceController::class, 'show']);
    Route::post('/me/advances/{id}/reply',      [MyAdvanceController::class, 'reply']);
    Route::post('/me/advances/{id}/accept',     [MyAdvanceController::class, 'accept']);
    Route::post('/me/advances/{id}/cancel',     [MyAdvanceController::class, 'cancel']);
    Route::post('/me/advances/{id}/settlement', [MyAdvanceController::class, 'settle']);
    Route::get('/me/advances/{id}/attachments/{attachmentId}', [MyAdvanceController::class, 'attachment']);

    // ── My leave ────────────────────────────────────────────────────────
    // The existing leave routes are HR's: they take an employee_id and are gated
    // on managing the queue, so there was no way to apply for your OWN leave in
    // the CRM — that only existed in the app, against SangoeTrack.
    //
    // No employee_id is accepted anywhere here. 'balances' and 'preview' are
    // declared before /{id} so neither is captured as a record id.
    Route::get('/me/leave',                  [MyLeaveController::class, 'index']);
    Route::get('/me/leave/balances',         [MyLeaveController::class, 'balances']);
    Route::post('/me/leave/preview',         [MyLeaveController::class, 'preview']);
    Route::post('/me/leave',                 [MyLeaveController::class, 'store']);
    Route::get('/me/leave/{id}',             [MyLeaveController::class, 'show']);
    Route::patch('/me/leave/{id}/cancel',    [MyLeaveController::class, 'cancel']);
    Route::get('/me/leave/{id}/attachment',  [MyLeaveController::class, 'attachment']);

    // ── My attendance corrections ───────────────────────────────────────
    // Asking for a wrong or missing punch to be fixed. The CRM had no native
    // corrections at all — only a proxy to SangoeTrack's.
    // 'day' is declared before /{id} so it is never read as a record id.
    Route::get('/me/corrections',              [MyAttendanceCorrectionController::class, 'index']);
    Route::get('/me/corrections/day',          [MyAttendanceCorrectionController::class, 'day']);
    Route::post('/me/corrections',             [MyAttendanceCorrectionController::class, 'store']);
    Route::get('/me/corrections/{id}',         [MyAttendanceCorrectionController::class, 'show']);
    Route::post('/me/corrections/{id}/reply',  [MyAttendanceCorrectionController::class, 'reply']);
    Route::patch('/me/corrections/{id}/withdraw', [MyAttendanceCorrectionController::class, 'withdraw']);

    // The few settings an employee's own screens need. A short allowlist, not
    // the whole group — hiding fields in the client is not hiding them.
    Route::get('/me/settings', [HrSettingsController::class, 'forEmployee']);

    Route::get('/me/attendance/today',       [MyAttendanceController::class, 'today']);
    Route::post('/me/attendance/check-in',   [MyAttendanceController::class, 'checkIn']);
    Route::post('/me/attendance/check-out',  [MyAttendanceController::class, 'checkOut']);
    Route::post('/me/attendance/break-start',[MyAttendanceController::class, 'breakStart']);
    Route::post('/me/attendance/break-end',  [MyAttendanceController::class, 'breakEnd']);

    Route::post('/attendance/check-in',      [AttendanceController::class, 'checkIn']);
    Route::post('/attendance/check-out',     [AttendanceController::class, 'checkOut']);
    Route::post('/attendance/break-start',   [AttendanceController::class, 'breakStart']);
    Route::post('/attendance/break-end',     [AttendanceController::class, 'breakEnd']);
    // Declared before the {attendance} route so "sync-sangoetrack" is never
    // captured as a record id.
    Route::post('/attendance/sync-sangoetrack', [SangoeTrackSyncController::class, 'store']);
    Route::patch('/attendance/{attendance}', [AttendanceController::class, 'correct']);
});

// ── Expense claims, admin side ──────────────────────────────────────────────
// Gated on the GROUP rather than inside each method: a method that forgets the
// check is how a list-everything endpoint ends up open, which is exactly what
// happened in the first draft of ReimbursementController.
//
// `permission:hr_attendance,view_global` REPLACES the old `hr.manage` gate. That
// gate was a hardcoded list of role strings, so changing who may run HR meant
// editing PHP and deploying. This reads the permission grid an admin ticks in
// Staff Management, which is the whole point of the grid existing.
//
// Nobody loses access in the swap: `permissions:sync` grants hr_attendance to
// exactly the roles canManageHrQueue() admitted (hr_executive, hr_recruiter),
// and admins bypass the grid entirely. `permissions:audit` proves it per user
// by running both rules side by side.
Route::middleware(['auth:sanctum', 'permission:hr_attendance,view_global'])->prefix('hr')->group(function () {
    // ── Demo requests ───────────────────────────────────────────────────
    // Inbound enquiries. Unclaimed ones (tenant_id null) are visible to every
    // workspace until somebody starts working on one.
    Route::get('/demo-requests',        [DemoRequestController::class, 'index']);
    Route::post('/demo-requests',       [DemoRequestController::class, 'store']);
    Route::get('/demo-requests/{id}',   [DemoRequestController::class, 'show']);
    Route::put('/demo-requests/{id}',   [DemoRequestController::class, 'update']);

    // ── HR settings ─────────────────────────────────────────────────────
    // The controls that used to be constants — the advance thresholds above all.
    Route::get('/settings',  [HrSettingsController::class, 'index']);
    Route::put('/settings',  [HrSettingsController::class, 'update']);

    // ── POSH committees ─────────────────────────────────────────────────
    // Committee composition, its own role vocabulary and its members. Gated
    // on hr_settings inside the controller. CONFIGURATION ONLY — no case data
    // is reachable here, and case access will never come through this gate.
    Route::get('/posh-committees',                       [PoshCommitteeController::class, 'index']);
    Route::post('/posh-committees',                      [PoshCommitteeController::class, 'store']);
    Route::put('/posh-committees/{id}',                  [PoshCommitteeController::class, 'update'])->whereNumber('id');
    Route::patch('/posh-committees/{id}/status',         [PoshCommitteeController::class, 'setStatus'])->whereNumber('id');
    Route::delete('/posh-committees/{id}',               [PoshCommitteeController::class, 'destroy'])->whereNumber('id');
    Route::post('/posh-committees/{id}/roles',           [PoshCommitteeController::class, 'storeRole'])->whereNumber('id');
    Route::put('/posh-committees/{id}/roles/{roleId}',   [PoshCommitteeController::class, 'updateRole'])->whereNumber('id')->whereNumber('roleId');
    Route::delete('/posh-committees/{id}/roles/{roleId}',[PoshCommitteeController::class, 'destroyRole'])->whereNumber('id')->whereNumber('roleId');
    Route::put('/posh-committees/{id}/members',          [PoshCommitteeController::class, 'setMembers'])->whereNumber('id');

    // ── Exit clearance departments ──────────────────────────────────────
    // Who may sign off each department. Gated on hr_settings inside the
    // controller: actioning clearances must not confer the right to decide
    // who actions them.
    Route::get('/clearance-departments',                 [ClearanceDepartmentController::class, 'index']);
    Route::post('/clearance-departments',                [ClearanceDepartmentController::class, 'store']);
    Route::post('/clearance-departments/reorder',        [ClearanceDepartmentController::class, 'reorder']);
    Route::put('/clearance-departments/{id}',            [ClearanceDepartmentController::class, 'update'])->whereNumber('id');
    Route::patch('/clearance-departments/{id}/status',   [ClearanceDepartmentController::class, 'setStatus'])->whereNumber('id');
    Route::put('/clearance-departments/{id}/authorities',[ClearanceDepartmentController::class, 'authorities'])->whereNumber('id');
    Route::delete('/clearance-departments/{id}',         [ClearanceDepartmentController::class, 'destroy'])->whereNumber('id');

    // ── Onboarding checklist master ─────────────────────────────────────
    // The 27 tasks that used to be a PHP constant. Gated on hr_settings
    // inside the controller, like the approval workflows below: configuring
    // what everybody must do is not the same authority as doing it.
    Route::get('/onboarding-checklist',                  [OnboardingChecklistController::class, 'index']);
    Route::post('/onboarding-checklist',                 [OnboardingChecklistController::class, 'store']);
    Route::post('/onboarding-checklist/reorder',         [OnboardingChecklistController::class, 'reorder']);
    Route::post('/onboarding-checklist/adopt-defaults',  [OnboardingChecklistController::class, 'adoptDefaults']);
    Route::put('/onboarding-checklist/{id}',             [OnboardingChecklistController::class, 'update'])->whereNumber('id');
    Route::patch('/onboarding-checklist/{id}/status',    [OnboardingChecklistController::class, 'setStatus'])->whereNumber('id');
    Route::delete('/onboarding-checklist/{id}',          [OnboardingChecklistController::class, 'destroy'])->whereNumber('id');

    // ── Approval workflows ──────────────────────────────────────────────
    // Who approves what, in what order. Gated on hr_settings inside the
    // controller, not on the HR-queue predicate: an approver must not be able
    // to edit the ladder they stand on.
    Route::get('/approval-workflows',                     [ApprovalWorkflowController::class, 'index']);
    Route::get('/approval-workflows/{process}',           [ApprovalWorkflowController::class, 'show']);
    Route::put('/approval-workflows/{process}',           [ApprovalWorkflowController::class, 'save']);
    Route::patch('/approval-workflows/{process}/status',  [ApprovalWorkflowController::class, 'setStatus']);

    // ── Attendance corrections ──────────────────────────────────────────
    Route::get('/corrections',                 [AttendanceCorrectionController::class, 'index']);
    Route::get('/corrections/{id}',            [AttendanceCorrectionController::class, 'show']);
    Route::post('/corrections/{id}/approve',   [AttendanceCorrectionController::class, 'approve']);
    Route::post('/corrections/{id}/reject',    [AttendanceCorrectionController::class, 'reject']);
    Route::post('/corrections/{id}/hold',      [AttendanceCorrectionController::class, 'hold']);
    Route::post('/corrections/{id}/note',      [AttendanceCorrectionController::class, 'note']);

    // ── Attendance reports ──────────────────────────────────────────────
    // Read-only, so looking at them cannot affect a payroll run. 'departments'
    // is declared before the {employeeId} route so it is not read as an id.
    Route::get('/reports/attendance',             [AttendanceReportController::class, 'monthly']);
    Route::get('/reports/attendance/departments', [AttendanceReportController::class, 'byDepartment']);
    Route::get('/reports/attendance/{employeeId}', [AttendanceReportController::class, 'forEmployee']);

    Route::get('/reimbursements',                  [ReimbursementController::class, 'index']);
    Route::get('/reimbursements/{id}',             [ReimbursementController::class, 'show']);
    Route::post('/reimbursements/{id}/approve',    [ReimbursementController::class, 'approve']);
    Route::post('/reimbursements/{id}/decline',    [ReimbursementController::class, 'decline']);
    Route::post('/reimbursements/{id}/hold',       [ReimbursementController::class, 'hold']);
    Route::get('/reimbursements/{id}/attachments/{attachmentId}', [ReimbursementController::class, 'attachment']);


    Route::post('/reimbursements/{id}/note',       [ReimbursementController::class, 'note']);
});

// ── Advances ───────────────────────────────────────────────────────────────
// Its own gate, not hr.manage: the three tiers that approve an advance are a
// line manager, accounts and a director, and none of them are HR.
Route::middleware(['auth:sanctum', 'hr.advances'])->prefix('hr')->group(function () {
    // The gate gets you into the queue; it does not get you a rung. Which tier
    // may act on a given request is decided per request by AdvanceTierService,
    // and a manager sees only their own reports' requests.
    //
    // The settlement routes are declared BEFORE /advances/{id} so 'settlements'
    // is never matched as a record id.
    Route::get('/advances',                                [AdvanceController::class, 'index']);
    Route::get('/advances/settlements',                    [AdvanceController::class, 'settlements']);
    Route::post('/advances/settlements/{settlementId}/accept', [AdvanceController::class, 'acceptSettlement']);
    Route::post('/advances/settlements/{settlementId}/reject', [AdvanceController::class, 'rejectSettlement']);
    Route::get('/advances/{id}',                           [AdvanceController::class, 'show']);
    Route::post('/advances/{id}/approve',                  [AdvanceController::class, 'approve']);
    Route::post('/advances/{id}/decline',                  [AdvanceController::class, 'decline']);
    Route::post('/advances/{id}/hold',                     [AdvanceController::class, 'hold']);
    Route::post('/advances/{id}/disburse',                 [AdvanceController::class, 'disburse']);
    Route::post('/advances/{id}/note',                     [AdvanceController::class, 'note']);
    Route::get('/advances/{id}/attachments/{attachmentId}', [AdvanceController::class, 'attachment']);
});

// A punch selfie. Signed, not authenticated: the attendance register renders
// these in an <img>, which sends no Authorization header. See the controller.
Route::get('/hr/attendance/{attendance}/selfie/{which}',
    [\App\Http\Controllers\Api\Hr\AttendanceSelfieController::class, 'show'])
    ->whereIn('which', ['in', 'out'])
    ->name('hr.attendance.selfie')
    ->middleware('signed');

/*
|--------------------------------------------------------------------------
| POSH cases — members only
|--------------------------------------------------------------------------
|
| Their own group, with NO permission middleware, and that is deliberate.
|
| Authorisation is PoshAccessResolver and nothing else: not a permission, not
| a data scope, not the HR queue, not committee membership, not being an
| administrator. A harassment complaint may name any of those people, so none
| of them is a way in.
|
| A permission gate here would also answer the wrong question in the wrong
| way. It refuses with 403 before the resolver runs, and a 403 says "this
| exists and you may not see it" — which on this data is itself a disclosure.
| Every refusal has to be the same 404.
|
| There is deliberately NO index route. A list is an enumeration surface.
*/
Route::middleware('auth:sanctum')->prefix('hr')->group(function () {
    Route::get('/posh-cases/{id}',         [PoshCaseController::class, 'show'])->whereNumber('id');
    Route::get('/posh-cases/{id}/members', [PoshCaseController::class, 'members'])->whereNumber('id');

    // Intake. The ONE route here that is not gated on case membership,
    // because there is no case yet to be a member of. hr_posh_intake, which
    // is not admin, not hr_settings and not the HR queue — and which grants
    // no access to what it creates.
    Route::post('/posh-cases', [PoshCaseWorkController::class, 'store']);

    // Everything below resolves the case through PoshAccessResolver first.
    Route::patch('/posh-cases/{id}/acknowledge', [PoshCaseWorkController::class, 'acknowledge'])->whereNumber('id');
    Route::patch('/posh-cases/{id}/withdraw',    [PoshCaseWorkController::class, 'withdraw'])->whereNumber('id');
    Route::patch('/posh-cases/{id}/close',       [PoshCaseWorkController::class, 'close'])->whereNumber('id');

    Route::get('/posh-cases/{id}/thread',   [PoshCaseWorkController::class, 'thread'])->whereNumber('id');
    Route::post('/posh-cases/{id}/messages', [PoshCaseWorkController::class, 'message'])->whereNumber('id');
    Route::post('/posh-cases/{id}/notes',    [PoshCaseWorkController::class, 'note'])->whereNumber('id');

    Route::get('/posh-cases/{id}/attachments',      [PoshCaseWorkController::class, 'attachments'])->whereNumber('id');
    Route::post('/posh-cases/{id}/attachments',     [PoshCaseWorkController::class, 'upload'])->whereNumber('id');
    Route::get('/posh-cases/{id}/attachments/{attachmentId}', [PoshCaseWorkController::class, 'download'])
        ->whereNumber('id')->whereNumber('attachmentId');

    Route::get('/posh-cases/{id}/inquiry',         [PoshCaseWorkController::class, 'inquiry'])->whereNumber('id');
    Route::post('/posh-cases/{id}/inquiry',        [PoshCaseWorkController::class, 'openInquiry'])->whereNumber('id');
    Route::post('/posh-cases/{id}/inquiry/decide', [PoshCaseWorkController::class, 'decide'])->whereNumber('id');

    Route::get('/posh-cases/{id}/findings',          [PoshCaseWorkController::class, 'findings'])->whereNumber('id');
    Route::put('/posh-cases/{id}/findings',          [PoshCaseWorkController::class, 'saveFindings'])->whereNumber('id');
    Route::post('/posh-cases/{id}/findings/record',  [PoshCaseWorkController::class, 'recordFindings'])->whereNumber('id');
    Route::post('/posh-cases/{id}/findings/publish', [PoshCaseWorkController::class, 'publishFindings'])->whereNumber('id');

    // The complainant's link. Issuing is can_manage_case + active membership,
    // exactly like running the case — NOT hr_posh_intake, which would put the
    // credential in the hands of whoever takes complaints at the door, and not
    // hr_settings or admin, neither of which can reach the case at all.
    //
    // There is deliberately no GET: a token list would be an inventory of live
    // credentials, and the raw values are unrecoverable in any case.
    Route::post('/posh-cases/{id}/tokens', [PoshCaseWorkController::class, 'issueToken'])->whereNumber('id');
    Route::delete('/posh-cases/{id}/tokens/{tokenId}', [PoshCaseWorkController::class, 'revokeToken'])
        ->whereNumber('id')->whereNumber('tokenId');

    // hr_settings authority, and it grants NO case-content access.
    Route::post('/posh-cases/{id}/reconstitute', [PoshCaseWorkController::class, 'reconstitute'])->whereNumber('id');
});

/*
| POSH aggregate reporting.
|
| Counts only — period, status, outcome — and gated on its own capability.
| Sits apart from the case routes above because it answers a different
| question: how many complaints, in what state. hr_posh_reports grants no
| access to a single case and is never consulted by the access resolver.
|
| auth:sanctum ONLY, with the capability checked inside the controller. The
| main HR group requires hr_attendance, and sitting inside it would mean a
| compliance officer needed attendance permission to read a complaint count —
| two unrelated authorities welded together, and a 403 from the wrong gate.
*/
Route::middleware('auth:sanctum')->prefix('hr')->group(function () {
    Route::get('/posh-reports/summary', [PoshReportController::class, 'summary']);
});

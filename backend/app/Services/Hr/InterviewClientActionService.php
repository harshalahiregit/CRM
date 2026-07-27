<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrExternalCompany;
use App\Models\Hr\HrInterviewClientAction;
use App\Models\Hr\HrInterviewRound;
use App\Models\User;
use App\Services\Notifications\NotificationService;

/**
 * Company-side interview actions (Confirm / Reschedule / Cancel). These are
 * REQUESTS: they record an auditable action + timeline entry + notify the
 * recruiter, who executes the actual change via the existing InterviewService.
 * The hr_interview_rounds table is never modified here.
 */
class InterviewClientActionService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    private const NEEDS_OPEN = [HrInterviewClientAction::CONFIRMED, HrInterviewClientAction::RESCHEDULE, HrInterviewClientAction::CANCEL];

    public function record(HrInterviewRound $interview, HrExternalCompany $company, ?int $submissionId, string $action, array $data, User $user): HrInterviewClientAction
    {
        if (! in_array($action, [HrInterviewClientAction::CONFIRMED, HrInterviewClientAction::RESCHEDULE, HrInterviewClientAction::CANCEL, HrInterviewClientAction::ACCEPTED], true)) {
            throw new BusinessException('Invalid interview action.', 422);
        }

        // Cannot act on a completed or cancelled interview.
        if (in_array($action, self::NEEDS_OPEN, true) && in_array($interview->status, ['Completed', 'Cancelled'], true)) {
            throw new BusinessException('This interview is '.strtolower($interview->status).' and can no longer be changed.', 422);
        }
        if ($action === HrInterviewClientAction::RESCHEDULE && empty($data['preferred_date'])) {
            throw new BusinessException('A preferred date is required to request a reschedule.', 422);
        }

        $row = HrInterviewClientAction::create([
            'tenant_id'      => $interview->tenant_id,
            'interview_id'   => $interview->id,
            'candidate_id'   => $interview->candidate_id,
            'company_id'     => $company->id,
            'submission_id'  => $submissionId,
            'action'         => $action,
            'preferred_date' => $data['preferred_date'] ?? null,
            'preferred_time' => $data['preferred_time'] ?? null,
            'reason'         => $data['reason'] ?? null,
            'created_by'     => $user->id,
        ]);

        $note = $action === HrInterviewClientAction::RESCHEDULE
            ? trim(($data['preferred_date'] ?? '').' '.($data['preferred_time'] ?? '').' — '.($data['reason'] ?? ''))
            : ($data['reason'] ?? null);
        $interview->recordAudit('Client interview '.$action, $user, $note, ['client' => true]);

        // Notify the recruiter who owns the candidate (falls back to the interviewer).
        $interview->loadMissing(['candidate.assignedRecruiter:id,email', 'interviewer:id,email']);
        $to = optional($interview->candidate?->assignedRecruiter)->email ?? optional($interview->interviewer)->email;
        $this->notifications->email(
            $to,
            'Interview '.$action.' request from client',
            "The client submitted a '{$action}' action for the interview \"{$interview->round_name}\" (candidate: {$interview->candidate?->name}).".($note ? "\n\nDetails: {$note}" : ''),
            ['interview_id' => $interview->id, 'event' => 'interview_client_'.strtolower($action)],
        );

        return $row;
    }
}

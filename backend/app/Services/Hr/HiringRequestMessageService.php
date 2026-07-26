<?php

namespace App\Services\Hr;

use App\Models\Hr\HrHiringRequest;
use App\Models\Hr\HrHiringRequestMessage;
use App\Models\User;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Collection;

/**
 * Two-way company ↔ recruiter conversation for a hiring request. New persistence
 * (the codebase had no messaging), but it reuses the audit trail for the request
 * timeline and NotificationService to alert the other side.
 */
class HiringRequestMessageService
{
    public function __construct(private NotificationService $notifications)
    {
    }

    /** Thread for a request, or a candidate sub-thread when $submissionId is set. */
    public function list(HrHiringRequest $request, ?int $submissionId = null): Collection
    {
        return $request->messages()
            ->when($submissionId, fn ($q) => $q->where('submission_id', $submissionId), fn ($q) => $q->whereNull('submission_id'))
            ->with('sender:id,name')->orderBy('id')->get();
    }

    /** Post a message. $kind is 'company' or 'internal'; $submissionId scopes it to a candidate. */
    public function post(HrHiringRequest $request, string $kind, User $sender, string $body, ?int $submissionId = null): HrHiringRequestMessage
    {
        $message = HrHiringRequestMessage::create([
            'tenant_id'         => $request->tenant_id,
            'hiring_request_id' => $request->id,
            'submission_id'     => $submissionId,
            'sender_kind'       => $kind,
            'sender_user_id'    => $sender->id,
            'body'              => $body,
        ]);
        $label = $submissionId ? 'Candidate comment from '.$kind : 'Message from '.$kind;
        $request->recordAudit($label, $sender, \Illuminate\Support\Str::limit($body, 80), ['client_visible' => true]);

        // Notify the counterparty: company message → recruiter; internal → company.
        if ($kind === 'company') {
            $request->loadMissing('assignedRecruiter:id,email');
            $this->notifications->email(
                $request->assignedRecruiter?->email,
                'New message on hiring request #'.$request->id,
                "A new message was posted on \"{$request->job_title}\":\n\n{$body}",
                ['hiring_request_id' => $request->id, 'event' => 'company_message'],
            );
        } else {
            $request->loadMissing('externalCompany:id,contact_email');
            $this->notifications->email(
                $request->externalCompany?->contact_email,
                'New message on your hiring request',
                "Your recruitment team posted a message on \"{$request->job_title}\":\n\n{$body}",
                ['hiring_request_id' => $request->id, 'event' => 'recruiter_message'],
            );
        }

        return $message->load('sender:id,name');
    }
}

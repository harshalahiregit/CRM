<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\Compliance\ComplianceChecklist;
use App\Models\Compliance\ComplianceSignature;
use App\Models\Compliance\ComplianceTemplate;
use App\Models\User;
use App\Support\Compliance\ChecklistSubject;
use App\Support\Compliance\ComplianceStatus as Status;
use App\Support\Compliance\RiskBand;
use App\Support\Compliance\SignatureTier as Tier;
use App\Support\Compliance\TemplateStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComplianceChecklistService
{
    private const DISK = 'compliance_media';

    public function __construct(private ChecklistEvaluator $evaluator)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        // checklistable is eager-loaded because the `subject` accessor is appended
        // on every row — without this the ledger would fire an N+1.
        $query = ComplianceChecklist::forTenant($tenantId)
            ->with(['template:id,code,name,category', 'assignee:id,name', 'issuer:id,name', 'checklistable']);

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['risk_band']) && $filters['risk_band'] !== 'All') {
            $query->where('risk_band', $filters['risk_band']);
        }
        if (! empty($filters['template_id'])) {
            $query->where('compliance_template_id', $filters['template_id']);
        }
        // Subject filter speaks the stable key, never a class name.
        if (! empty($filters['subject_type']) && ! empty($filters['subject_id'])) {
            if (! ChecklistSubject::isValid($filters['subject_type'])) {
                throw new BusinessException('Unknown subject type.');
            }
            $query->for(ChecklistSubject::classFor($filters['subject_type']), (int) $filters['subject_id']);
        }
        if (! empty($filters['awaiting'])) {
            $query->awaitingSignature();
        }

        return $query->latest()->get();
    }

    /**
     * Issue a checklist against a subject.
     *
     * Goes straight to Assigned: a checklist with nobody to fill it in is not a
     * useful record, and the Draft state exists for templates, not instances.
     */
    public function issue(array $data, User $actor): ComplianceChecklist
    {
        $template = ComplianceTemplate::forTenant($actor->tenant_id)->find($data['template_id']);

        if (! $template) {
            throw new BusinessException('Template not found.', 404);
        }
        if (! TemplateStatus::isIssuable($template->status)) {
            throw new BusinessException(
                'Only an active template can be issued. This one is '.TemplateStatus::label($template->status).'.'
            );
        }

        $subject = $this->resolveSubject($data['subject_type'] ?? null, $data['subject_id'] ?? null, $actor->tenant_id);

        $checklist = ComplianceChecklist::create([
            'tenant_id'              => $actor->tenant_id,
            'compliance_template_id' => $template->id,
            'checklistable_type'     => $subject ? $subject::class : null,
            'checklistable_id'       => $subject?->id,
            'title'                  => $data['title'] ?? $this->defaultTitle($template, $subject),
            'reference'              => $data['reference'] ?? null,
            'status'                 => Status::ASSIGNED,
            'public_token'           => $this->freshToken(),
            'issued_by'              => $actor->id,
            'assigned_to'            => $data['assigned_to'] ?? null,
            'assignee_name'          => $data['assignee_name'] ?? null,
            'assignee_email'         => $data['assignee_email'] ?? null,
            'due_date'               => $data['due_date'] ?? null,
        ]);

        // The issuer signs at issue time, so the chain records who put this into
        // the field — not just who blessed the result.
        $this->sign($checklist, Tier::ISSUER, Tier::APPROVE, $actor, $data['remarks'] ?? null);

        $checklist->recordAudit('issued', $actor, "Issued '{$checklist->title}'", [
            'template' => $template->code,
            'subject'  => $subject ? ChecklistSubject::nameOf($subject) : null,
        ]);
        Log::channel('compliance')->info('Checklist issued', [
            'checklist_id' => $checklist->id, 'template_id' => $template->id,
            'tenant_id' => $actor->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $this->hydrate($checklist);
    }

    /** The form as the filler sees it: questions + whatever is answered so far. */
    public function form(ComplianceChecklist $checklist): array
    {
        $checklist->loadMissing(['template', 'checklistable']);

        return [
            'id'         => $checklist->id,
            'title'      => $checklist->title,
            'status'     => $checklist->status,
            'due_date'   => $checklist->due_date,
            'fillable'   => Status::isFillable($checklist->status),
            'template'   => [
                'code'       => $checklist->template?->code,
                'name'       => $checklist->template?->name,
                'definition' => $checklist->template?->definition,
            ],
            'responses'  => $checklist->responses ?? [],
            'subject'    => $checklist->subject,
        ];
    }

    /** Save a partially-filled form — a site walk takes longer than one request. */
    public function saveResponses(ComplianceChecklist $checklist, array $responses): ComplianceChecklist
    {
        $this->guardFillable($checklist);
        $checklist->loadMissing('template');

        $errors = $this->evaluator->validateResponses($checklist->template?->definition, $responses, partial: true);
        if ($errors !== []) {
            throw new BusinessException('Some answers are not valid: '.implode(' ', $errors));
        }

        $checklist->update(['responses' => $responses]);

        return $checklist->fresh();
    }

    /**
     * Submit — the point at which answers are validated in full, scored, banded
     * and frozen, and the who/where/what of the submission is captured.
     */
    public function submit(ComplianceChecklist $checklist, array $data, array $meta, ?User $actor = null): ComplianceChecklist
    {
        $this->guardFillable($checklist);
        $checklist->loadMissing('template');

        $responses = $data['responses'] ?? $checklist->responses ?? [];

        $errors = $this->evaluator->validateResponses($checklist->template?->definition, $responses, partial: false);
        if ($errors !== []) {
            throw new BusinessException('This checklist is not complete: '.implode(' ', $errors));
        }

        // Scored server-side, always. The client never sends a score.
        $result = $this->evaluator->evaluate(
            $checklist->template?->definition,
            $responses,
            $checklist->template?->thresholds,
        );

        $checklist->update([
            'responses'            => $responses,
            'status'               => Status::SUBMITTED,
            'score'                => $result['score'],
            'max_score'            => $result['max_score'],
            'risk_percent'         => $result['risk_percent'],
            'risk_band'            => $result['risk_band'],
            'critical_failures'    => $result['critical_failures'],
            'submitted_at'         => now(),
            'selfie_path'          => isset($data['selfie']) ? $this->storeMedia($checklist, $data['selfie'], 'selfie') : $checklist->selfie_path,
            'latitude'             => $data['latitude'] ?? null,
            'longitude'            => $data['longitude'] ?? null,
            'submitted_ip'         => $meta['ip'] ?? null,
            'submitted_user_agent' => Str::limit($meta['user_agent'] ?? '', 250, ''),
        ]);

        $checklist->recordAudit('submitted', $actor, $this->submissionSummary($result), [
            'score' => $result['score'], 'max_score' => $result['max_score'],
            'risk_band' => $result['risk_band'], 'critical_failures' => $result['critical_failures'],
            // A public submission has no actor — record who claimed to be filling it.
            'submitted_by' => $actor?->name ?? $checklist->assignee_name ?? 'Public link',
        ]);

        Log::channel('compliance')->info('Checklist submitted', [
            'checklist_id' => $checklist->id, 'risk_band' => $result['risk_band'],
            'score' => $result['score'], 'critical_failures' => $result['critical_failures'],
            'actor_id' => $actor?->id, 'ip' => $meta['ip'] ?? null,
        ]);

        return $this->hydrate($checklist->fresh());
    }

    /**
     * A tier of the signature chain acts.
     *
     * The tier drives the transition, not the caller: a manager cannot post
     * "approved" and skip the head, because ACTS_ON pins which status each tier
     * may act on and canTransition() refuses anything else.
     */
    public function act(ComplianceChecklist $checklist, string $tier, string $action, User $actor, array $data): ComplianceChecklist
    {
        if (! in_array($tier, [Tier::MANAGER, Tier::HEAD], true)) {
            throw new BusinessException('Only the manager and head tiers sign off a submitted checklist.');
        }

        $required = Tier::ACTS_ON[$tier];
        if ($checklist->status !== $required) {
            throw new BusinessException(
                Tier::label($tier).' sign-off expects a '.Status::label($required).
                ' checklist; this one is '.Status::label($checklist->status).'.'
            );
        }

        $target = $action === Tier::REJECT ? Status::REJECTED : Tier::APPROVES_TO[$tier];

        if (! Status::canTransition($checklist->status, $target)) {
            throw new BusinessException(
                'Cannot move a '.Status::label($checklist->status).' checklist to '.Status::label($target).'.'
            );
        }

        // A rejection without a reason is not actionable by the person who has
        // to fix it.
        if ($action === Tier::REJECT && trim((string) ($data['remarks'] ?? '')) === '') {
            throw new BusinessException('A rejection needs a remark explaining what must change.');
        }

        $overridden = $this->guardSegregationOfDuties($checklist, $tier, $action, $actor, $data);

        $this->sign($checklist, $tier, $action, $actor, $data['remarks'] ?? null, $data['signature'] ?? null, $overridden);

        $checklist->update([
            'status'    => $target,
            'closed_at' => in_array($target, Status::CLOSED, true) ? now() : null,
            // Once closed the fill link is dead — don't leave a live bearer token
            // lying in an inbox after the record is final.
            'public_token' => in_array($target, Status::CLOSED, true) ? null : $checklist->public_token,
        ]);

        $verb = $action === Tier::REJECT ? 'rejected' : 'approved';
        // Say it in the timeline, not just in a column — a reader scanning the
        // history should see that no second pair of eyes reviewed this.
        $note = Tier::label($tier).' '.$verb
            .($overridden ? ' (self-approved — segregation of duties overridden)' : '')
            .(! empty($data['remarks']) ? ": {$data['remarks']}" : '');

        $checklist->recordAudit($verb, $actor, $note, ['segregation_overridden' => $overridden]);
        Log::channel('compliance')->info('Checklist '.$verb, [
            'checklist_id' => $checklist->id, 'tier' => $tier, 'actor_id' => $actor->id, 'status' => $target,
            'segregation_overridden' => $overridden,
        ]);

        return $this->hydrate($checklist->fresh());
    }

    /**
     * Send a rejected checklist back for rework.
     *
     * Keeps the responses and the rejection signature — the assignee needs to
     * see what was wrong — and mints a NEW fill token so the superseded link in
     * the old email stops working.
     */
    public function reopen(ComplianceChecklist $checklist, User $actor): ComplianceChecklist
    {
        if (! Status::canTransition($checklist->status, Status::ASSIGNED)) {
            throw new BusinessException('Only a rejected checklist can be reopened.');
        }

        $checklist->update([
            'status'       => Status::ASSIGNED,
            'public_token' => $this->freshToken(),
            'submitted_at' => null,
            'closed_at'    => null,
        ]);

        $checklist->recordAudit('reopened', $actor, 'Reopened for rework');

        return $this->hydrate($checklist->fresh());
    }

    /** Public fill-in: resolve a bearer token to its checklist. */
    public function resolveByToken(string $token): ComplianceChecklist
    {
        $checklist = ComplianceChecklist::where('public_token', $token)->with('template')->first();

        if (! $checklist) {
            throw new BusinessException('This checklist link is not valid. It may have been completed already.', 404);
        }

        return $checklist;
    }

    public function stats(int $tenantId): array
    {
        $base = fn () => ComplianceChecklist::forTenant($tenantId);

        return [
            'total'      => $base()->count(),
            'open'       => $base()->open()->count(),
            'awaiting'   => $base()->awaitingSignature()->count(),
            'approved'   => $base()->where('status', Status::APPROVED)->count(),
            'rejected'   => $base()->where('status', Status::REJECTED)->count(),
            'high_risk'  => $base()->highRisk()->whereNotIn('status', [Status::REJECTED])->count(),
            'overdue'    => $base()->open()->whereNull('submitted_at')
                                 ->whereNotNull('due_date')->whereDate('due_date', '<', now())->count(),
            'by_band'    => collect(RiskBand::ALL)->map(fn ($b) => [
                'band'  => $b,
                'label' => RiskBand::label($b),
                'count' => $base()->where('risk_band', $b)->count(),
            ])->all(),
        ];
    }

    /* ── internals ─────────────────────────────────────────────── */

    private function guardFillable(ComplianceChecklist $checklist): void
    {
        if (! Status::isFillable($checklist->status)) {
            throw new BusinessException(
                'A '.Status::label($checklist->status).' checklist cannot be edited.'
                .($checklist->status === Status::REJECTED ? ' Reopen it to make changes.' : '')
            );
        }
    }

    /**
     * Segregation of duties: the manager signing off must not be the person who
     * issued the checklist. A manager signature that is just the issuer's own
     * second click is not independent review, and the whole point of the chain
     * is that someone else looked.
     *
     * An admin may knowingly override — small teams genuinely do have one person
     * wearing both hats — but never silently: the override is admin-only, must
     * carry a reason, and is stamped onto the signature row so it can be found
     * later with a WHERE clause rather than archaeology through audit JSON.
     *
     * Only the manager tier is bound. The head tier is already admin-gated by
     * the route, so a staff issuer can never close their own record; an admin
     * issuer reaching head sign-off is the same person the override exists for.
     *
     * APPROVALS only. Rejecting your own issue carries no conflict of interest —
     * you are being stricter with your own work, not waving it through — and
     * blocking it would only force an issuer who spots a bad submission to wait
     * for someone else to send it back.
     *
     * @return bool whether this signature is an override
     */
    private function guardSegregationOfDuties(
        ComplianceChecklist $checklist,
        string $tier,
        string $action,
        User $actor,
        array $data,
    ): bool {
        $isSelf = $tier === Tier::MANAGER
            && $action === Tier::APPROVE
            && $checklist->issued_by !== null
            && (int) $checklist->issued_by === (int) $actor->id;

        if (! $isSelf) {
            return false;
        }

        if (empty($data['override_segregation'])) {
            throw new BusinessException(
                'You issued this checklist, so you cannot also sign it off as manager. '
                .'Ask another manager to review it, or have an admin override with a reason.'
            );
        }

        if (! $actor->isAdmin()) {
            throw new BusinessException('Only an admin can sign off a checklist they issued themselves.', 403);
        }

        if (trim((string) ($data['remarks'] ?? '')) === '') {
            throw new BusinessException(
                'Signing off your own checklist needs a remark recording why no second reviewer was available.'
            );
        }

        return true;
    }

    private function sign(
        ComplianceChecklist $checklist,
        string $tier,
        string $action,
        ?User $actor,
        ?string $remarks,
        ?UploadedFile $signature = null,
        bool $segregationOverridden = false,
    ): ComplianceSignature {
        return ComplianceSignature::create([
            'tenant_id'               => $checklist->tenant_id,
            'compliance_checklist_id' => $checklist->id,
            'user_id'                 => $actor?->id,
            'tier'                    => $tier,
            'action'                  => $action,
            'remarks'                 => $remarks,
            'signature_path'          => $signature ? $this->storeMedia($checklist, $signature, "sig-{$tier}") : null,
            'signed_at'               => now(),
            'segregation_overridden'  => $segregationOverridden,
        ]);
    }

    private function storeMedia(ComplianceChecklist $checklist, UploadedFile $file, string $kind): string
    {
        $name = $kind.'-'.Str::random(12).'.'.$file->getClientOriginalExtension();

        return $file->storeAs("tenant-{$checklist->tenant_id}/checklist-{$checklist->id}", $name, self::DISK);
    }

    /** 48 chars, matching the gate-scan badge token's shape and entropy. */
    private function freshToken(): string
    {
        return Str::random(48);
    }

    private function resolveSubject(?string $type, $id, int $tenantId): ?object
    {
        if (! $type || ! $id) {
            return null;   // standalone checklist — a site walk owned by nobody
        }
        if (! ChecklistSubject::isValid($type)) {
            throw new BusinessException('Unknown subject type.');
        }

        $class = ChecklistSubject::classFor($type);
        // forTenant, not find() — a checklist must never attach across tenants.
        $model = $class::forTenant($tenantId)->find($id);

        if (! $model) {
            throw new BusinessException(ChecklistSubject::label($type).' not found.', 404);
        }

        return $model;
    }

    /**
     * The subject is structured data on the record (the `subject` accessor), and
     * every surface renders it as its own field — so the title does NOT repeat
     * it. Appending "— Apex Structural" here put the vendor name twice on the
     * fill page and existed only to paper over a listing that showed a raw id.
     */
    private function defaultTitle(ComplianceTemplate $template, ?object $subject): string
    {
        return $template->name;
    }

    private function submissionSummary(array $result): string
    {
        $band = $result['risk_band'] ? RiskBand::label($result['risk_band']) : 'unscored';
        $line = "Submitted — {$result['score']}/{$result['max_score']} risk ({$result['risk_percent']}%), {$band} band";

        return $result['critical_failures']
            ? $line.'. Critical: '.implode(', ', $result['critical_failures'])
            : $line.'.';
    }

    /** Consistent shape for every write that returns a checklist. */
    private function hydrate(ComplianceChecklist $checklist): ComplianceChecklist
    {
        return $checklist->load(['template:id,code,name,category', 'signatures.user:id,name', 'issuer:id,name', 'assignee:id,name']);
    }
}

<?php

namespace App\Services\Hr\Posh;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrDecisionRound;
use App\Models\Hr\HrPoshCase;
use App\Models\Hr\HrPoshFinding;
use App\Models\User;

/**
 * What the committee concluded, and the separate act of telling anybody.
 *
 * FOUR STATES, THREE OF THEM DELIBERATE. A finding is drafted while it is
 * being written, recorded when the committee is content with the wording, and
 * published when somebody decides it should be seen. Recording and publishing
 * are different facts and are stored as different columns, because a single
 * status would force one to overwrite the other and lose when each happened.
 *
 * PUBLICATION IS AN ACT, NOT A VOTE. The committee has already decided; a
 * second round on whether to tell anyone is a rule nobody asked for. It
 * requires a case member with can_manage_case, and membership is checked
 * first — authority over a case somebody cannot see is not coherent.
 *
 * PUBLISHED MEANS FINISHED. Summary, recommendation and outcome are immutable
 * once published, with no amendment path. A correction would be a new finding
 * superseding this one, and what that means — whether the original stays
 * visible, who may issue it — is a product decision. Guessing would put the
 * guess into the record.
 */
class PoshFindingService
{
    public function __construct(
        private PoshAccessResolver $access,
        private PoshCaseAuthority $authority,
    ) {
    }

    /**
     * Start a draft when the inquiry concludes.
     *
     * Created empty but carrying the round's verdict, so the outcome is on the
     * record even if nobody ever writes the prose. Idempotent: a second
     * decision arriving on an already-concluded case does not open a second
     * draft.
     */
    public function openDraft(HrPoshCase $case, HrDecisionRound $round, ?User $actor = null): HrPoshFinding
    {
        $existing = HrPoshFinding::where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->latest('id')->first();

        if ($existing) {
            return $existing;
        }

        $finding = HrPoshFinding::create([
            'tenant_id'  => $case->tenant_id,
            'case_id'    => $case->id,
            'round_id'   => $round->id,
            'outcome'    => $round->outcome,
            'status'     => HrPoshFinding::STATUS_DRAFT,
            'created_by' => $actor?->id,
            'updated_by' => $actor?->id,
        ]);

        $finding->recordAudit('POSH Finding Drafted', $actor, null, ['round_id' => $round->id]);

        return $finding;
    }

    /** The finding, for a member of the case. */
    public function show(HrPoshCase $case): ?array
    {
        $finding = $this->current($case);

        return $finding ? $this->present($finding) : null;
    }

    /**
     * Write or rewrite the draft.
     *
     * Open until recorded, closed forever after publication.
     */
    public function save(HrPoshCase $case, User $actor, array $data): array
    {
        $this->authority->assertCanManageCase($actor, $case);

        $finding = $this->current($case);

        if (! $finding) {
            throw new BusinessException(
                'There is nothing to write up yet: the inquiry has not concluded.', 422
            );
        }

        $this->assertEditable($finding);

        $finding->update([
            'summary'        => array_key_exists('summary', $data) ? $data['summary'] : $finding->summary,
            'recommendation' => array_key_exists('recommendation', $data) ? $data['recommendation'] : $finding->recommendation,
            'updated_by'     => $actor->id,
        ]);

        $finding->recordAudit('POSH Finding Updated', $actor);

        return $this->present($finding->fresh());
    }

    /** Freeze the wording. Still not visible to anybody outside the case. */
    public function record(HrPoshCase $case, User $actor): array
    {
        $this->authority->assertCanManageCase($actor, $case);

        $finding = $this->current($case);

        if (! $finding) {
            throw new BusinessException('There is no finding to record.', 422);
        }

        $this->assertEditable($finding);

        if (trim((string) $finding->summary) === '') {
            throw new BusinessException('A finding needs a summary before it can be recorded.', 422);
        }

        $finding->update([
            'status'      => HrPoshFinding::STATUS_RECORDED,
            'recorded_at' => now(),
            'recorded_by' => $actor->id,
            'updated_by'  => $actor->id,
        ]);

        $finding->recordAudit('POSH Finding Recorded', $actor);

        return $this->present($finding->fresh());
    }

    /**
     * Publish.
     *
     * The act that will eventually make the finding visible on the restricted
     * complainant surface. That surface does not exist yet, so publishing
     * today only stamps the record — which is why it is safe to build now and
     * why the stamp is what matters.
     */
    public function publish(HrPoshCase $case, User $actor): array
    {
        $this->authority->assertCanManageCase($actor, $case);

        $finding = $this->current($case);

        if (! $finding) {
            throw new BusinessException('There is no finding to publish.', 422);
        }

        if ($finding->isPublished()) {
            throw new BusinessException('This finding has already been published.', 422);
        }

        if (! $finding->isRecorded()) {
            // Publishing a draft would make wording public that the committee
            // has not settled.
            throw new BusinessException('Record the finding before publishing it.', 422);
        }

        $finding->update([
            'published_at' => now(),
            'published_by' => $actor->id,
            'updated_by'   => $actor->id,
        ]);

        $case->update([
            'findings_published_at' => now(),
            'updated_by'            => $actor->id,
        ]);

        $finding->recordAudit('POSH Finding Published', $actor);
        $case->recordAudit('POSH Findings Published', $actor);

        return $this->present($finding->fresh());
    }

    /* ── internals ────────────────────────────────────────────────────── */

    private function current(HrPoshCase $case): ?HrPoshFinding
    {
        return HrPoshFinding::where('case_id', $case->id)
            ->where('tenant_id', $case->tenant_id)
            ->latest('id')
            ->first();
    }

    private function assertEditable(HrPoshFinding $finding): void
    {
        if ($finding->isPublished()) {
            throw new BusinessException(
                'A published finding cannot be changed.', 422
            );
        }

        if ($finding->isRecorded()) {
            throw new BusinessException(
                'This finding has been recorded and can no longer be edited.', 422
            );
        }
    }

    private function present(HrPoshFinding $f): array
    {
        return [
            'id'             => $f->id,
            'status'         => $f->status,
            'outcome'        => $f->outcome,
            'summary'        => $f->summary,
            'recommendation' => $f->recommendation,
            'round_id'       => $f->round_id,
            'recorded_at'    => optional($f->recorded_at)->toIso8601String(),
            'published_at'   => optional($f->published_at)->toIso8601String(),
            'is_editable'    => $f->isDraft(),
        ];
    }
}

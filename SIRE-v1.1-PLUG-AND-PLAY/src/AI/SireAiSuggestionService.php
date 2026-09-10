<?php

namespace Sire\AI;

use Sire\Exceptions\SireException;
use Sire\Models\AiSuggestion;
use Sire\Dto\SireUserIdentity;
use Sire\Support\Ai\AiCapability;
use Illuminate\Support\Collection;

/**
 * SIRE AI — reading suggestions and recording what a human did about them.
 *
 * THE DESIGN DECISION WORTH ARGUING ABOUT
 *
 * Accepting a suggestion does NOT apply it. This service records the decision and
 * returns the suggested value; the human then performs the ordinary action —
 * setting the severity, marking the duplicate, saving the root cause — through
 * the ordinary endpoint, with themselves as the actor.
 *
 * The cost is one extra call. The benefit is that the AI layer has NO WRITE PATH
 * to core data whatsoever, so "AI must never overwrite original issue data" is a
 * property of the architecture rather than a rule someone has to keep. Every
 * change to an issue was made by a person, and the audit trail says so without
 * qualification.
 *
 * It also captures something an auto-apply would lose: a human who accepts a
 * suggestion but changes it before applying is recorded as MODIFIED, which is the
 * most useful feedback signal there is.
 */
class SireAiSuggestionService
{
    public function __construct(private readonly SireAiGateway $gateway)
    {
    }

    /** Suggestions for one subject. Advisory decorations, never a source of truth. */
    public function for(int $tenantId, string $subjectType, int $subjectId, bool $pendingOnly = true): Collection
    {
        return AiSuggestion::query()
            ->forTenant($tenantId)
            ->where('subject_type', $subjectType)
            ->where('subject_id', $subjectId)
            ->when($pendingOnly, fn ($q) => $q->where('status', AiSuggestion::PENDING))
            ->with('decider:id,name')
            ->latest('created_at')
            ->get();
    }

    /**
     * The human agreed. Records the decision and hands back the payload for the
     * caller to apply through the ordinary endpoint.
     */
    public function accept(AiSuggestion $suggestion, SireUserIdentity $actor, ?string $note = null): AiSuggestion
    {
        return $this->decide($suggestion, AiSuggestion::ACCEPTED, $actor, $note);
    }

    public function reject(AiSuggestion $suggestion, SireUserIdentity $actor, ?string $note = null): AiSuggestion
    {
        return $this->decide($suggestion, AiSuggestion::REJECTED, $actor, $note);
    }

    /**
     * The human took the idea but changed it. The most useful feedback there is:
     * "nearly right" is a different signal from "right" and from "wrong", and
     * collapsing it into accept or reject throws that away.
     */
    public function modify(AiSuggestion $suggestion, SireUserIdentity $actor, array $finalValue, ?string $note = null): AiSuggestion
    {
        $suggestion->final_value = $finalValue;

        return $this->decide($suggestion, AiSuggestion::MODIFIED, $actor, $note);
    }

    /**
     * "Not sure." The reader looked and could not tell.
     *
     * Recorded separately from a rejection because it means something different,
     * and because a suggestion nobody could judge is a signal about the
     * suggestion, not about the reader.
     */
    public function defer(AiSuggestion $suggestion, SireUserIdentity $actor, ?string $note = null): AiSuggestion
    {
        return $this->decide($suggestion, AiSuggestion::DEFERRED, $actor, $note);
    }

    private function decide(AiSuggestion $suggestion, string $decision, SireUserIdentity $actor, ?string $note): AiSuggestion
    {
        if ($suggestion->status !== AiSuggestion::PENDING) {
            throw new SireException('That suggestion has already been decided.');
        }
        if ((int) $suggestion->tenant_id !== (int) $actor->tenantId) {
            // Belt and braces; the controller asserts ownership first.
            throw new SireException('That suggestion belongs to a different workspace.');
        }

        $suggestion->fill([
            'status'        => $decision,
            'decided_by'    => $actor->id,
            'decided_at'    => now(),
            'decision_note' => $note,
        ])->save();

        /*
         * Audited as an AI event, never as a workflow event.
         *
         * The timeline must be able to say "a person decided this" and "an AI
         * proposed that" without a reader having to know which is which. `source`
         * is what makes requirement 4 visible rather than merely true.
         */
        $suggestion->recordAudit(
            sprintf('AI suggestion %s: %s', $decision, AiCapability::label($suggestion->capability)),
            $actor,
            $note,
            [
                'action'     => 'ai_decision',
                'source'     => 'ai',
                'capability' => $suggestion->capability,
                'decision'   => $decision,
                'provider'   => $suggestion->provider,
                'model'      => $suggestion->model,
                'confidence' => $suggestion->confidence,
                'system'     => true,
            ],
        );

        return $suggestion->fresh();
    }

    /**
     * Whether a suggestion still describes the subject it was made about.
     *
     * A suggestion made before someone rewrote the description is stale, and
     * showing it as current advice is how a stale guess gets applied. The
     * fingerprint is recomputed by the caller from the same context builder.
     */
    public function isStale(AiSuggestion $suggestion, string $currentFingerprint): bool
    {
        return $suggestion->context_fingerprint !== null
            && $suggestion->context_fingerprint !== $currentFingerprint;
    }
}

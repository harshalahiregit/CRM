<?php

namespace Sire\AI;

use Sire\Models\AiSuggestion;
use Sire\Dto\SireUserIdentity;
use Sire\Contracts\SireSettingsProvider;
use Sire\Support\Ai\AiCapability;
use Sire\Support\Ai\AiRequest;
use Sire\Support\Ai\AiSuggestionResult;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * SIRE AI — the single door.
 *
 * Every AI interaction in SIRE goes through suggest(). Nothing else knows a
 * provider exists, and nothing else builds a context.
 *
 * THE CONTRACT THIS CLASS KEEPS
 *
 *   NEVER THROWS. Every path returns an AiSuggestionResult. SIRE must work when
 *   AI is unavailable, and an exception escaping here would turn an optional
 *   convenience into an outage on a page that has nothing to do with AI.
 *
 *   NEVER WRITES TO CORE. It creates rows in sire_ai_suggestions and nothing
 *   else. The AI layer has no write path to a report, a release or a root cause —
 *   requirement 3 is a structural property, not a promise.
 *
 *   NEVER SENDS WHAT IT WAS NOT GIVEN. Context is built by SireAiRedactor from a
 *   per-capability allowlist, and the provider receives only that.
 *
 * NO ANALYSIS IS IMPLEMENTED. The only registered provider declines everything.
 */
class SireAiGateway
{
    public function __construct(
        private readonly SireSettingsProvider $settings,
        private readonly SireAiRegistry $registry,
        private readonly SireAiRedactor $redactor,
    ) {
    }

    /**
     * Ask for a suggestion. Safe to call from anywhere, including a page render:
     * when AI is off this is three settings reads and a returned object.
     *
     * @param  array<string, mixed>  $rawContext  unfiltered; the redactor decides
     */
    public function suggest(
        int $tenantId,
        string $capability,
        Model $subject,
        array $rawContext,
        ?SireUserIdentity $actor = null,
    ): AiSuggestionResult {
        try {
            if (! AiCapability::isValid($capability)) {
                return AiSuggestionResult::unavailable("Unknown capability '{$capability}'.");
            }
            if (! $this->isEnabled($tenantId, $capability)) {
                return AiSuggestionResult::unavailable();
            }

            $provider = $this->registry->resolve(
                $this->settings->get($tenantId, 'sire.ai.provider', 'null'),
            );

            if (! $provider->supports($capability)) {
                return AiSuggestionResult::unsupported($capability, $provider->describe());
            }

            ['context' => $context, 'report' => $report] =
                $this->redactor->redact($capability, $rawContext);

            if ($context === []) {
                // Nothing survived redaction. Asking anyway would spend money to
                // receive a guess made from nothing.
                return AiSuggestionResult::unavailable('There is nothing safe to send for this capability.');
            }

            $request = new AiRequest(
                tenantId: $tenantId,
                capability: $capability,
                subjectType: $this->subjectTypeOf($subject),
                subjectId: $subject->getKey(),
                context: $context,
                redactionReport: $report,
                requestedBy: $actor?->id,
            );

            $result = $provider->suggest($request);

            if ($result->isOk()) {
                $this->persist($request, $result, $provider->describe(), $actor);
            }

            return $result;
        } catch (Throwable $e) {
            // A provider that throws despite the contract, a settings store that
            // is down, anything at all. AI failing must never surface as an error
            // to a user who was doing something else.
            report($e);

            return AiSuggestionResult::failed('AI suggestions are temporarily unavailable.');
        }
    }

    /** Whether AI is on at all, and on for this capability. Both must be true. */
    public function isEnabled(int $tenantId, ?string $capability = null): bool
    {
        if (! (bool) $this->settings->get($tenantId, 'sire.ai.enabled', false)) {
            return false; // OFF BY DEFAULT. Opting a tenant in is a decision.
        }
        if ($capability === null) {
            return true;
        }

        $enabled = (array) $this->settings->get($tenantId, 'sire.ai.capabilities', []);

        // Absent means off. A capability shipped later must not switch itself on
        // for every tenant that had enabled AI for something else.
        return (bool) ($enabled[$capability] ?? false);
    }

    /** What a tenant could turn on, and what is on. For the settings screen. */
    public function status(int $tenantId): array
    {
        $providerName = $this->settings->get($tenantId, 'sire.ai.provider', 'null');
        $provider = $this->registry->resolve($providerName);

        return [
            'enabled'           => $this->isEnabled($tenantId),
            'provider'          => $provider->describe(),
            'provider_configured' => $this->registry->isConfigured($providerName),
            'capabilities'      => collect(AiCapability::ALL)->mapWithKeys(fn (string $c) => [$c => [
                'label'          => AiCapability::label($c),
                'enabled'        => $this->isEnabled($tenantId, $c),
                'supported'      => $provider->supports($c),
                'advisory_only'  => AiCapability::isAdvisoryOnly($c),
            ]])->all(),
        ];
    }

    /**
     * The suggestion row. This is the ONLY table the AI layer writes to.
     *
     * `superseded` handling: a new suggestion for the same subject and capability
     * retires the previous one rather than deleting it. What the AI said last week
     * and what a human did about it is the record that makes the next decision
     * about AI possible.
     */
    private function persist(AiRequest $request, AiSuggestionResult $result, array $provider, ?SireUserIdentity $actor): void
    {
        AiSuggestion::query()
            ->forTenant($request->tenantId)
            ->where('subject_type', $request->subjectType)
            ->where('subject_id', $request->subjectId)
            ->where('capability', $request->capability)
            ->where('status', AiSuggestion::PENDING)
            ->update(['status' => AiSuggestion::SUPERSEDED]);

        AiSuggestion::create([
            'tenant_id'        => $request->tenantId,   // explicit, never ambient
            'subject_type'     => $request->subjectType,
            'subject_id'       => $request->subjectId,
            'capability'       => $request->capability,
            'status'           => AiSuggestion::PENDING,
            'payload'          => $result->payload,
            'confidence'       => $result->confidence,
            'evidence'         => $result->evidence,
            'provider'         => $provider['provider'] ?? null,
            'model'            => $provider['model'] ?? null,
            'model_version'    => $provider['model_version'] ?? null,
            'context_fingerprint' => $request->fingerprint(),
            'redaction_report' => $request->redactionReport,
            'requested_by'     => $actor?->id,
        ]);
    }

    private function subjectTypeOf(Model $subject): string
    {
        return match (class_basename($subject)) {
            'Report'          => AiCapability::SUBJECT_REPORT,
            'RecurrenceGroup' => AiCapability::SUBJECT_RECURRENCE_GROUP,
            'Release'         => AiCapability::SUBJECT_RELEASE,
            default           => AiCapability::SUBJECT_TENANT,
        };
    }
}

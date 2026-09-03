<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseOnboarding;

/**
 * Purchase onboarding checklist (§10) — mirror of TPV's, on Purchase's tables.
 *
 * Purchase had the approval chain but no checklist at all, so "was the JSA
 * actually reviewed before we let them start?" had nowhere to be recorded —
 * only the approver's memory. The chain says WHO signed; the checklist says
 * WHAT was verified, and they are not the same question.
 *
 * The rule engine is config-driven and matches on the vendor's dimensions
 * (risk level, category), so a Critical vendor draws a longer list than a
 * routine one. Items are stored as the same {label: bool} map TPV uses, so both
 * modules read alike.
 */
class PurchaseOnboardingChecklistService
{
    /** The resolved checklist plus its tick state. */
    public function checklist(PurchaseOnboarding $onboarding): array
    {
        $resolved = $this->resolve($onboarding);
        $state = $onboarding->checklist_state ?? [];

        $items = array_map(fn ($label) => [
            'item' => $label,
            'done' => (bool) ($state[$label] ?? false),
        ], $resolved['items']);

        $missing = array_values(array_filter($resolved['items'], fn ($l) => empty($state[$l])));

        return [
            'items'            => $items,
            'gates_activation' => (bool) $resolved['gates_activation'],
            'complete'         => $missing === [],
            'missing'          => $missing,
        ];
    }

    /**
     * Tick or untick items. Merged into the existing map rather than replacing
     * it, so a caller sending one item does not silently clear the rest.
     */
    public function setChecklist(PurchaseOnboarding $onboarding, array $state): PurchaseOnboarding
    {
        $current = $onboarding->checklist_state ?? [];
        foreach ($state as $item => $done) {
            $current[(string) $item] = (bool) $done;
        }
        $onboarding->update(['checklist_state' => $current]);

        return $onboarding->fresh() ?? $onboarding;
    }

    /**
     * Would activating this onboarding breach the checklist?
     *
     * Returns the unticked items when the checklist is configured to gate, and
     * an empty list otherwise. The caller decides what to do — this service does
     * not throw, because a checklist that blocks silently is indistinguishable
     * from one that is not configured.
     */
    public function blockers(PurchaseOnboarding $onboarding): array
    {
        $c = $this->checklist($onboarding);

        return $c['gates_activation'] ? $c['missing'] : [];
    }

    /**
     * The config-driven item list for this onboarding's dimensions.
     *
     * The general block always applies; each matching rule adds its items.
     * Unique-d, because two rules legitimately naming the same item should not
     * produce two checkboxes for one thing.
     */
    private function resolve(PurchaseOnboarding $onboarding): array
    {
        $cfg = config('purchase_onboarding_checklists', []);
        $context = $this->context($onboarding);
        $items = $cfg['general']['items'] ?? [];

        foreach ($cfg['rules'] ?? [] as $rule) {
            if ($this->matches($rule['match'] ?? [], $context)) {
                $items = array_merge($items, $rule['items'] ?? []);
            }
        }

        return [
            'items'            => array_values(array_unique($items)),
            'gates_activation' => (bool) ($cfg['general']['gates_activation'] ?? false),
        ];
    }

    /**
     * The [dimension => value] map rules match against.
     *
     * Only dimensions Purchase actually holds are passed. Rules keyed on
     * anything else stay dormant by design rather than matching on a null and
     * firing for every vendor.
     */
    private function context(PurchaseOnboarding $onboarding): array
    {
        $vendor = $onboarding->vendor;

        return array_filter([
            'risk_level' => $vendor?->risk_level,
            'category'   => $vendor?->category,
        ]);
    }

    private function matches(array $match, array $context): bool
    {
        if ($match === []) {
            return false;
        }

        foreach ($match as $dimension => $value) {
            if (($context[$dimension] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}

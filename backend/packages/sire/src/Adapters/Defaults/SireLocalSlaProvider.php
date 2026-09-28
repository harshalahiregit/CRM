<?php

namespace Sire\Adapters\Defaults;

use Sire\Contracts\SireSettingsProvider;
use Sire\Contracts\SireSlaProvider;
use Sire\Support\SireStatus;

/**
 * The shipped SLA provider: per-tenant policy from settings.
 *
 * THIS IS THE EXPECTED IMPLEMENTATION, not a placeholder for a host one. SIRE
 * owns SLA behaviour outright — which policy wins, when the clock pauses, how
 * warning and breach are derived, how alerts dedupe — and all of that lives in
 * SireSlaService, verified by a 15-case decision table in tests/fixtures/.
 *
 * A host implements SireSlaProvider only if it already has a service-level
 * configuration screen and wants SIRE to read from it. Nothing else about SLA
 * changes when it does.
 *
 * A tenant with no policies gets []. SireSlaService then reports every issue as
 * ON_TRACK with no targets, which is the right reading of "SLA not configured" —
 * inventing a default deadline would breach issues against a target nobody
 * agreed to.
 */
class SireLocalSlaProvider implements SireSlaProvider
{
    private const DEFAULT_WARNING_THRESHOLD = 0.8;

    public function __construct(private readonly SireSettingsProvider $settings)
    {
    }

    public function policies(int $tenantId): array
    {
        $policies = $this->settings->get($tenantId, 'sire.sla.policies', []);

        return is_array($policies) ? $policies : [];
    }

    public function warningThreshold(int $tenantId): float
    {
        $threshold = (float) $this->settings->get(
            $tenantId,
            'sire.sla.warning_threshold',
            self::DEFAULT_WARNING_THRESHOLD,
        );

        // Outside 0–1 the warning would land after the breach, which is worse
        // than no warning: it teaches people the amber state means nothing.
        return $threshold > 0.0 && $threshold < 1.0 ? $threshold : self::DEFAULT_WARNING_THRESHOLD;
    }

    public function pauseStates(int $tenantId): array
    {
        $states = (array) $this->settings->get($tenantId, 'sire.sla.pause_states', SireStatus::SLA_PAUSED);

        return array_values(array_filter($states, 'is_string'));
    }

    public function businessCalendar(int $tenantId): ?array
    {
        // Null means a 24/7 clock, which is what SIRE assumes. A business-hours
        // SLA needs the host to say what a business hour IS -- timezone, working
        // days, public holidays -- and SIRE will not guess any of the three.
        $calendar = $this->settings->get($tenantId, 'sire.sla.calendar');

        return is_array($calendar) && $calendar !== [] ? $calendar : null;
    }
}

<?php

namespace Sire\Contracts;

/**
 * SIRE SDK — SERVICE-LEVEL INPUTS. Not the algorithm.
 *
 * READ THIS BEFORE IMPLEMENTING
 *
 * You almost certainly should not implement this. SIRE owns SLA BEHAVIOUR
 * outright — which policy wins, when the clock pauses and resumes, how warning
 * and breach are derived, how alerts dedupe. All of that lives in SireSlaService
 * and a host never reimplements it.
 *
 * This interface supplies only the per-tenant INPUTS, and exists for one narrow
 * case: a host that already has a service-level configuration screen and wants
 * SIRE to read from it instead of from settings. If that is not you, leave it on
 * SireLocalSlaProvider and configure SLA through `sire.sla.*` settings.
 *
 * WHY THE LINE IS DRAWN HERE
 *
 * An adapter that started computing due dates would put product logic behind an
 * integration seam, where it becomes invisible to review and impossible to test
 * against SIRE's own fixtures. The 15-case SLA decision table in
 * tests/fixtures/ only means something while SIRE owns the calculation.
 *
 * A policy row:
 *
 *   ['match' => ['type' => 'bug', 'severity' => 'critical'],   // any subset
 *    'ack_minutes' => 30, 'resolve_minutes' => 480]
 *
 * Most-specific match wins; ties break toward the earlier row.
 *
 * Returning [] is valid and means "SLA not configured": SIRE then reports every
 * issue ON_TRACK with no target. Inventing a default deadline would breach
 * issues against a target nobody agreed to.
 */
interface SireSlaProvider
{
    /** @return array<int, array<string, mixed>> */
    public function policies(int $tenantId): array;

    /** Fraction of the resolution target at which WARNING begins. Strictly between 0 and 1. */
    public function warningThreshold(int $tenantId): float;

    /**
     * Statuses during which the clock is paused.
     *
     * @return array<int, string> SireStatus::* values
     */
    public function pauseStates(int $tenantId): array;

    /**
     * Working-hours calendar, if the host has one.
     *
     * Return null for a 24/7 clock, which is what SIRE assumes by default. An
     * internal engineering SLA measured in business hours needs the host to say
     * what a business hour is; SIRE will not guess a timezone or a holiday
     * calendar.
     *
     * @return array<string, mixed>|null
     */
    public function businessCalendar(int $tenantId): ?array;
}

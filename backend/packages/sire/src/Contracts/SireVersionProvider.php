<?php

namespace Sire\Contracts;

/**
 * SIRE SDK — WHICH BUILD.
 *
 * Four version fields carry an issue from report to proof:
 *
 *   detected_version  what was running when it was seen   (automatic)
 *   affected_version  what is known to carry the defect   (engineering judgement)
 *   fixed_version     the build containing the fix        (set at RELEASED)
 *   released_version  what actually reached the environment
 *
 * Only the first comes from here, and it is the one that must be automatic:
 * asking a user which build they were on is asking them to go and look, and they
 * will guess.
 *
 * NOT MANDATORY. With no host version service SIRE falls back to a config value,
 * then an environment variable, then null. A null version is honest and costs
 * nothing; a wrong one poisons every release dashboard.
 *
 * SIRE does not deploy anything and owns no version registry. It records versions
 * against issues and governs the release decision around them.
 */
interface SireVersionProvider
{
    /** The version currently deployed for this tenant, or null if unknown. */
    public function current(int $tenantId): ?string;

    /** 'production' | 'staging' | 'local' — recorded on captured context. */
    public function environment(): ?string;

    /**
     * Known versions, newest first — populates version pickers.
     *
     * @return array<int, string>
     */
    public function known(int $tenantId): array;

    /**
     * Canonical form of a user- or header-supplied version string, so that
     * "v2.14.0", "2.14.0 " and "2.14.0-build3312" group together on a dashboard.
     *
     * Return null for anything unrecognisable rather than inventing a version.
     */
    public function normalize(?string $raw): ?string;
}

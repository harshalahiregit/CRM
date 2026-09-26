<?php

namespace App\Support\Transport;

use App\Domains\Fleet\Contracts\DriverDirectory;
use App\Domains\Fleet\Directory\CrmDriverDirectory;
use App\Domains\Fleet\Directory\StandaloneDriverDirectory;

/**
 * Both directories at once — D-134.
 *
 * `config('stos.directory.driver')` offered `crm` or `standalone`, and `auto`
 * chose between them by asking whether CRM tables exist. That assumed the two
 * sources are ALTERNATIVES: integrated or standalone, never both. The config's
 * own words — *"use the CRM's directories when they are present, and the
 * STOS-local register when they are not"* — say exactly that.
 *
 * The D-62 move made them SIMULTANEOUS. Moving the transport masters into Fleet
 * inserted the drivers it found into `stos_drivers` (the standalone register)
 * and pointed their profiles at it with `source = 'stos'` — correctly, because
 * there was no CRM person to point at. So a CRM installation now legitimately
 * holds people in both registers, `auto` picked the CRM one, and the migrated
 * drivers became invisible: not blocked, not listed as ineligible, absent.
 *
 * Forcing `standalone` would have inverted the problem rather than fixed it —
 * every CRM-sourced driver would have disappeared to reveal these two.
 *
 * ── WHY THIS CLASS, AND WHY IT LIVES HERE ────────────────────────────────
 * `DriverDirectory` exists for precisely this. Its own docblock: *"Swapping the
 * implementation swaps the source. Nothing above this line has to know which
 * one is in use."* Neither underlying directory is wrong, and neither is
 * touched.
 *
 * It sat in `app/Domains/Fleet/Directory/` for two days, beside the two things
 * it composes, which is where a reader would look for it. It is here instead
 * because **the line is drawn at whose TREE, not whose CONCEPT**: the decision
 * this class encodes — that `auto` resolves to both sources — is ours, made in
 * our provider and our config. P2's two directories each answer for one source
 * because that is all either of them is; choosing to ask both is not a Fleet
 * fact. If he reorganises his folder he should not have to reason about a file
 * whose behaviour he did not choose.
 *
 * ── THE PROPERTY THE NAMESPACED REFS BUY ─────────────────────────────────
 * Every ref is `source:source_id`, and the sources are disjoint by
 * construction: `stos:` for the local register, `crm_*:` for the CRM ones. Each
 * implementation already returns null for a ref it does not own — verified, not
 * assumed — so `find()` can DISPATCH on the prefix instead of trying both and
 * taking whatever answers.
 *
 * That distinction matters and is guarded: asking the CRM directory for a
 * `stos:` ref must keep returning null. If a refusal ever became a fallback,
 * two registers could answer for one handle, and the handle would stop
 * identifying a person. `CompositeDriverDirectoryTest` fails on that.
 */
class CompositeDriverDirectory implements DriverDirectory
{
    public function __construct(
        private CrmDriverDirectory $crm,
        private StandaloneDriverDirectory $standalone,
    ) {
    }

    /**
     * Everyone, from both registers.
     *
     * Concatenated rather than merged: the refs are disjoint, so there is no
     * row to reconcile and nothing to deduplicate. Sorted by name afterwards,
     * because two lists in source order reads as two lists.
     *
     * @return array<int, array>
     */
    public function people(int $companyId, array $filters = []): array
    {
        $people = array_merge(
            $this->crm->people($companyId, $filters),
            $this->standalone->people($companyId, $filters),
        );

        usort($people, fn ($a, $b) => strcasecmp($a['name'] ?? '', $b['name'] ?? ''));

        return $people;
    }

    /**
     * One person, from whichever register owns that handle.
     *
     * Dispatched on the source, not attempted in turn. Asking both and taking
     * the first answer would work today and would quietly stop being correct
     * the moment a source name were reused.
     */
    public function find(int $companyId, string $source, int $sourceId): ?array
    {
        return $source === 'stos'
            ? $this->standalone->find($companyId, $source, $sourceId)
            : $this->crm->find($companyId, $source, $sourceId);
    }

    /** Both, named, so the UI can say where a person came from. */
    public function describe(): string
    {
        return $this->crm->describe().' Also '.lcfirst($this->standalone->describe());
    }
}

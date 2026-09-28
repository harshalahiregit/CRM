<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * D-62 — what moved into Fleet, and what a person still has to decide.
 *
 * The data-move migration deliberately refuses an ambiguous identity: when a
 * normalised registration — or licence — matches more than one Fleet row, no
 * automatic rule can say which one it is, and a wrong match silently attaches
 * one truck's fuel history, or one driver's licence, to another. The migration
 * leaves those alone and says to run this.
 *
 * ── ONE ENGINE, BOTH MASTERS (D-118) ─────────────────────────────────────
 * This used to be two methods that only looked alike. The vehicle half checked
 * each legacy row against Fleet and classified it; the driver half COUNTED
 * links and compared two numbers:
 *
 *     $migrated = DB::table('driver_profiles')->whereNotNull($link)->count();
 *
 * A count cannot tell a good link from a dangling one. With profiles linked to
 * drivers 33 and 34, and the live drivers being 39 and 40, that line reported
 * "2 legacy rows — 2 have a Fleet profile" and looked healthy against ids that
 * do not exist. Person 1 found it holding up the repoint: a repoint on that
 * mapping would have written permanent "unmatchable" verdicts against live
 * driver references on real trips.
 *
 * So there is one implementation now, parameterised by which master it is
 * reading. Two copies of a rule is how one of them ends up not being the rule.
 *
 * ── WHAT IT WILL AND WILL NOT DO ─────────────────────────────────────────
 * One matching identity is not an ambiguity. It means the row is in Fleet, the
 * identity agrees, and only the stored link is wrong — which is precisely what
 * a reseed of the legacy table leaves behind. That is repairable without a
 * judgement call, so `--relink` repairs it and nothing else. Anything with no
 * match, no identity to match on, or more than one candidate is only reported.
 */
class ReconcileFleetMasters extends Command
{
    protected $signature = 'stos:reconcile-fleet
                            {--relink : repair links where exactly one Fleet row carries the identity}
                            {--company= : limit to one company}';

    protected $description = 'Report which legacy transport vehicles and drivers are still unmigrated, and why';

    public function handle(): int
    {
        if (! Schema::hasTable('transport_vehicles') && ! Schema::hasTable('transport_drivers')) {
            $this->info('No legacy transport tables — nothing to reconcile.');

            return self::SUCCESS;
        }

        $company = $this->option('company');

        $this->reconcile($this->vehicleSpec(), $company);
        $this->newLine();
        $this->reconcile($this->driverSpec(), $company);

        return self::SUCCESS;
    }

    /* ── What each master is ────────────────────────────────────────── */

    private function vehicleSpec(): array
    {
        return [
            'noun' => 'Vehicles',
            'legacyTable' => 'transport_vehicles',
            'fleetTable' => 'vehicles',
            'fleetNoun' => 'Fleet vehicle',
            'link' => 'legacy_transport_vehicle_id',
            'legacyKey' => 'registration_number',
            'fleetKey' => 'registration_number',
            'keyName' => 'plate',
            'legacySelect' => ['id', 'tenant_id', 'registration_number'],
            'fleetSelect' => ['id', 'registration_number', 'legacy_transport_vehicle_id'],
            'softDeletes' => true,
            'describeLegacy' => fn ($row) => $row->registration_number,
            'describeFleet' => fn ($row) => $row->registration_number,
            'wrongMatchCosts' => "a wrong match attaches one truck's fuel and job history to another",
            'footer' => null,
        ];
    }

    private function driverSpec(): array
    {
        return [
            'noun' => 'Drivers',
            'legacyTable' => 'transport_drivers',
            'fleetTable' => 'driver_profiles',
            'fleetNoun' => 'Fleet profile',
            'link' => 'legacy_transport_driver_id',
            'legacyKey' => 'licence_number',
            'fleetKey' => 'licence_number',
            'keyName' => 'licence',
            'legacySelect' => ['id', 'tenant_id', 'name', 'licence_number'],
            'fleetSelect' => ['id', 'licence_number', 'legacy_transport_driver_id'],
            'softDeletes' => false,
            'describeLegacy' => fn ($row) => trim(($row->name ?: 'Unnamed').' · '.($row->licence_number ?: 'no licence')),
            'describeFleet' => fn ($row) => $row->licence_number ?: 'no licence',
            'wrongMatchCosts' => "a wrong match puts one person's licence and expiry on another driver",

            // Fleet holds no names, so a driver that never moved is not the same
            // kind of problem a vehicle would be. Said once, at the end, rather
            // than against every row.
            'footer' => [
                'Fleet stores no names: a driver is a reference into the CRM directory plus a',
                'licence. A legacy driver who is not a person in the CRM has to be created there',
                'first — that is the design, not a gap.',
            ],
        ];
    }

    /* ── The engine ─────────────────────────────────────────────────── */

    private function reconcile(array $spec, ?string $company): void
    {
        if (! Schema::hasTable($spec['legacyTable'])) {
            $this->info("{$spec['noun']}: no legacy table.");

            return;
        }

        $legacy = DB::table($spec['legacyTable'])
            ->when($company, fn ($q) => $q->where('tenant_id', $company))
            ->get($spec['legacySelect']);

        if ($legacy->isEmpty()) {
            $this->info("{$spec['noun']}: the legacy table is empty. Nothing outstanding.");

            return;
        }

        $buckets = ['migrated' => [], 'repairable' => [], 'ambiguous' => [], 'missing' => [], 'unidentifiable' => []];

        foreach ($legacy as $row) {
            $buckets[$this->classify($spec, $row, $candidates)][] = [
                'legacy' => $row,
                'candidates' => $candidates,
            ];
        }

        $outstanding = $legacy->count() - count($buckets['migrated']);

        $this->line("{$spec['noun']}: {$legacy->count()} legacy rows — "
            .count($buckets['migrated']).' linked, '.$outstanding.' outstanding.');

        if ($outstanding === 0) {
            $this->info('  Nothing needs a person. The legacy table can be retired on schedule.');

            return;
        }

        $this->newLine();
        $this->report($spec, $buckets);

        if ($spec['footer'] && ($buckets['missing'] !== [] || $buckets['unidentifiable'] !== [])) {
            $this->newLine();
            foreach ($spec['footer'] as $line) {
                $this->line('  '.$line);
            }
        }
    }

    /**
     * Which of the five states is this legacy row in?
     *
     * `$candidates` is filled with the Fleet rows carrying the same identity,
     * so the caller can name them without querying twice.
     */
    private function classify(array $spec, object $row, ?Collection &$candidates): string
    {
        $candidates = collect();

        // A link that points at a row that IS there is the only thing that
        // counts as migrated. Not "a link exists" — that was D-118.
        $linked = DB::table($spec['fleetTable'])
            ->where($spec['link'], $row->id)
            ->when($spec['softDeletes'], fn ($q) => $q->whereNull('deleted_at'))
            ->exists();

        if ($linked) {
            return 'migrated';
        }

        $key = $this->normalise($row->{$spec['legacyKey']} ?? null);

        // Nothing to match on. For a driver this is real — a legacy row can
        // carry no licence at all — and no rule can repair it.
        if ($key === '') {
            return 'unidentifiable';
        }

        $candidates = DB::table($spec['fleetTable'])
            ->where('company_id', $row->tenant_id)
            ->when($spec['softDeletes'], fn ($q) => $q->whereNull('deleted_at'))
            ->get($spec['fleetSelect'])
            ->filter(fn ($f) => $this->normalise($f->{$spec['fleetKey']} ?? null) === $key)
            ->values();

        return match (true) {
            $candidates->count() === 0 => 'missing',
            $candidates->count() === 1 => 'repairable',
            default => 'ambiguous',
        };
    }

    private function report(array $spec, array $buckets): void
    {
        foreach ($buckets['missing'] as $entry) {
            // Should not happen: the migration inserts these. Worth saying
            // loudly rather than reporting a tidy zero.
            $this->warn("  · #{$entry['legacy']->id} {$this->describe($spec, $entry['legacy'])}"
                ." — no {$spec['fleetNoun']} carries this {$spec['keyName']} and none was inserted. Re-run the migration.");
        }

        foreach ($buckets['unidentifiable'] as $entry) {
            $this->warn("  · #{$entry['legacy']->id} {$this->describe($spec, $entry['legacy'])}"
                ." — no {$spec['keyName']} recorded, so nothing can be matched on.");
            $this->line('      Needs a person: give it one in the legacy record, or link it by hand.');
        }

        foreach ($buckets['ambiguous'] as $entry) {
            $ids = $entry['candidates']->pluck('id')->implode(', ');
            $shown = $entry['candidates']->map(fn ($c) => $this->describeFleet($spec, $c))->implode(', ');

            $this->warn("  · #{$entry['legacy']->id} {$this->describe($spec, $entry['legacy'])}"
                ." — AMBIGUOUS, {$entry['candidates']->count()} carry this {$spec['keyName']} (ids {$ids}: {$shown}).");
            $this->line("      Needs a person: correct the duplicate {$spec['keyName']}s in Fleet, then re-run the migration.");
            $this->line("      Do not guess — {$spec['wrongMatchCosts']}.");
        }

        if ($buckets['repairable'] !== []) {
            $this->repairable($spec, $buckets['repairable']);
        }
    }

    /**
     * Exactly one Fleet row carries the identity — so the link is knowable.
     *
     * The only reason it is not already set is that something replaced the
     * legacy side: a reseed gives the same truck or the same driver a new id,
     * and the stored link keeps pointing at the row that used to be there.
     *
     * Repairing is still refused when the Fleet row is already linked to a
     * legacy row that STILL EXISTS. Two live legacy rows competing for one
     * Fleet row is a decision, not a repair.
     */
    private function repairable(array $spec, array $entries): void
    {
        $relink = (bool) $this->option('relink');

        $this->line("  These are not ambiguous — exactly one {$spec['fleetNoun']} carries the {$spec['keyName']},");
        $this->line('  and only the stored link is wrong. That is what a reseed of the legacy table leaves behind.');
        $this->newLine();

        $repaired = 0;

        foreach ($entries as $entry) {
            $row = $entry['legacy'];
            $fleet = $entry['candidates']->first();
            $held = $fleet->{$spec['link']};

            $contested = $held
                && (int) $held !== (int) $row->id
                && DB::table($spec['legacyTable'])->where('id', $held)->exists();

            if ($contested) {
                $this->warn("  · #{$row->id} {$this->describe($spec, $row)}"
                    ." — {$spec['fleetNoun']} #{$fleet->id} is already linked to live legacy #{$held}.");
                $this->line("      Needs a person: two legacy rows claim one {$spec['fleetNoun']}.");

                continue;
            }

            if (! $relink) {
                $this->line("  · #{$row->id} {$this->describe($spec, $row)} — {$spec['fleetNoun']} #{$fleet->id}"
                    .($held ? " (link points at #{$held}, which is gone)" : ' (no link stored)').'. Repairable.');

                continue;
            }

            DB::table($spec['fleetTable'])->where('id', $fleet->id)->update([$spec['link'] => $row->id]);

            $repaired++;
            $this->info("  · #{$row->id} {$this->describe($spec, $row)} — relinked to {$spec['fleetNoun']} #{$fleet->id}.");
        }

        $this->newLine();

        if ($relink) {
            $this->info("  Repaired {$repaired} link".($repaired === 1 ? '' : 's').'.');

            return;
        }

        $this->line("  Re-run with --relink to repair them. It only writes `{$spec['link']}`, and only");
        $this->line('  where one identity matches one row — nothing else is touched.');
    }

    private function describe(array $spec, object $row): string
    {
        return ($spec['describeLegacy'])($row);
    }

    private function describeFleet(array $spec, object $row): string
    {
        return ($spec['describeFleet'])($row);
    }

    /**
     * The same normalisation both masters use.
     *
     * "MH 12 AB 1234" and "MH12AB1234" are one truck; "MH-01 2011 0012345" and
     * "MH01201100 12345" are one licence.
     */
    private function normalise(?string $value): string
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));
    }
}

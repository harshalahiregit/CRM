<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * D-109 — repoint trip references from the legacy masters onto Fleet.
 *
 * This was inside the data-move migration and should never have been. Moving
 * the rows and repointing the keys are two different decisions: the first is
 * safe on its own, the second is only safe at the exact moment Operations
 * switches its readers from `transport_vehicles` to `vehicles`.
 *
 * Doing it early orphans every trip SILENTLY — the vehicle and driver fields
 * just go blank on screen, with no error — and it cannot be rolled back,
 * because by then the Fleet rows are live masters carrying their own history.
 *
 * So it is a command, it defaults to a dry run, and it has to be asked for.
 *
 * ── THE MAP IS NOT STORED, BUT THE VERDICT IS ─────────────────────────────
 * The mapping is reconstructed from `legacy_transport_vehicle_id` /
 * `legacy_transport_driver_id` on each run, because a stored map is one more
 * thing that can go stale between the move and the switch. It does go stale:
 * a reseed of the legacy table left ours pointing at rows 29 and 30 while the
 * live rows for the same two trucks were 35 and 36, and the dry run then read
 * "0 rows" — which looks like "nothing to do" and actually meant "the map
 * matches nothing". `--relink` on `stos:reconcile-fleet` repairs that, and
 * this command now says so rather than reporting a tidy zero.
 *
 * What IS written down is what this command decided about each reference, in
 * `fleet_reference_repoints`. That exists for D-116: after the switch, nothing
 * in a trip row says which id space its number is in, and the references this
 * command could NOT map are still sitting in the old one. A verdict per row —
 * including "this pointed at something that is gone" — is what lets telemetry
 * answer that question from data instead of from the shape of the number.
 *
 * It also makes the switch reversible, which it was not before.
 */
class RepointTripFleetReferences extends Command
{
    protected $signature = 'stos:repoint-trip-fleet-refs
                            {--apply : actually write; without this it only reports}
                            {--force : apply even though some references cannot be mapped}
                            {--company= : limit to one company}';

    protected $description = 'D-109 — point transport_trips and trip_assignments at the Fleet masters (dry run unless --apply)';

    private const LEDGER = 'fleet_reference_repoints';

    /**
     * What the ledger is allowed to say about a reference — D-131.
     *
     * There used to be two outcomes, carried implicitly by `to_id`: set meant
     * moved, null meant stranded, which the command describes as *"points at a
     * legacy row with no Fleet counterpart"*.
     *
     * `trip_advances` #2 holds `driver_id = 1212010`. That value is in no table
     * anywhere. Calling it stranded is not a rough approximation of the truth,
     * it is a different claim that happens to be false — and every reader
     * downstream would then refuse the row on a stated ground that was not the
     * real one. Same family as D-116 and D-118: an answer that is wrong without
     * being an error.
     *
     * A migration tool that has to describe bad data will eventually meet data
     * none of its descriptions fit, and the temptation is to use the nearest
     * one. The nearest one is a lie. So there is a third word.
     *
     * The distinction is operational, not pedantic: UNMAPPED_LEGACY is fixed by
     * finishing the migration, NEVER_VALID is fixed by somebody correcting the
     * row, and those are different people's work.
     *
     * ── WHAT NEVER_VALID CAN AND CANNOT PROVE ────────────────────────────
     * What is checked is narrow and literal: **the value is not an id in the
     * legacy master today**. That is true of `1212010`, which was never an id
     * anywhere. It is ALSO true of a legacy row that existed and was later
     * deleted — trips 2, 12 and 14 point at legacy ids 10, 16, 17 and 21, which
     * a reseed removed. The tool cannot tell "never existed" from "deleted
     * since", because both leave exactly the same absence behind.
     *
     * So the verdict is not claiming to know history. It is claiming that
     * nothing in the legacy master answers to this value, which is the thing a
     * reader needs and the thing that can be demonstrated. The alternative —
     * calling them unmapped legacy rows — would assert a legacy row exists when
     * none does, which is the lie this constant was added to stop telling.
     */
    private const MOVED = 'moved';

    private const UNMAPPED_LEGACY = 'unmapped_legacy';

    private const NEVER_VALID = 'never_valid';

    /**
     * Every reference into the legacy masters — D-120.
     *
     * This was four entries written inline, and there are SEVEN. Person 1 found
     * the three that were missing: `trip_exceptions.vehicle_id`,
     * `trip_exceptions.driver_id` and `trip_advances.driver_id`. After `--apply`
     * those columns would still have meant the legacy tables while the other
     * four meant Fleet — one schema, two id spaces, nothing marking which —
     * and it would have passed silently, because nothing loads
     * `$exception->vehicle` today.
     *
     * (He said six. It is seven; he had the three omissions exactly right.)
     *
     * Declared as data rather than written into the plan loop, and
     * `RepointCoversEveryReferenceTest` asserts this list is EVERY tenant-scoped
     * `vehicle_id`/`driver_id` column in the schema. A table added later fails
     * that test instead of being quietly missed, which is the only thing that
     * stops this happening a third time.
     *
     * `trip_assignments.active_vehicle_id` / `active_driver_id` are absent on
     * purpose: they are STORED generated columns off the two real ones and
     * follow automatically. They matter to `unsafeCollisions()` below, not here.
     */
    private const REFERENCES = [
        ['transport_trips', 'vehicle_id', 'vehicle'],
        ['transport_trips', 'driver_id', 'driver'],
        ['trip_assignments', 'vehicle_id', 'vehicle'],
        ['trip_assignments', 'driver_id', 'driver'],
        ['trip_exceptions', 'vehicle_id', 'vehicle'],
        ['trip_exceptions', 'driver_id', 'driver'],
        ['trip_advances', 'driver_id', 'driver'],
    ];

    /**
     * The statuses `trip_assignments.active_vehicle_id` treats as holding the
     * resource — kept identical to the CASE in that table's generated column.
     */
    private const ASSIGNMENT_HOLDS = ['assigned', 'confirmed', 'active'];

    /** Legacy ids claimed by more than one Fleet row; filled by legacyMap(). */
    private array $contested = [];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $vehicleMap = $this->legacyMap('vehicles', 'legacy_transport_vehicle_id');
        $driverMap  = $this->legacyMap('driver_profiles', 'legacy_transport_driver_id');

        // Two Fleet rows claiming one legacy id means the mapping is not a
        // mapping. Refused before anything is surveyed, dry run included —
        // a dry run built on a guessed map reports numbers nobody should trust.
        if ($this->contested !== []) {
            $this->error('The mapping is contested — two Fleet rows claim the same legacy row:');

            foreach ($this->contested as $line) {
                $this->line('  · '.$line);
            }

            $this->newLine();
            $this->line('  Only one of them can be right and nothing here can tell which.');
            $this->line('  Clear the duplicate `legacy_*_id` by hand, then run this again.');

            return self::FAILURE;
        }

        if ($vehicleMap === [] && $driverMap === []) {
            $this->info('Nothing has been moved into the Fleet masters yet — there is nothing to repoint.');

            return self::SUCCESS;
        }

        $this->line(sprintf('Mapping: %d vehicles, %d drivers.', count($vehicleMap), count($driverMap)));
        $this->newLine();

        if (! $apply) {
            $this->warn('DRY RUN — nothing will be written. Add --apply when Operations switches its readers.');
            $this->newLine();
        }

        $maps = ['vehicle' => $vehicleMap, 'driver' => $driverMap];
        $plan = [];

        foreach (self::REFERENCES as [$table, $column, $kind]) {
            $map = $maps[$kind];

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column) || $map === []) {
                continue;
            }

            $legacyTable = $kind === 'vehicle' ? 'transport_vehicles' : 'transport_drivers';

            $plan[] = [$table, $column, $map, $this->survey($table, $column, $map, $legacyTable)];
        }

        $movable = array_sum(array_map(fn ($p) => count($p[3]['movable']), $plan));
        $stranded = array_sum(array_map(fn ($p) => count($p[3]['stranded']), $plan));

        $neverValid = [];

        foreach ($plan as [$table, $column, , $survey]) {
            $unmapped = array_filter($survey['stranded'], fn ($r) => $r['verdict'] === self::UNMAPPED_LEGACY);
            $invalid = array_filter($survey['stranded'], fn ($r) => $r['verdict'] === self::NEVER_VALID);

            $this->line(sprintf('  %-18s %-12s %d to move, %d legacy ids not migrated, %d that are not ids at all',
                $table, $column, count($survey['movable']), count($unmapped), count($invalid)));

            foreach ($invalid as $row) {
                $neverValid[] = sprintf('%s #%d holds %s in %s', $table, $row['id'], $row['from'], $column);
            }
        }

        // Said separately and by row, because it is a different problem with a
        // different owner: an unmapped legacy id is fixed by finishing the
        // migration, a value that is not an id is fixed by correcting the row.
        if ($neverValid !== []) {
            $this->newLine();
            $this->warn('  These hold a value that exists in NO legacy table — they were never references:');

            foreach ($neverValid as $line) {
                $this->line('    · '.$line);
            }

            $this->line('    Recorded as `'.self::NEVER_VALID.'`, not as an unmapped legacy id. Those are');
            $this->line('    different failures and the ledger must not call one by the other\'s name.');
        }

        $this->newLine();

        // Said in the dry run too, because switch-day is the wrong time to
        // learn that two active assignments want the same truck.
        $clashes = $this->unsafeCollisions($plan);

        if ($clashes !== []) {
            $this->error('Repointing would put two ACTIVE assignments on one resource:');

            foreach ($clashes as $line) {
                $this->line('  · '.$line);
            }

            $this->line('  trip_assignments allows one active assignment per vehicle and per driver.');
            $this->line('  Release one of each pair first — this cannot be resolved by repointing.');

            return self::FAILURE;
        }

        if ($movable === 0 && $stranded === 0) {
            $this->info('Every reference has already been ruled on. Nothing left to do.');

            return self::SUCCESS;
        }

        // "0 to move" used to print as a clean zero and read as "done". It is
        // the opposite when the map itself is stale, so say which one it is.
        if ($movable === 0) {
            $this->warn('Nothing can be moved: every reference points at a legacy id the mapping does not cover.');
            $this->line('  That is usually a stale `legacy_transport_vehicle_id` rather than finished work.');
            $this->line('  Run `php artisan stos:reconcile-fleet` — and `--relink` if it reports repairable links.');
            $this->newLine();
        }

        if (! $apply) {
            $this->line("Nothing written. Re-run with --apply once Operations reads Fleet's tables —");
            $this->line('until then, repointing these would blank the vehicle and driver on every trip.');

            return self::SUCCESS;
        }

        // A reference that cannot be mapped is recorded as stranded and left
        // where it is. That is the safe outcome, but it is also permanent, so
        // it is not something to discover afterwards.
        if ($stranded > 0 && ! $this->option('force')) {
            $this->error("{$stranded} references cannot be moved.");
            $this->line('  They will be left alone and recorded under the verdict that is TRUE of each —');
            $this->line('  `'.self::UNMAPPED_LEGACY.'` for a real legacy row not yet migrated, `'.self::NEVER_VALID.'` for a');
            $this->line('  value that was never a reference. Readers refuse them either way, but the');
            $this->line('  ledger says which problem it is, and therefore whose it is.');
            $this->line('  Fix the mapping first, or pass --force if recording them is intended.');

            return self::FAILURE;
        }

        $moved = 0;

        foreach ($plan as [$table, $column, , $survey]) {
            $moved += $this->apply($table, $column, $survey);
        }

        Log::channel('stos')->warning('Trip references repointed onto the Fleet masters', [
            'defect' => 'D-109', 'rows' => $moved, 'stranded' => $stranded,
        ]);

        $this->newLine();
        $this->info("Repointed {$moved} rows; {$stranded} recorded as unmatchable.");
        $this->line('The legacy tables still hold their rows and can still be read. Nothing was dropped.');
        $this->line('Every decision is in `'.self::LEDGER.'`, so this can be read back and undone.');

        return self::SUCCESS;
    }

    /**
     * old legacy id => new Fleet id, reconstructed from the moved rows.
     *
     * ── A DUPLICATED LINK USED TO VANISH HERE ─────────────────────────────
     * `pluck('id', $column)` keys by the legacy id, so if two Fleet rows both
     * claim legacy #35 the second silently overwrites the first and the whole
     * repoint runs against a mapping nobody chose. Same family as D-116: an
     * answer that is wrong without being an error.
     *
     * `stos:reconcile-fleet --relink` refuses to create that state, but it can
     * arrive by hand, by an import, or by a half-finished migration. So it is
     * checked rather than assumed, and it stops the run.
     */
    private function legacyMap(string $table, string $column): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return [];
        }

        $company = $this->option('company');

        $rows = DB::table($table)
            ->whereNotNull($column)
            ->when($company, fn ($q) => $q->where('company_id', $company))
            ->get(['id', $column]);

        $map = [];

        foreach ($rows as $row) {
            $legacyId = (int) $row->{$column};

            if (isset($map[$legacyId])) {
                $this->contested[] = sprintf('%s #%d and #%d both claim legacy #%d',
                    $table, $map[$legacyId], $row->id, $legacyId);

                continue;
            }

            $map[$legacyId] = (int) $row->id;
        }

        return $map;
    }

    /**
     * Rows that would violate a uniqueness rule the moment they are repointed.
     *
     * `trip_assignments` enforces one ACTIVE assignment per vehicle and per
     * driver, per tenant, through unique indexes on stored generated columns.
     * Those columns follow `vehicle_id` and `driver_id` automatically — so a
     * repoint that lands two active assignments on the same Fleet id trips the
     * index mid-run.
     *
     * The write is transactional, so nothing is half-written; but discovering
     * it on switch-day, partway through, is the worst moment to find out. The
     * dry run says it now.
     */
    private function unsafeCollisions(array $plan): array
    {
        $clashes = [];

        foreach ($plan as [$table, $column, , $survey]) {
            if ($table !== 'trip_assignments') {
                continue;
            }

            $seen = [];

            foreach ($survey['movable'] as $row) {
                if (! $this->isActiveAssignment((int) $row['id'])) {
                    continue;
                }

                $key = $row['tenant_id'].':'.$row['to'];

                // Two rows that are both moving onto the same Fleet id.
                if (isset($seen[$key])) {
                    $clashes[] = sprintf('%s.%s — assignments #%d and #%d would both be active on #%d',
                        $table, $column, $seen[$key], $row['id'], $row['to']);

                    continue;
                }

                $seen[$key] = (int) $row['id'];

                // And the case the first version missed: a row that is NOT
                // moving because it already holds the Fleet id natively. That
                // is the partial state the switch itself creates, so it is the
                // likeliest collision of the two, not the rarest.
                $holder = DB::table($table)
                    ->where('tenant_id', $row['tenant_id'])
                    ->where($column, $row['to'])
                    ->where('id', '!=', $row['id'])
                    ->whereIn('status', self::ASSIGNMENT_HOLDS)
                    ->value('id');

                if ($holder) {
                    $clashes[] = sprintf('%s.%s — assignment #%d would move onto #%d, which active assignment #%d already holds',
                        $table, $column, $row['id'], $row['to'], $holder);
                }
            }
        }

        return $clashes;
    }

    private function isActiveAssignment(int $id): bool
    {
        return DB::table('trip_assignments')->where('id', $id)
            ->whereIn('status', self::ASSIGNMENT_HOLDS)
            ->exists();
    }

    /**
     * What this column holds today, split into what maps and what does not.
     *
     * Rows already ruled on are skipped as a FACT rather than by hoping a Fleet
     * id does not collide with a legacy one. The old version assumed "a
     * repointed row no longer matches any legacy id", which is the same kind of
     * luck D-116 was about.
     */
    private function survey(string $table, string $column, array $map, string $legacyTable): array
    {
        $company = $this->option('company');
        $ruled = $this->alreadyRuled($table, $column);

        // Every id the legacy master actually holds. Read once per column
        // rather than per row: it is two rows today and this is the check that
        // separates "not migrated yet" from "never was a reference".
        $legacyIds = Schema::hasTable($legacyTable)
            ? DB::table($legacyTable)->pluck('id')->map(fn ($id) => (int) $id)
            : collect();

        $movable = $stranded = [];

        DB::table($table)
            ->whereNotNull($column)
            ->when($company, fn ($q) => $q->where('tenant_id', $company))
            ->orderBy('id')
            ->select(['id', 'tenant_id', $column])
            ->chunk(500, function ($rows) use ($column, $map, $ruled, $legacyIds, &$movable, &$stranded) {
                foreach ($rows as $row) {
                    if (isset($ruled[(int) $row->id])) {
                        continue;
                    }

                    $old = (int) $row->{$column};
                    $entry = ['id' => (int) $row->id, 'tenant_id' => (int) $row->tenant_id, 'from' => $old];

                    if (isset($map[$old])) {
                        $entry['to'] = (int) $map[$old];
                        $entry['verdict'] = self::MOVED;
                        $movable[] = $entry;
                    } else {
                        // Not in the mapping. Two different things look the
                        // same here, so ask which one it is rather than
                        // assuming the commoner: a legacy row we have not
                        // migrated yet, or a value that was never a reference.
                        $entry['to'] = null;
                        $entry['verdict'] = $legacyIds->contains($old)
                            ? self::UNMAPPED_LEGACY
                            : self::NEVER_VALID;
                        $stranded[] = $entry;
                    }
                }
            });

        return ['movable' => $movable, 'stranded' => $stranded];
    }

    /** row id => true, for references this command has already decided. */
    private function alreadyRuled(string $table, string $column): array
    {
        if (! Schema::hasTable(self::LEDGER)) {
            return [];
        }

        return DB::table(self::LEDGER)
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->pluck('row_id')
            ->mapWithKeys(fn ($id) => [(int) $id => true])
            ->all();
    }

    /** Write the verdicts, then move the ones that have somewhere to go. */
    private function apply(string $table, string $column, array $survey): int
    {
        $moved = 0;

        foreach (array_chunk(array_merge($survey['movable'], $survey['stranded']), 200) as $batch) {
            DB::transaction(function () use ($table, $column, $batch, &$moved) {
                $ledger = [];

                foreach ($batch as $row) {
                    $ledger[] = [
                        'company_id' => $row['tenant_id'],
                        'table_name' => $table, 'column_name' => $column,
                        'row_id' => $row['id'],
                        'from_id' => $row['from'], 'to_id' => $row['to'],
                        'verdict' => $row['verdict'],
                        'applied_at' => now(),
                    ];

                    if ($row['to'] !== null) {
                        DB::table($table)->where('id', $row['id'])->update([$column => $row['to']]);
                        $moved++;
                    }
                }

                // The verdict is written in the same transaction as the move it
                // describes. A ledger that can disagree with the data would be
                // worse than no ledger at all.
                DB::table(self::LEDGER)->insert($ledger);
            });
        }

        return $moved;
    }
}

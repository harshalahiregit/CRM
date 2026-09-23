<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-MAINT — the casing becomes an asset (T-36).
 *
 * `tyre_fitments.tyre_id` has been a bare string since M2: the number stamped
 * on the casing, repeated on every fitment row. That is enough to answer "what
 * is on this axle" and nothing else. It cannot answer the two questions a
 * workshop actually asks:
 *
 *   what has this casing cost us per kilometre, across every truck it has
 *   been on and every retread it has had?
 *
 *   what is in the store right now?
 *
 * Both need the CASING to be a row. A tyre outlives the vehicle it is fitted
 * to — that is the whole economics of retreading — so modelling it as an
 * attribute of a fitment loses the thing worth tracking.
 *
 * ── TRAILERS WEAR TYRES TOO ───────────────────────────────────────────────
 * T-54 made trailers their own master last night, and a fitment could only
 * point at a `vehicles` row. The existing workaround was a POSITION called
 * `trailer_1`, which records that a tyre is somewhere on some trailer and not
 * which one — useless the moment the trailer is swapped, which is daily.
 *
 * So a fitment now points at a vehicle OR a trailer, and the service refuses
 * both and neither.
 *
 * ── THE BACKFILL LOSES NOTHING ────────────────────────────────────────────
 * Every distinct `tyre_id` becomes a master and its fitments are linked to it.
 * The string column stays exactly where it is: it is the serial as somebody
 * typed it, and rewriting history to match a new key is how a migration gets
 * blamed for a number nobody recognises.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tyre_masters')) {
            Schema::create('tyre_masters', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id')->index();

                // Stamped on the casing. One per company — the same number on
                // two rows is two people recording one tyre.
                $table->string('serial_number', 60);

                $table->string('brand', 60)->nullable();
                // "295/80 R22.5" — free text, because the format varies by
                // market and a dropdown would be wrong within a year.
                $table->string('size', 40)->nullable();
                $table->string('pattern', 60)->nullable();

                $table->decimal('purchase_cost', 12, 2)->nullable();
                $table->date('purchase_date')->nullable();
                $table->string('supplier', 120)->nullable();

                // IN_STOCK | FITTED | RETREADED | SCRAPPED
                $table->string('status', 20)->default('IN_STOCK');

                // How many times it has been to the retreader. The economics of
                // a casing are "cost ÷ total km across all its lives", and the
                // count is what says whether another life is even possible.
                $table->unsignedTinyInteger('retread_count')->default(0);
                $table->decimal('retread_cost_total', 12, 2)->default(0);

                $table->decimal('new_tread_depth', 4, 2)->nullable();    // mm
                $table->decimal('scrap_tread_depth', 4, 2)->nullable();  // mm, the legal floor

                $table->date('scrapped_on')->nullable();
                $table->string('scrap_reason', 255)->nullable();

                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();

                $table->timestamps();
                $table->softDeletes();

                $table->unique(['company_id', 'serial_number'], 'tyre_masters_company_serial_uniq');
                $table->index(['company_id', 'status'], 'tyre_masters_company_status_idx');
            });
        }

        Schema::table('tyre_fitments', function (Blueprint $table) {
            if (! Schema::hasColumn('tyre_fitments', 'tyre_master_id')) {
                $table->unsignedBigInteger('tyre_master_id')->nullable()->after('tyre_id')->index();
            }

            if (! Schema::hasColumn('tyre_fitments', 'trailer_id')) {
                // Nullable, and so is `vehicle_id`: a fitment is on one or the
                // other. Exactly one is the service's rule, because a CHECK
                // constraint is not portable across the two engines this runs
                // on and a half-enforced rule reads worse than a stated one.
                $table->unsignedBigInteger('trailer_id')->nullable()->after('vehicle_id')->index();
            }
        });

        $this->backfill();
    }

    /**
     * One master per distinct serial, per company, and its fitments linked.
     *
     * Runs on the existing rows only — nothing is invented, and the serial
     * string stays on every fitment exactly as it was typed.
     */
    private function backfill(): void
    {
        $serials = DB::table('tyre_fitments')
            ->whereNull('tyre_master_id')
            ->whereNotNull('tyre_id')
            ->select('company_id', 'tyre_id')
            ->distinct()->get();

        foreach ($serials as $row) {
            $masterId = DB::table('tyre_masters')
                ->where('company_id', $row->company_id)
                ->where('serial_number', $row->tyre_id)
                ->value('id');

            if (! $masterId) {
                // The status is derived from what the fitments say rather than
                // defaulted: a casing whose last fitment is still open is
                // FITTED, and calling it IN_STOCK would put a tyre that is on a
                // truck into the store list.
                $open = DB::table('tyre_fitments')
                    ->where('company_id', $row->company_id)
                    ->where('tyre_id', $row->tyre_id)
                    ->whereIn('status', ['FITTED', 'fitted'])
                    ->exists();

                $masterId = DB::table('tyre_masters')->insertGetId([
                    'company_id' => $row->company_id,
                    'serial_number' => $row->tyre_id,
                    'status' => $open ? 'FITTED' : 'IN_STOCK',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            DB::table('tyre_fitments')
                ->where('company_id', $row->company_id)
                ->where('tyre_id', $row->tyre_id)
                ->whereNull('tyre_master_id')
                ->update(['tyre_master_id' => $masterId]);
        }
    }

    public function down(): void
    {
        Schema::table('tyre_fitments', function (Blueprint $table) {
            foreach (['tyre_master_id', 'trailer_id'] as $column) {
                if (Schema::hasColumn('tyre_fitments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('tyre_masters');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cycle counting and physical verification (§12).
 *
 * The ledger says what SHOULD be on the shelf. A count is the only thing that
 * ever checks whether it is. Everything else in the module is bookkeeping about
 * bookkeeping; this is the one place where the app is corrected by the building.
 *
 * A session is a scope of stock, frozen into lines, walked by somebody, and then
 * approved by somebody else — approval being the moment the corrections reach
 * the ledger as ordinary adjustments.
 *
 *     draft → counting → pending_approval → approved
 *                     ↖ (rejected, back to counting) ↙
 *
 * TWO system snapshots per line, and the difference between them is the whole
 * design:
 *
 *   • `system_qty`      — what the ledger said when the session was RAISED. A
 *                         reference figure for the supervisor, nothing more.
 *   • `system_at_count` — what the ledger said at the instant the counter typed
 *                         their figure. THIS is what the variance is measured
 *                         against.
 *
 * Measuring against the raise-time figure would be wrong: a delivery posted
 * legitimately at 11am would look like a counting error at 3pm. Measuring at
 * count time makes the window unambiguous — anything that moves afterwards
 * provably happened after the counter looked, so it is real and must survive the
 * correction rather than be flattened by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_count_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('code', 30);
            $table->string('name', 160)->nullable();
            $table->unsignedBigInteger('warehouse_id')->index();

            // What is being verified. `full` is a wall-to-wall stocktake; the
            // rest are cycle counts — small, frequent, and far more likely to
            // actually happen than an annual shutdown.
            $table->string('scope', 20)->default('full');   // full|location|category|abc|product|random
            $table->json('scope_ref')->nullable();          // ids / class / sample size

            $table->string('status', 20)->default('draft')->index();

            // Blind counting: the counter is NOT shown the expected figure.
            // Shown it, a tired person confirms it — and a count that only ever
            // agrees with the ledger has verified nothing. Default on.
            $table->boolean('blind')->default(true);

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->unsignedBigInteger('rejected_by')->nullable();
            $table->text('rejection_reason')->nullable();

            // The money the variance is worth, frozen at approval. Recomputing
            // it later from today's cost price would quietly rewrite history.
            $table->decimal('variance_value', 15, 2)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('inventory_count_lines', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('count_session_id')->index();
            $table->unsignedBigInteger('product_id')->index();
            // Null means "this product at this warehouse, wherever it sits" —
            // a site that doesn't model bins still needs to count.
            $table->unsignedBigInteger('location_id')->nullable();

            $table->decimal('system_qty', 15, 3)->default(0);          // at raise time
            $table->decimal('system_at_count', 15, 3)->nullable();     // at count time — the one that matters
            $table->decimal('counted_qty', 15, 3)->nullable();         // null = not walked yet
            // A second look at a line that disagreed. Most variances are counting
            // mistakes, not stock mistakes, so the recount is what gets posted.
            $table->decimal('recount_qty', 15, 3)->nullable();
            $table->decimal('variance', 15, 3)->default(0);

            $table->string('status', 20)->default('pending')->index();  // pending|matched|variance|recounted
            $table->unsignedBigInteger('counted_by')->nullable();
            $table->timestamp('counted_at')->nullable();

            // Evidence. Optional — a warehouse with no smartphones still has to
            // count — but recorded when offered, because a variance worth real
            // money is a conversation, and a photo ends it.
            $table->string('photo_path', 500)->nullable();
            $table->decimal('gps_lat', 10, 7)->nullable();
            $table->decimal('gps_lng', 10, 7)->nullable();
            $table->decimal('gps_accuracy', 8, 2)->nullable();
            $table->string('device', 120)->nullable();

            // The ledger row this line produced, so a movement can be traced back
            // to the person who counted it and the photo they took.
            $table->unsignedBigInteger('movement_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'count_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_count_lines');
        Schema::dropIfExists('inventory_count_sessions');
    }
};

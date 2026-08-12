<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase workforce lifecycle: step pointer, entry badge, and PPE issuance.
 *
 * Purchase keeps its normalised shape — medical / training / induction already
 * live in their own tables — so this adds only what has no home yet: where the
 * worker is in the 5-step wizard, and the badge the admin issues at step 5.
 * TPV's wide single-table design is deliberately NOT copied.
 *
 * purchase_worker_ppe_issues mirrors tpv_worker_ppe_issues column-for-column so
 * both modules post to the ONE inventory ledger the same way; only the worker FK
 * and the reference_type differ.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_workers')) {
            Schema::table('purchase_workers', function (Blueprint $t) {
                if (! Schema::hasColumn('purchase_workers', 'current_step')) {
                    // 1 profile · 2 medical · 3 training/induction · 4 PPE · 5 badge
                    $t->unsignedTinyInteger('current_step')->default(1)->after('status');
                }
                foreach ([
                    'badge_number'      => fn (Blueprint $b) => $b->string('badge_number', 60)->nullable(),
                    'qr_token'          => fn (Blueprint $b) => $b->string('qr_token', 64)->nullable(),
                    'badge_issued_at'   => fn (Blueprint $b) => $b->timestamp('badge_issued_at')->nullable(),
                    'badge_issued_by'   => fn (Blueprint $b) => $b->unsignedBigInteger('badge_issued_by')->nullable(),
                    'badge_valid_until' => fn (Blueprint $b) => $b->date('badge_valid_until')->nullable(),
                ] as $col => $add) {
                    if (! Schema::hasColumn('purchase_workers', $col)) {
                        $add($t);
                    }
                }
            });

            // Unique on the token only — a null badge is the normal state for most
            // rows, and SQLite/MySQL both allow repeated nulls in a unique index.
            if (Schema::hasColumn('purchase_workers', 'qr_token')) {
                try {
                    Schema::table('purchase_workers', function (Blueprint $t) {
                        $t->unique('qr_token', 'purchase_workers_qr_token_unique');
                    });
                } catch (\Throwable $e) { /* already present */ }
            }
        }

        if (! Schema::hasTable('purchase_worker_ppe_issues')) {
            Schema::create('purchase_worker_ppe_issues', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->index();
                $t->unsignedBigInteger('purchase_worker_id')->index();
                $t->unsignedBigInteger('issued_by')->nullable();
                // The Inventory product. Nullable so a written-off row survives a
                // product being removed, exactly as the TPV table behaves.
                $t->unsignedBigInteger('inventory_item_id')->nullable()->index();
                // Name snapshot: the issue record must still read correctly after
                // the product is renamed in Inventory.
                $t->string('item')->nullable();
                $t->decimal('qty', 12, 3)->default(0);
                $t->string('size', 40)->nullable();
                $t->date('issued_date')->nullable();
                $t->text('notes')->nullable();
                $t->string('status', 20)->default('issued');   // issued | returned | lost | damaged
                $t->decimal('returned_qty', 12, 3)->default(0);
                $t->timestamp('returned_at')->nullable();
                $t->unsignedBigInteger('returned_by')->nullable();
                $t->text('return_notes')->nullable();
                $t->timestamps();
                $t->softDeletes();

                $t->index(['tenant_id', 'purchase_worker_id', 'status'], 'pwppe_tenant_worker_status_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_worker_ppe_issues');

        if (Schema::hasTable('purchase_workers')) {
            Schema::table('purchase_workers', function (Blueprint $t) {
                foreach (['current_step', 'badge_number', 'qr_token', 'badge_issued_at', 'badge_issued_by', 'badge_valid_until'] as $col) {
                    if (Schema::hasColumn('purchase_workers', $col)) {
                        $t->dropColumn($col);
                    }
                }
            });
        }
    }
};

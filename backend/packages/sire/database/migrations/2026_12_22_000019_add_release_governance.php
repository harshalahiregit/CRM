<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — release governance.
 *
 * Adds the gate state, the override register, and the release-content taxonomy
 * the brief names (bugs / changes / improvements / security / performance).
 */
return new class extends Migration
{
    private function releaseColumns(): array
    {
        return [
            // Cached gate evaluation. The gates are RECOMPUTED, not stored as
            // truth — this is a cache so the dashboard can render 40 releases
            // without running 40 evaluations, and it carries the timestamp so a
            // stale reading is visible rather than assumed current.
            'gate_state'        => fn (Blueprint $t) => $t->json('gate_state')->nullable(),
            'gate_evaluated_at' => fn (Blueprint $t) => $t->dateTime('gate_evaluated_at')->nullable(),

            'approved_by'  => fn (Blueprint $t) => $t->unsignedBigInteger('approved_by')->nullable(),
            'approved_at'  => fn (Blueprint $t) => $t->dateTime('approved_at')->nullable(),
            'cancelled_by' => fn (Blueprint $t) => $t->unsignedBigInteger('cancelled_by')->nullable(),
            'cancelled_at' => fn (Blueprint $t) => $t->dateTime('cancelled_at')->nullable(),
            'cancel_reason' => fn (Blueprint $t) => $t->string('cancel_reason', 1000)->nullable(),

            /*
             * THE SEAM, and nothing more.
             *
             * There is no deployment platform in this CRM to integrate with:
             * deploys are a zip uploaded through the Plesk file manager, there is
             * no CI, no pipeline and no git checkout on the live server. SIRE
             * records that a release happened; it does not perform one, trigger
             * one, or hold any credential that could.
             *
             * This column exists so that when a real pipeline appears, its build
             * or tag identifier has an obvious home and nobody is tempted to grow
             * a deployment tool inside an issue tracker.
             */
            'deployment_ref' => fn (Blueprint $t) => $t->string('deployment_ref', 191)->nullable(),
        ];
    }

    private function reportColumns(): array
    {
        return [
            // Per-issue override of the category's release class.
            'release_class' => fn (Blueprint $t) => $t->string('release_class', 16)->nullable(),

            // Drives the regression-testing gate. Set at triage for regressions,
            // criticals and P1s; adjustable by hand.
            'requires_regression_test' => fn (Blueprint $t) => $t->boolean('requires_regression_test')->default(false),
            'regression_tested_at'     => fn (Blueprint $t) => $t->dateTime('regression_tested_at')->nullable(),
            'regression_tested_by'     => fn (Blueprint $t) => $t->unsignedBigInteger('regression_tested_by')->nullable(),
        ];
    }

    public function up(): void
    {
        if (Schema::hasTable('sire_releases')) {
            foreach ($this->releaseColumns() as $name => $add) {
                if (! Schema::hasColumn('sire_releases', $name)) {
                    Schema::table('sire_releases', fn (Blueprint $t) => $add($t));
                }
            }

            /*
             * Phase 2 used planned | in_progress | released | rolled_back.
             * Governance replaces that with the brief's lifecycle. Mapped rather
             * than dropped so nothing is stranded in a status the code no longer
             * understands — a release stuck in an unknown state renders as neither
             * ready nor blocked and quietly disappears from every list.
             *
             * planned / in_progress → blocked. Blocked is the honest default: the
             * gates have not been evaluated yet, so the release is not ready.
             */
            DB::table('sire_releases')->whereIn('status', ['planned', 'in_progress'])->update(['status' => 'blocked']);
        }

        if (Schema::hasTable('sire_reports')) {
            foreach ($this->reportColumns() as $name => $add) {
                if (! Schema::hasColumn('sire_reports', $name)) {
                    Schema::table('sire_reports', fn (Blueprint $t) => $add($t));
                }
            }

            Schema::table('sire_reports', function (Blueprint $table) {
                $table->index(['tenant_id', 'release_class'], 'sire_rep_release_class_idx');
                // Drives the regression gate; without it the gate scans the register.
                $table->index(['tenant_id', 'requires_regression_test', 'regression_tested_at'], 'sire_rep_regr_gate_idx');
            });
        }

        // The tenant's default mapping from issue type to release class.
        if (Schema::hasTable('sire_report_categories') && ! Schema::hasColumn('sire_report_categories', 'release_class')) {
            Schema::table('sire_report_categories', fn (Blueprint $t) => $t->string('release_class', 16)->nullable());
        }

        if (! Schema::hasTable('sire_release_overrides')) {
            Schema::create('sire_release_overrides', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('release_id');

                // Which gates were bypassed, and what they said at the time. The
                // snapshot matters more than the list: six months later, "we
                // overrode the critical gate" is worth little next to "we overrode
                // it while three critical issues were open".
                $table->json('overridden_gates');
                $table->json('gate_snapshot');

                $table->string('reason', 64);        // hotfix | customer_commitment | regulatory | other
                $table->text('justification');       // free text, required

                $table->unsignedBigInteger('authorized_by');
                $table->dateTime('authorized_at');

                // An override applies to ONE release attempt. Revoked when the
                // release is cancelled or the gates are genuinely satisfied.
                $table->dateTime('revoked_at')->nullable();
                $table->unsignedBigInteger('revoked_by')->nullable();

                $table->timestamps();

                $table->index('tenant_id');
                $table->index(['tenant_id', 'release_id'], 'sire_ovr_release_idx');
                $table->index(['tenant_id', 'authorized_at'], 'sire_ovr_when_idx');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_release_overrides');

        if (Schema::hasTable('sire_reports')) {
            Schema::table('sire_reports', function (Blueprint $table) {
                $table->dropIndex('sire_rep_release_class_idx');
                $table->dropIndex('sire_rep_regr_gate_idx');
                $table->dropColumn(array_keys($this->reportColumns()));
            });
        }
        if (Schema::hasTable('sire_report_categories') && Schema::hasColumn('sire_report_categories', 'release_class')) {
            Schema::table('sire_report_categories', fn (Blueprint $t) => $t->dropColumn('release_class'));
        }
        if (Schema::hasTable('sire_releases')) {
            Schema::table('sire_releases', fn (Blueprint $t) => $t->dropColumn(array_keys($this->releaseColumns())));
        }
    }
};

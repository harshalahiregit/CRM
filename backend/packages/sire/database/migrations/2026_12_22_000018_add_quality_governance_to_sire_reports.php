<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SIRE Phase 2 — quality and governance columns. Additive, column-guarded. */
return new class extends Migration
{
    private function columns(): array
    {
        return [
            // --- which workflow this row runs (Phase 2) ----------------------
            'workflow_track' => fn (Blueprint $t) => $t->string('workflow_track', 16)->default('defect'),

            // --- change-request fields ---------------------------------------
            'business_justification' => fn (Blueprint $t) => $t->text('business_justification')->nullable(),
            'impact_summary'         => fn (Blueprint $t) => $t->text('impact_summary')->nullable(),
            'change_risk'            => fn (Blueprint $t) => $t->string('change_risk', 16)->nullable(),

            // --- version mapping. Four roles, three of them singular ---------
            'detected_version_id' => fn (Blueprint $t) => $t->unsignedBigInteger('detected_version_id')->nullable(),
            'fixed_version_id'    => fn (Blueprint $t) => $t->unsignedBigInteger('fixed_version_id')->nullable(),
            'released_version_id' => fn (Blueprint $t) => $t->unsignedBigInteger('released_version_id')->nullable(),
            // An issue can affect several versions at once; ids, display only.
            // Promote to a link table the day you need to FILTER by it.
            'affected_versions'   => fn (Blueprint $t) => $t->json('affected_versions')->nullable(),

            // --- regression ---------------------------------------------------
            'is_regression'          => fn (Blueprint $t) => $t->boolean('is_regression')->default(false),
            'regression_of_id'       => fn (Blueprint $t) => $t->unsignedBigInteger('regression_of_id')->nullable(),
            'caused_by_release_id'   => fn (Blueprint $t) => $t->unsignedBigInteger('caused_by_release_id')->nullable(),
            'regression_notes'       => fn (Blueprint $t) => $t->text('regression_notes')->nullable(),

            // --- recurrence ---------------------------------------------------
            'recurrence_group_id' => fn (Blueprint $t) => $t->unsignedBigInteger('recurrence_group_id')->nullable(),

            // --- release notes -------------------------------------------------
            // What a customer should be told. Falls back to the title when blank,
            // because an internal title like "null deref in LeadPolicy::view" is
            // not a release note.
            'user_facing_summary' => fn (Blueprint $t) => $t->string('user_facing_summary', 500)->nullable(),
            'include_in_release_notes' => fn (Blueprint $t) => $t->boolean('include_in_release_notes')->default(true),
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('sire_reports')) {
            return;
        }

        foreach ($this->columns() as $name => $add) {
            if (Schema::hasColumn('sire_reports', $name)) {
                continue;
            }
            Schema::table('sire_reports', function (Blueprint $table) use ($add) {
                $add($table);
            });
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            // Short explicit names: MySQL caps identifiers at 64 characters and the
            // SQLite suite will not catch an overrun.
            $table->index(['tenant_id', 'workflow_track', 'status'], 'sire_rep_track_status_idx');
            $table->index(['tenant_id', 'released_version_id'], 'sire_rep_released_ver_idx');
            $table->index(['tenant_id', 'caused_by_release_id'], 'sire_rep_caused_by_idx');
            $table->index(['tenant_id', 'is_regression'], 'sire_rep_regression_idx');
            $table->index(['tenant_id', 'recurrence_group_id'], 'sire_rep_recurrence_idx');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sire_reports')) {
            return;
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            foreach (['sire_rep_track_status_idx', 'sire_rep_released_ver_idx', 'sire_rep_caused_by_idx',
                      'sire_rep_regression_idx', 'sire_rep_recurrence_idx'] as $index) {
                $table->dropIndex($index);
            }
            $table->dropColumn(array_keys($this->columns()));
        });
    }
};

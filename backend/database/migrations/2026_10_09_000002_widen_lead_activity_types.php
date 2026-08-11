<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen `lead_activities.type` for the new profile actions.
 *
 * The column was created as an enum, which SQLite implements as a CHECK
 * constraint — so logging an attachment upload or a sent email failed the insert
 * outright rather than being ignored. The lost/junk reason entries need slots too.
 *
 * Rebuilt as a plain string with an index instead of a wider enum: every new kind
 * of activity would otherwise need another migration, and the set of things that
 * can happen to a lead is open-ended. Values stay validated in application code.
 */
return new class extends Migration
{
    private const TYPES = [
        'created', 'updated', 'status_changed', 'assigned', 'note_added', 'converted',
        'lost', 'junk', 'restored', 'contact', 'proposal_sent', 'questionnaire_submitted',
        'attachment_added', 'attachment_removed', 'email_sent',
        'lost_reason', 'junk_reason', 'appointment_scheduled', 'appointment_completed',
    ];

    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // A CHECK constraint is baked into the CREATE TABLE, so `change()` can't
            // lift it — the table has to be rebuilt. Done by hand to keep the data.
            $this->rebuildSqliteTable();

            return;
        }

        // MySQL/MariaDB: a plain modify is enough.
        DB::statement("ALTER TABLE lead_activities MODIFY type VARCHAR(60) NOT NULL");
    }

    public function down(): void
    {
        // Deliberately not restoring the narrow CHECK: rows using the new types
        // would violate it and the migration would fail half-way.
    }

    private function rebuildSqliteTable(): void
    {
        // A half-finished earlier attempt would leave this behind and the rename
        // would then fail with "table already exists".
        Schema::dropIfExists('lead_activities_old');

        Schema::rename('lead_activities', 'lead_activities_old');

        // SQLite carries INDEX NAMES across a table rename, so the renamed table
        // still owns `lead_activities_type_index` et al. Creating the new table
        // below would collide on those names, so release them first.
        foreach (DB::select(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name NOT LIKE 'sqlite_%'",
            ['lead_activities_old'],
        ) as $index) {
            DB::statement('DROP INDEX IF EXISTS "' . $index->name . '"');
        }

        Schema::create('lead_activities', function ($table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('type', 60);
            $table->text('description')->nullable();
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('type');
            $table->index(['tenant_id', 'lead_id']);
        });

        $columns = Schema::getColumnListing('lead_activities_old');
        $shared = array_values(array_intersect(
            ['id', 'tenant_id', 'lead_id', 'type', 'description', 'old_value', 'new_value', 'performed_by', 'created_at', 'updated_at'],
            $columns,
        ));
        $list = implode(', ', $shared);

        DB::statement("INSERT INTO lead_activities ({$list}) SELECT {$list} FROM lead_activities_old");
        Schema::drop('lead_activities_old');
    }
};

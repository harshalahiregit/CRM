<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two things notes were missing, applied to BOTH notes tables.
 *
 * There are two by design-drift, not by design: `client_notes` is rich
 * (type, visibility, priority, deadline, reminder) but customer-only, while the
 * shared `notes` table is polymorphic but plain. The shared table's own
 * docblock records that merging them was deliberately deferred.
 *
 * Rather than add a third, this brings the same two capabilities to each:
 *
 *  contacted_at  WHEN the conversation happened, as distinct from when the note
 *                was typed. Those differ — someone logs Friday's call on Monday
 *                — and "when did we last speak to this customer" is unanswerable
 *                from created_at. The legacy CRM had exactly this field
 *                (tblnotes.date_contacted) and the port dropped it.
 *
 *  is_pinned     A note that matters permanently — payment terms agreed on a
 *                call, a person's preference — otherwise sinks under routine
 *                ones and is found only by scrolling.
 *
 * The shared table additionally gains `type` and `visibility` so a note on a
 * vendor can be structured the way a note on a customer already is, and so a
 * later consolidation has one less difference to reconcile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            $table->dateTime('contacted_at')->nullable()->after('content');
            $table->boolean('is_pinned')->default(false)->after('visibility');
            $table->index(['tenant_id', 'client_id', 'is_pinned'], 'cn_tenant_client_pinned_idx');
        });

        if (Schema::hasTable('notes')) {
            Schema::table('notes', function (Blueprint $table) {
                if (! Schema::hasColumn('notes', 'type')) {
                    $table->string('type', 30)->nullable()->after('content');
                }
                if (! Schema::hasColumn('notes', 'visibility')) {
                    // Same three levels client_notes uses, so the two agree.
                    $table->string('visibility', 10)->default('team')->after('type');
                }
                if (! Schema::hasColumn('notes', 'contacted_at')) {
                    $table->dateTime('contacted_at')->nullable()->after('visibility');
                }
                if (! Schema::hasColumn('notes', 'is_pinned')) {
                    $table->boolean('is_pinned')->default(false)->after('contacted_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::table('client_notes', function (Blueprint $table) {
            $table->dropIndex('cn_tenant_client_pinned_idx');
            $table->dropColumn(['contacted_at', 'is_pinned']);
        });

        if (Schema::hasTable('notes')) {
            Schema::table('notes', function (Blueprint $table) {
                $table->dropColumn(['type', 'visibility', 'contacted_at', 'is_pinned']);
            });
        }
    }
};

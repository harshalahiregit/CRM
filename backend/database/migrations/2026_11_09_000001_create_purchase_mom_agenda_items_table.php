<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase meeting agenda builder (Meeting.docx §3) — a structured agenda
 * (#/item/owner/duration/priority) rather than a free-text field, and the anchor
 * for the structured MOM (§7: Agenda → Discussion → Decision → Action). Purchase-
 * owned; never shares the shared/TPV meeting_agenda_items. No DB foreign keys.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_mom_agenda_items')) {
            return;
        }

        Schema::create('purchase_mom_agenda_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id')->index();
            $table->string('item');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('owner_participant_id')->nullable()->index();
            $table->string('owner_names', 300)->nullable();
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('priority', 20)->nullable();
            // Structured MOM (§7): what was discussed / decided under this item.
            $table->text('discussion')->nullable();
            $table->text('decision')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            // Explicit short name — under MySQL's 64-char identifier limit.
            $table->index(['tenant_id', 'purchase_kickoff_meeting_id'], 'pmagenda_tenant_meeting_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_mom_agenda_items');
    }
};

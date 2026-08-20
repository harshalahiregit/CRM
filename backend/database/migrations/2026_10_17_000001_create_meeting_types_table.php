<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-editable meeting types + agenda templates (Meeting.docx — admin
 * Types/Templates settings).
 *
 * Deliberately a LAYER over config/meetings.php, not a replacement: the config
 * types stay the built-in baseline every tenant gets, and a row here either adds
 * a brand-new type or overrides a built-in one's label / template for that tenant.
 * MeetingTypeCatalog does the merge. So an empty table = exactly today's behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_types', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The stable key used on kickoff_meetings.meeting_type.
            $table->string('key', 60);
            $table->string('label', 120);
            // Agenda template: array of { item, duration_minutes?, priority? }.
            $table->json('templates')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            // One row per key per tenant — a tenant cannot define the same key twice.
            $table->unique(['tenant_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_types');
    }
};

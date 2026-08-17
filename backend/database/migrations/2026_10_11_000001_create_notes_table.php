<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic notes, attachable to anything.
 *
 * Every notes feature so far bound itself to one owner — project_notes.project_id,
 * client_notes, hr_candidate_notes — so a vendor had nowhere to keep a note and the
 * Notes tab had nothing to read. Rather than adding vendor_notes as a fifth
 * single-purpose table, this follows the shape `reminders` already uses in this
 * codebase: one polymorphic table, one engine, any subject.
 *
 * The existing per-module notes tables are deliberately left alone — they carry
 * module-specific columns (visibility, assignment, attachments) and migrating them
 * is a separate decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('notable_type');
            $table->unsignedBigInteger('notable_id');

            $table->string('title');
            // Rich text from the same editor the rest of the app uses; sanitised
            // through HtmlSanitizer before it ever reaches this column.
            $table->text('content')->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['notable_type', 'notable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notes');
    }
};

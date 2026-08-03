<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complete the project Notes options from the doc: assign a note to someone,
 * set a reminder on it, and attach files. The first two are columns on the note;
 * attachments get their own table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_notes')) {
            Schema::table('project_notes', function (Blueprint $table) {
                if (! Schema::hasColumn('project_notes', 'assigned_to')) {
                    $table->foreignId('assigned_to')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
                }
                if (! Schema::hasColumn('project_notes', 'remind_at')) {
                    $table->timestamp('remind_at')->nullable()->after('assigned_to');
                }
                if (! Schema::hasColumn('project_notes', 'reminded')) {
                    $table->boolean('reminded')->default(false)->after('remind_at');
                }
            });
        }

        if (! Schema::hasTable('project_note_attachments')) {
            Schema::create('project_note_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('project_note_id')->constrained('project_notes')->cascadeOnDelete();
                $table->string('original_name');
                $table->string('path');
                $table->unsignedBigInteger('size')->default(0);
                $table->string('mime')->nullable();
                $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->index(['tenant_id', 'project_note_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_note_attachments');
        if (Schema::hasTable('project_notes')) {
            Schema::table('project_notes', function (Blueprint $table) {
                foreach (['assigned_to', 'remind_at', 'reminded'] as $col) {
                    if (Schema::hasColumn('project_notes', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};

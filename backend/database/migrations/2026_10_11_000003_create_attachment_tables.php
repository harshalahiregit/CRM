<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A general file store with folders, attachable to anything.
 *
 * Distinct from the existing document stores on purpose. `vendor_documents` is a
 * statutory CHECKLIST — a fixed set of named types the vendor must supply, each
 * reviewed and approved. This is the opposite: free-form files the team files
 * away in folders of their own making, with no checklist and no approval.
 *
 * Polymorphic like `reminders` and `notes`, so the next module that needs a file
 * area plugs in instead of adding a third single-purpose table.
 *
 * Bytes always live on OUR disk. Google Drive and OneDrive are import SOURCES,
 * not storage backends: the picker hands back the file, we store it, and the
 * origin is recorded in `source` so the trail back is not lost. That is the
 * pattern the task attachments already use (see lib/googleDrivePicker.js) — a
 * link to someone's personal Drive would rot the moment they move or unshare it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attachment_folders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');

            // Self-referential: null is the root of that subject's tree.
            $table->foreignId('parent_id')->nullable()->constrained('attachment_folders')->cascadeOnDelete();
            $table->string('name');

            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('parent_id');
            // One name per level per subject — the rule a file manager enforces.
            $table->unique(['tenant_id', 'attachable_type', 'attachable_id', 'parent_id', 'name'], 'attachment_folders_unique_name');
        });

        Schema::create('attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('attachable_type');
            $table->unsignedBigInteger('attachable_id');

            // Null = sitting at the root rather than inside a folder. Deleting a
            // folder takes its files with it, which is what a file manager does.
            $table->foreignId('folder_id')->nullable()->constrained('attachment_folders')->cascadeOnDelete();

            $table->string('name');                   // display name, renameable
            $table->string('disk', 40)->default('attachments');
            $table->string('path');                   // path on the disk
            $table->string('mime', 190)->nullable();
            $table->unsignedBigInteger('size')->default(0);

            // Where it came from: upload | google_drive | onedrive. The bytes are
            // ours either way; this only records the origin.
            $table->string('source', 20)->default('upload');
            $table->string('source_ref')->nullable();  // the provider's file id

            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['attachable_type', 'attachable_id']);
            $table->index('folder_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('attachment_folders');
    }
};

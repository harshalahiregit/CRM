<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Let a task file belong to a COMMENT, not just the task (owner: Shivam).
 *
 * The task detail's comment box can now attach files (like the reference view).
 * Rather than a separate comment_attachments table, a comment's files ARE task
 * files with comment_id set — so they reuse the exact same storage, download and
 * cleanup path. task-level files keep comment_id null; the Files card lists only
 * those, while each comment renders its own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->foreignId('comment_id')->nullable()->after('task_id')
                ->constrained('task_comments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('task_files', function (Blueprint $table) {
            $table->dropConstrainedForeignId('comment_id');
        });
    }
};

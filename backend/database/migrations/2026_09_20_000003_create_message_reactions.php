<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emoji reactions on any message-like row (owner: Shivam) — task comments,
 * ticket replies, project-discussion comments. Polymorphic via subject_type +
 * subject_id so one engine serves every thread. One row per (subject, user,
 * emoji): a user can add several different emojis, but each only once.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('message_reactions')) {
            return;
        }
        Schema::create('message_reactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $t->string('subject_type', 40);          // task_comment | ticket_reply | discussion_comment
            $t->unsignedBigInteger('subject_id');
            $t->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $t->string('emoji', 16);
            $t->timestamps();

            $t->unique(['subject_type', 'subject_id', 'user_id', 'emoji']);
            $t->index(['tenant_id', 'subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_reactions');
    }
};

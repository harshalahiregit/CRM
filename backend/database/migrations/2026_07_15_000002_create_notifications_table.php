<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * In-app notifications. Deliberately a plain table rather than Laravel's
 * polymorphic notifications table: every row is addressed to one user in one
 * tenant and renders as title + message + a deep link, which is all the bell
 * needs. Modules emit through NotificationService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 60);            // e.g. ticket.assigned
            $table->string('title');
            $table->string('message', 500)->nullable();
            $table->string('link')->nullable();    // in-app deep link
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell's query: this user's rows, unread first, newest first.
            $table->index(['user_id', 'read_at']);
            $table->index(['tenant_id', 'user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

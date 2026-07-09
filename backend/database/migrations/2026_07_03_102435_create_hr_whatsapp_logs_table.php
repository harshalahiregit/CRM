<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('hr_whatsapp_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->foreignId('candidate_id')->nullable()->constrained('hr_candidates')->onDelete('set null');
            $table->string('to_number');
            $table->string('message_sid')->nullable()->index();
            $table->string('event_type'); // interview_scheduled, status_update, application_received, etc.
            $table->text('message');
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed', 'undelivered', 'read'])->default('queued');
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();
            
            $table->index(['candidate_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hr_whatsapp_logs');
    }
};

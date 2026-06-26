<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_interview_rounds', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('round_name');                // "HR Telephonic", "Technical L1"
            $table->string('interviewer_name')->nullable();
            $table->unsignedBigInteger('interviewer_id')->nullable();
            $table->dateTime('scheduled_at')->nullable();
            $table->string('meet_link')->nullable();
            $table->enum('status', ['Scheduled','Completed','Cancelled','Rescheduled'])->default('Scheduled');
            $table->enum('result', ['Pending','Passed','Failed'])->default('Pending');
            $table->text('notes')->nullable();
            $table->boolean('email_sent_candidate')->default(false);
            $table->boolean('email_sent_interviewer')->default(false);
            $table->boolean('whatsapp_sent')->default(false);
            $table->boolean('calendar_event_created')->default(false);
            $table->timestamps();

            $table->foreign('candidate_id')->references('id')->on('hr_candidates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_interview_rounds');
    }
};

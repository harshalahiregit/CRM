<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The `result` column was created as enum('Pending','Passed','Failed') — a DB
 * CHECK constraint. The enterprise interview workflow adds 'On Hold' and
 * 'Next Round' outcomes, which the CHECK rejected. Relax it to a plain string
 * (values are validated in RecordInterviewFeedbackRequest) so the pipeline can
 * record every feedback result.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->string('result')->default('Pending')->change();
        });
    }

    public function down(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->enum('result', ['Pending', 'Passed', 'Failed'])->default('Pending')->change();
        });
    }
};

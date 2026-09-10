<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_work_cycles')) {
            return;
        }

        Schema::create('sire_work_cycles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');            // NOT NULL by design
            $table->unsignedBigInteger('report_id');            // logical -> sire_reports

            $table->string('phase', 16);                        // development | qa
            $table->unsignedSmallInteger('cycle_no');           // 1, 2, 3 ... per phase
            $table->unsignedBigInteger('actor_id')->nullable(); // who ran this round

            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('outcome', 16)->nullable();          // submitted | passed | failed | abandoned
            $table->text('notes')->nullable();                  // snapshot of what this round said

            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'report_id', 'phase'], 'sire_wc_report_phase_idx');
            $table->index(['tenant_id', 'phase', 'outcome'], 'sire_wc_phase_outcome_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_work_cycles');
    }
};

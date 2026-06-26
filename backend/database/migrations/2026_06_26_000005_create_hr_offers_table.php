<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_offers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('candidate_id');
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('position');
            $table->string('department');
            $table->decimal('offered_ctc', 14, 2);
            $table->date('joining_date');
            $table->string('probation_period')->default('3 months');
            $table->string('notice_period')->default('1 month');
            $table->date('validity_date')->nullable();
            $table->enum('status', ['Generated','Sent','Accepted','Rejected'])->default('Generated');
            $table->string('letter_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->foreign('candidate_id')->references('id')->on('hr_candidates')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_offers');
    }
};

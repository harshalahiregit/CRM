<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('job_posting_id')->nullable();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('location')->nullable();
            $table->string('current_company')->nullable();
            $table->decimal('experience_years', 4, 1)->default(0);
            $table->string('source')->default('Direct');
            $table->enum('stage', ['Applied','Screening','Assessment','Interview','Offer','Hired','Rejected'])->default('Applied');
            $table->string('linkedin_url')->nullable();
            $table->json('linkedin_data')->nullable();   // extracted profile JSON
            $table->string('resume_path')->nullable();
            $table->integer('ai_score')->nullable();     // 0-100
            $table->json('skills')->nullable();          // ["React","Node.js"]
            $table->text('notes')->nullable();
            $table->enum('final_decision', ['Pending','Selected','Hold','Rejected'])->default('Pending');
            $table->timestamps();

            $table->foreign('job_posting_id')->references('id')->on('hr_job_postings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_candidates');
    }
};

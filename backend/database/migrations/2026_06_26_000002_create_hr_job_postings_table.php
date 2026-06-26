<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_job_postings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('title');
            $table->string('department');
            $table->string('location');
            $table->enum('job_type', ['Full-time', 'Part-time', 'Contract', 'Internship', 'Remote'])->default('Full-time');
            $table->enum('posting_type', ['Internal', 'External', 'Both'])->default('Both');
            $table->text('description')->nullable();
            $table->text('requirements')->nullable();
            $table->decimal('salary_from', 12, 2)->nullable();
            $table->decimal('salary_to', 12, 2)->nullable();
            $table->integer('number_of_openings')->default(1);
            $table->date('closing_date')->nullable();
            $table->enum('status', ['Active', 'Draft', 'Closed'])->default('Active');
            $table->json('sources')->nullable(); // ["LinkedIn","Career Page"]
            $table->integer('applicant_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_job_postings');
    }
};

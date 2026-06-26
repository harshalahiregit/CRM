<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_manpower_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('requested_by')->nullable();
            $table->string('department');
            $table->string('position_title');
            $table->integer('number_of_posts')->default(1);
            $table->date('required_by_date')->nullable();
            $table->enum('job_type', ['Full-time', 'Part-time', 'Contract', 'Internship'])->default('Full-time');
            $table->enum('priority', ['Low', 'Medium', 'High'])->default('Medium');
            $table->text('justification')->nullable();
            $table->enum('status', ['Pending', 'Approved', 'Rejected'])->default('Pending');
            $table->timestamps();

            $table->foreign('requested_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_manpower_requests');
    }
};

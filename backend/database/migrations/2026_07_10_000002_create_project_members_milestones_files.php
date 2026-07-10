<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects module — members, milestones, files (owner: Shivam). SQLite-safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['project_id', 'user_id']);
            $table->index('tenant_id');
        });

        Schema::create('project_milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->date('due_date');
            $table->date('start_date')->nullable();
            $table->string('color', 9)->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('hide_from_customer')->default(false);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('project_id');
        });

        Schema::create('project_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('original_name');
            $table->string('file_path');
            $table->boolean('visible_to_customer')->default(false);
            $table->foreignId('uploaded_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_files');
        Schema::dropIfExists('project_milestones');
        Schema::dropIfExists('project_members');
    }
};

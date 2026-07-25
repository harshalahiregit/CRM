<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projects module — core table (owner: Shivam).
 *
 * SQLite-safe: enum-like columns are stored as strings (values enforced by
 * FormRequests) — same choice as Helpdesk's priority, and it avoids the
 * CHECK-constraint rebuild SQLite can't do when a value is later added.
 *
 * customer_id has NO foreign key — Customer belongs to Zafar's module; the link
 * resolves at runtime through CustomerServiceContract (mocked, same as Helpdesk).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            $table->string('name');
            $table->text('description')->nullable();                 // HTML from rich editor
            $table->string('status', 20)->default('not_started');    // not_started|in_progress|on_hold|finished|cancelled

            $table->unsignedBigInteger('customer_id')->nullable();   // Zafar's module — no FK

            $table->string('billing_type', 20)->default('fixed');    // fixed|project_hours|task_hours
            $table->decimal('project_cost', 15, 2)->nullable();      // when billing_type=fixed
            $table->decimal('rate_per_hour', 15, 2)->nullable();     // when billing_type=project_hours

            $table->date('start_date');
            $table->date('deadline')->nullable();

            $table->unsignedTinyInteger('progress')->default(0);     // 0-100 manual
            $table->boolean('progress_from_tasks')->default(true);   // auto-calc from task completion
            $table->decimal('estimated_hours', 15, 2)->nullable();

            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('date_finished')->nullable();          // set when status → finished

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('customer_id');
            $table->index('created_by');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable checklist templates (owner: Shivam) — e.g. "Code Review Checklist"
 * applied to any task needing one.
 *
 * `items` is a JSON array of strings rather than a child table: a template is
 * always read and written whole, and copying it onto a task flattens it into
 * task_checklist_items anyway, so per-item rows would buy nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 120);
            $table->json('items');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_templates');
    }
};

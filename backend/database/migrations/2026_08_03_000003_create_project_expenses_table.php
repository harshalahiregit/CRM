<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project-scoped expenses (the doc's "Expenses" / "Missing — Expenses" item).
 * A lightweight, self-contained ledger on the project: title, category, amount,
 * date, optional note, and a billable flag. Tenant-scoped and soft-deletable.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_expenses')) {
            return;
        }

        Schema::create('project_expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('expense_date');
            $table->text('note')->nullable();
            $table->boolean('billable')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_expenses');
    }
};

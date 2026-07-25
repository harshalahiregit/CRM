<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts module — Phase 4: Budgets.
 *
 * A budget belongs to a financial year; its lines hold a target amount per ledger
 * per month (monthly granularity, mirroring the proven legacy model — annual/
 * quarterly entry just expands to 12 monthly rows). Budget-vs-Actual reads targets
 * here and actuals from the ledger (never stored).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('financial_year_id')->constrained('acc_financial_years')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'financial_year_id']);
        });

        Schema::create('acc_budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('budget_id')->constrained('acc_budgets')->cascadeOnDelete();
            $table->foreignId('ledger_id')->constrained('acc_ledgers')->cascadeOnDelete();
            $table->unsignedTinyInteger('month');   // 1-12 (calendar month)
            $table->unsignedSmallInteger('year');
            $table->decimal('amount', 15, 2)->default(0);
            $table->timestamps();

            $table->unique(['budget_id', 'ledger_id', 'month', 'year'], 'acc_budget_line_unique');
            $table->index(['tenant_id', 'budget_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_budget_lines');
        Schema::dropIfExists('acc_budgets');
    }
};

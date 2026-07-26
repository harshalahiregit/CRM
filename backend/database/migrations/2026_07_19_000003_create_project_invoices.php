<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project invoices (owner: Shivam).
 *
 * "Invoice Project" turns a project into a billable amount by its billing type.
 * There is no Finance/Invoices module in this CRM yet (that's Zafar's territory),
 * so rather than fake a full accounting document this stores a self-contained
 * project invoice DRAFT — number, computed amount, and the line items it was
 * built from — that the finance module can later adopt or supersede. Deliberately
 * minimal: no tax, no PDF, no payment tracking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->string('billing_type', 20);          // snapshot of the project's type at generation
            $table->decimal('amount', 15, 2)->default(0);
            $table->string('currency', 8)->default('INR');
            $table->string('status', 20)->default('draft');
            $table->json('line_items')->nullable();       // [{description, qty, rate, amount}]
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['tenant_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_invoices');
    }
};

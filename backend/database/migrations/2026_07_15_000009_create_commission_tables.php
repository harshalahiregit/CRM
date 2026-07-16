<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->enum('basis', ['product', 'executive', 'region', 'target'])->default('executive');
            $table->enum('calc_type', ['percentage', 'flat'])->default('percentage');
            $table->decimal('rate', 12, 2)->default(0);
            $table->json('conditions')->nullable();
            $table->enum('applies_to', ['won_deal', 'paid_invoice', 'both'])->default('paid_invoice');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('is_active');
        });

        Schema::create('commission_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('rule_id')->constrained('commission_rules')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source_type');   // FQCN (invoice / lead)
            $table->unsignedBigInteger('source_id');
            $table->decimal('base_amount', 15, 2)->default(0);
            $table->decimal('commission_amount', 15, 2)->default(0);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->enum('payout_status', ['unpaid', 'paid'])->default('unpaid');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Idempotency: one entry per (rule, source) — prevents double
            // generation when an invoice is re-saved / partially paid repeatedly.
            $table->unique(['rule_id', 'source_type', 'source_id']);
            $table->index('tenant_id');
            $table->index('staff_id');
            $table->index('status');
            $table->index('payout_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commission_entries');
        Schema::dropIfExists('commission_rules');
    }
};

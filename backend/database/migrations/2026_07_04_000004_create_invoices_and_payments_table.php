<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number')->unique();          // INV-2026-001
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('estimate_id')->nullable();   // if converted from estimate
            $table->date('date');
            $table->date('due_date');
            $table->string('currency', 3)->default('INR');
            $table->foreignId('sale_agent')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('discount_type', ['none', 'before_tax', 'after_tax'])->default('none');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('paid', 12, 2)->default(0);
            $table->decimal('balance', 12, 2)->default(0);
            $table->boolean('recurring')->default(false);
            $table->string('recur_interval')->nullable();
            $table->string('recur_type')->nullable();
            $table->unsignedInteger('cycles')->default(0);
            $table->json('allowed_payment_modes')->nullable();
            $table->boolean('cancel_overdue_reminders')->default(false);
            $table->enum('status', ['Draft', 'Unpaid', 'Partially Paid', 'Paid', 'Overdue', 'Cancelled'])->default('Draft');
            $table->text('adminnote')->nullable();
            $table->text('clientnote')->nullable();
            $table->text('terms')->nullable();
            $table->text('tags')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('client_id');
            $table->index('due_date');
        });

        Schema::create('sales_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->date('date');
            $table->decimal('amount', 12, 2);
            $table->string('mode')->default('Bank Transfer'); // Bank Transfer, Cash, Cheque, Stripe, Razorpay, UPI, PayPal
            $table->string('transaction_id')->nullable();
            $table->string('gateway')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('invoice_id');
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_payments');
        Schema::dropIfExists('sales_invoices');
    }
};

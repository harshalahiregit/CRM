<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('INR');
            $table->enum('status', ['active', 'paid', 'expired', 'cancelled'])->default('active');
            $table->string('token', 64)->unique();
            $table->dateTime('expiry_date')->nullable();
            $table->string('client_email')->nullable();
            $table->string('client_name')->nullable();
            $table->text('description')->nullable();
            $table->string('payment_gateway')->nullable();
            $table->string('transaction_id')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });

        Schema::create('retainer_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->string('number')->unique();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->date('billing_period_start');
            $table->date('billing_period_end');
            $table->enum('status', ['Draft', 'Sent', 'Paid', 'Overdue'])->default('Draft');
            $table->enum('retainer_type', ['monthly', 'quarterly', 'yearly'])->default('monthly');
            $table->boolean('auto_create')->default(false);
            $table->date('next_billing_date')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retainer_invoices');
        Schema::dropIfExists('payment_links');
    }
};

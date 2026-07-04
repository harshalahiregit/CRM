<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number')->unique();          // CN-001
            $table->unsignedBigInteger('client_id')->nullable();
            $table->foreignId('invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->date('date');
            $table->string('currency', 3)->default('INR');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('remaining', 12, 2)->default(0); // amount not yet applied
            $table->text('reason')->nullable();
            $table->text('adminnote')->nullable();
            $table->text('clientnote')->nullable();
            $table->text('terms')->nullable();
            $table->enum('status', ['Open', 'Partially Applied', 'Fully Applied', 'Void'])->default('Open');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('client_id');
        });

        Schema::create('credit_note_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('sales_invoices')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->date('date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('credit_note_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('credit_notes')->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('mode')->default('Bank Transfer');
            $table->string('transaction_id')->nullable();
            $table->text('note')->nullable();
            $table->date('date');
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
        });

        Schema::create('delivery_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('number')->unique();          // DN-001
            $table->foreignId('invoice_id')->nullable()->constrained('sales_invoices')->nullOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->date('delivery_date');
            $table->enum('status', ['Draft', 'Sent', 'Delivered', 'Cancelled'])->default('Draft');
            $table->text('shipping_address')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_state')->nullable();
            $table->string('shipping_country')->nullable();
            $table->string('shipping_zip')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_notes');
        Schema::dropIfExists('credit_note_refunds');
        Schema::dropIfExists('credit_note_applications');
        Schema::dropIfExists('credit_notes');
    }
};

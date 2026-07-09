<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Shared line_items table for proposals, estimates, invoices, credit_notes
        Schema::create('sales_line_items', function (Blueprint $table) {
            $table->id();
            $table->string('lineable_type');  // e.g. App\Models\Sales\Proposal
            $table->unsignedBigInteger('lineable_id');
            $table->foreignId('item_id')->nullable()->constrained('sales_items')->nullOnDelete();
            $table->string('item_name');
            $table->text('description')->nullable();
            $table->decimal('qty', 10, 2)->default(1);
            $table->string('unit')->default('pcs');
            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('tax', 5, 2)->default(0);   // GST %
            $table->decimal('discount', 12, 2)->default(0); // flat discount in ₹
            $table->decimal('total', 12, 2)->default(0);    // (qty*rate - discount) + tax
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['lineable_type', 'lineable_id']);
        });

        Schema::create('estimates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference')->unique();   // EST-2026-001
            $table->string('subject');
            $table->unsignedBigInteger('client_id')->nullable(); // contacts.id
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('date');
            $table->date('valid_until')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->enum('discount_type', ['none', 'before_tax', 'after_tax'])->default('none');
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('discount_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->foreignId('sale_agent')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('status', ['Draft', 'Sent', 'Accepted', 'Declined', 'Expired'])->default('Draft');
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('zip')->nullable();
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
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('estimates');
        Schema::dropIfExists('sales_line_items');
    }
};

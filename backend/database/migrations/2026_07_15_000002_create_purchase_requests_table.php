<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entry point of the procure-to-pay chain:
 * Purchase Request → Quotation → Purchase Order → Goods Received → Invoice.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('purchase_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->string('pr_number');
            $table->string('title');
            $table->string('department')->nullable();
            // Suggested vendor — nullable because a PR often precedes vendor choice.
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('requested_by')->nullable()->index();
            $table->date('required_by')->nullable();
            $table->string('priority')->default('Normal');   // Low | Normal | High | Urgent
            $table->text('justification')->nullable();

            $table->string('currency', 8)->default('INR');
            $table->decimal('subtotal', 15, 2)->default(0);
            $table->decimal('tax_total', 15, 2)->default(0);
            $table->decimal('total', 15, 2)->default(0);

            $table->string('status')->default('Draft')->index();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('remarks')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'pr_number']);
        });

        Schema::create('purchase_request_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_request_id')->index();
            $table->string('description');
            $table->decimal('qty', 15, 2)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('rate', 15, 2)->default(0);
            $table->decimal('tax', 5, 2)->default(0);       // percentage
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_items');
        Schema::dropIfExists('purchase_requests');
    }
};

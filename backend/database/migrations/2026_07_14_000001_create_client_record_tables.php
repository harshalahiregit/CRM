<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lightweight per-customer record tables surfaced as profile tabs — Contracts,
 * Expenses, Subscriptions, and the logistics trio (Pre-Alerts, Packages,
 * Shipments). Field sets mirror the legacy CRM's key columns. Prefixed
 * `client_` to stay clearly customer-scoped and avoid colliding with any future
 * top-level Expenses/Logistics modules.
 */
return new class extends Migration
{
    public function up(): void
    {
        $common = function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('client_id');
        };

        Schema::create('client_contracts', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('subject');
            $table->string('contract_type')->nullable();
            $table->decimal('value', 15, 2)->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('status')->default('Draft'); // Draft | Active | Expired
            $table->text('description')->nullable();
        });

        Schema::create('client_expenses', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('name');
            $table->string('category')->nullable();
            $table->decimal('amount', 15, 2)->default(0);
            $table->date('date')->nullable();
            $table->string('payment_mode')->nullable();
            $table->boolean('billable')->default(false);
            $table->text('note')->nullable();
        });

        Schema::create('client_subscriptions', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('name');
            $table->decimal('amount', 15, 2)->default(0);
            $table->unsignedInteger('quantity')->default(1);
            $table->string('interval')->default('Monthly'); // Monthly | Quarterly | Yearly
            $table->string('status')->default('Active');     // Active | Cancelled
            $table->date('next_billing_date')->nullable();
            $table->text('description')->nullable();
        });

        Schema::create('client_pre_alerts', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('tracking_number');
            $table->string('courier_company')->nullable();
            $table->string('supplier')->nullable();
            $table->decimal('purchase_price', 15, 2)->default(0);
            $table->date('delivery_date')->nullable();
            $table->string('status')->default('Pending');
            $table->text('description')->nullable();
        });

        Schema::create('client_packages', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('package_number');
            $table->string('description')->nullable();
            $table->string('courier_company')->nullable();
            $table->string('supplier')->nullable();
            $table->decimal('value', 15, 2)->default(0);
            $table->string('weight')->nullable();
            $table->string('status')->default('Received');
            $table->date('date')->nullable();
        });

        Schema::create('client_shipments', function (Blueprint $table) use ($common) {
            $common($table);
            $table->string('shipment_number');
            $table->string('origin')->nullable();
            $table->string('destination')->nullable();
            $table->string('courier_company')->nullable();
            $table->string('weight')->nullable();
            $table->decimal('value', 15, 2)->default(0);
            $table->string('status')->default('Processing');
            $table->date('date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_shipments');
        Schema::dropIfExists('client_packages');
        Schema::dropIfExists('client_pre_alerts');
        Schema::dropIfExists('client_subscriptions');
        Schema::dropIfExists('client_expenses');
        Schema::dropIfExists('client_contracts');
    }
};

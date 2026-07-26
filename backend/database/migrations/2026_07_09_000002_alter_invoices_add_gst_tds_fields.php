<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // sales_invoices — "Tax Invoice" per the terminology rename (Master Plan V2 §B1)
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->enum('invoice_type', ['tax_invoice', 'proforma', 'retainer'])->default('tax_invoice')->after('number');
            $table->boolean('gst_paid')->default(false)->after('total');
            $table->decimal('gst_amount', 12, 2)->default(0)->after('gst_paid');
            $table->decimal('after_discount_amount', 12, 2)->default(0)->after('discount_total');
            $table->string('public_link_token', 64)->nullable()->unique()->after('tags');
            $table->dateTime('public_link_expiry')->nullable()->after('public_link_token');
            $table->string('msme_udyam_number', 50)->nullable()->after('public_link_expiry');
            $table->string('eway_bill_number', 20)->nullable()->after('msme_udyam_number');
            $table->date('eway_bill_date')->nullable()->after('eway_bill_number');
            $table->boolean('payment_reminder_enabled')->default(true)->after('eway_bill_date');
            $table->json('reminder_schedule')->nullable()->after('payment_reminder_enabled');
            $table->boolean('feedback_email_sent')->default(false)->after('reminder_schedule');
        });

        Schema::table('sales_payments', function (Blueprint $table) {
            $table->decimal('tds_amount', 12, 2)->default(0)->after('amount');
            $table->string('tds_section', 20)->nullable()->after('tds_amount');
            $table->decimal('tds_percentage', 5, 2)->default(0)->after('tds_section');
            $table->enum('payment_type', ['received', 'paid'])->default('received')->after('tds_percentage');
        });

        // estimates — "Proforma Invoice" per the terminology rename (Master Plan V2 §B1)
        Schema::table('estimates', function (Blueprint $table) {
            $table->enum('estimate_type', ['proforma', 'estimate'])->default('proforma')->after('reference');
            $table->boolean('payment_received')->default(false)->after('status');
            $table->decimal('payment_amount', 12, 2)->default(0)->after('payment_received');
            $table->date('payment_date')->nullable()->after('payment_amount');
            $table->unsignedBigInteger('converted_invoice_id')->nullable()->after('payment_date');
        });
    }

    public function down(): void
    {
        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(['estimate_type', 'payment_received', 'payment_amount', 'payment_date', 'converted_invoice_id']);
        });

        Schema::table('sales_payments', function (Blueprint $table) {
            $table->dropColumn(['tds_amount', 'tds_section', 'tds_percentage', 'payment_type']);
        });

        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'invoice_type', 'gst_paid', 'gst_amount', 'after_discount_amount',
                'public_link_token', 'public_link_expiry', 'msme_udyam_number',
                'eway_bill_number', 'eway_bill_date', 'payment_reminder_enabled',
                'reminder_schedule', 'feedback_email_sent',
            ]);
        });
    }
};

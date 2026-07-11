<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing/shipping address snapshots on invoices and estimates.
 *
 * Documents snapshot the client's address at creation time, so a later change
 * to the client's registered address does NOT rewrite history — unless the
 * user ticks "apply to all previous invoices and estimates" on the client
 * edit form, which pushes the new address onto every existing document
 * (including paid ones — explicit product decision, differs from legacy
 * which skipped paid invoices).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
    ];

    public function up(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            foreach (self::COLUMNS as $col) {
                $table->string($col)->nullable();
            }
        });

        Schema::table('estimates', function (Blueprint $table) {
            foreach (self::COLUMNS as $col) {
                $table->string($col)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales_invoices', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });

        Schema::table('estimates', function (Blueprint $table) {
            $table->dropColumn(self::COLUMNS);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing/shipping address snapshots on invoices, estimates and credit notes.
 *
 * Documents snapshot the client's address at creation time, so a later change
 * to the client's registered address does NOT rewrite history — unless the
 * user ticks "apply to all previous invoices/estimates" (or the separate
 * credit-notes checkbox) on the client edit form, which pushes the new address
 * onto every existing document (including paid ones — explicit product
 * decision, differs from legacy which skipped paid invoices).
 */
return new class extends Migration
{
    private const COLUMNS = [
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
    ];

    private const TABLES = ['sales_invoices', 'estimates', 'credit_notes'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                foreach (self::COLUMNS as $col) {
                    $table->string($col)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn(self::COLUMNS);
            });
        }
    }
};

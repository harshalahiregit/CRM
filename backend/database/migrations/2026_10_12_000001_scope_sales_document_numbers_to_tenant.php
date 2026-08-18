<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make sales document numbers unique PER TENANT instead of globally.
 *
 * Invoice/estimate/credit-note/delivery-note numbers carried a plain
 * `unique(number)`. Every tenant's numbering restarts at 001, so the first
 * document a second tenant ever created collided with the first tenant's and the
 * insert failed outright — a hard blocker for multi-tenant use that only shows up
 * once there is more than one tenant, which is why it survived single-tenant
 * testing. A test that creates an invoice in two tenants reproduces it.
 *
 * Proposals and contracts were already correct, so they are left alone.
 */
return new class extends Migration
{
    /** table => number column */
    private array $targets = [
        'sales_invoices' => 'number',
        'estimates'      => 'reference',
        'credit_notes'   => 'number',
        'delivery_notes' => 'number',
    ];

    public function up(): void
    {
        foreach ($this->targets as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                // Laravel's generated name for unique($column).
                $t->dropUnique("{$table}_{$column}_unique");
                $t->unique(['tenant_id', $column], "{$table}_tenant_{$column}_unique");
            });
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table, $column) {
                $t->dropUnique("{$table}_tenant_{$column}_unique");
                $t->unique($column, "{$table}_{$column}_unique");
            });
        }
    }
};

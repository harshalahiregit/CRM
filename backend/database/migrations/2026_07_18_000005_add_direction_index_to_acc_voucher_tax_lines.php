<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GSTR-1/3B filter voucher_tax_lines by `direction` (outward/inward); add a
 * supporting composite index so the returns queries stay index-backed at scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_voucher_tax_lines', function (Blueprint $table) {
            $table->index(['tenant_id', 'direction', 'tax_type'], 'acc_tax_lines_direction_idx');
        });
    }

    public function down(): void
    {
        Schema::table('acc_voucher_tax_lines', function (Blueprint $table) {
            $table->dropIndex('acc_tax_lines_direction_idx');
        });
    }
};

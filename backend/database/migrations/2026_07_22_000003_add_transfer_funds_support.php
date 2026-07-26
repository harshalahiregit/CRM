<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Transfer Funds module support.
 *
 * `acc_ledgers.party_type` classifies a party ledger as client / vendor / tpv /
 * other — purely a UI/reporting label (the ledger itself already works without
 * it). This is the ONLY schema hook the future Vendor/TPV modules need: when
 * they exist, their posting bridges just set `party_type` when they
 * find-or-create a party ledger (exactly as SalesPostingBridge/BillService do
 * for 'client'/'vendor' today) and the Transfer Funds picker, and every other
 * party-aware screen, groups them automatically — no further code changes.
 *
 * `acc_transfer_categories` is the "Category / Head" master the Transfer Funds
 * screen uses to classify a move (reversal, excess-payment return, double-payment
 * refund, etc.) for reporting. `acc_vouchers.transfer_category_id` records it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acc_ledgers', function (Blueprint $table) {
            $table->string('party_type', 10)->nullable()->after('party_id'); // client|vendor|tpv|other
        });

        // Best-effort backfill for party ledgers that already exist (built before
        // this column existed) so they classify correctly without manual editing.
        DB::statement("
            UPDATE acc_ledgers SET party_type = 'client'
            WHERE is_party = 1 AND party_type IS NULL
              AND group_id IN (SELECT id FROM acc_account_groups WHERE name = 'Sundry Debtors')
        ");
        DB::statement("
            UPDATE acc_ledgers SET party_type = 'vendor'
            WHERE is_party = 1 AND party_type IS NULL
              AND group_id IN (SELECT id FROM acc_account_groups WHERE name = 'Sundry Creditors')
        ");

        Schema::create('acc_transfer_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('acc_vouchers', function (Blueprint $table) {
            $table->unsignedBigInteger('transfer_category_id')->nullable()->after('source_id');
            $table->foreign('transfer_category_id')->references('id')->on('acc_transfer_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('acc_vouchers', function (Blueprint $table) {
            $table->dropForeign(['transfer_category_id']);
            $table->dropColumn('transfer_category_id');
        });
        Schema::dropIfExists('acc_transfer_categories');
        Schema::table('acc_ledgers', function (Blueprint $table) {
            $table->dropColumn('party_type');
        });
    }
};

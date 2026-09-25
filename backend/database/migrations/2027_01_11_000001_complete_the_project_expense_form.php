<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The project expense form, finished.
 *
 * It recorded a title, a free-text category, an amount, a date, a note and a
 * billable flag — which is enough to say money was spent and not enough to do
 * anything with afterwards. Missing, and all of it ordinary:
 *
 *  • the CATEGORY was typed by hand on every row, while expense_categories and
 *    its admin screen already existed — so "Travel", "travel" and "Travel " were
 *    three categories and no total could be trusted;
 *  • no RECEIPT. An expense with no document behind it cannot be claimed,
 *    audited or re-billed, and the receipt was being e-mailed separately;
 *  • no REFERENCE — the bill or voucher number the receipt is filed under;
 *  • no PAYMENT MODE, which ClientExpense has had all along, so the same spend
 *    recorded against a client and against a project stored different things;
 *  • no TAX, so a gross figure had to be typed and the tax component was lost;
 *  • no CURRENCY on a system where purchase orders carry one;
 *  • no VENDOR — who was actually paid.
 *
 * `category` stays. It is the label as it was entered, and dropping it would
 * blank the category on every expense already recorded. New rows set both: the
 * id is the truth, the string is what it was called at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->foreignId('expense_category_id')->nullable()->after('category')
                ->constrained('expense_categories')->nullOnDelete();

            $table->string('reference_no')->nullable()->after('expense_date');
            $table->string('payment_mode', 40)->nullable()->after('reference_no');
            $table->string('currency', 3)->default('INR')->after('amount');
            $table->decimal('tax_percent', 5, 2)->default(0)->after('currency');

            // Who was paid. Nullable and un-constrained on purpose: plenty of
            // spend is a taxi or a hardware shop, not a vendor on the master.
            $table->unsignedBigInteger('purchase_vendor_id')->nullable()->after('payment_mode');

            // The receipt itself, on the same private disk as project files.
            $table->string('receipt_path')->nullable()->after('note');
            $table->string('receipt_name')->nullable()->after('receipt_path');

            $table->index(['tenant_id', 'expense_category_id'], 'project_expense_category_idx');
        });
    }

    public function down(): void
    {
        Schema::table('project_expenses', function (Blueprint $table) {
            $table->dropIndex('project_expense_category_idx');
            $table->dropConstrainedForeignId('expense_category_id');
            $table->dropColumn([
                'reference_no', 'payment_mode', 'currency', 'tax_percent',
                'purchase_vendor_id', 'receipt_path', 'receipt_name',
            ]);
        });
    }
};

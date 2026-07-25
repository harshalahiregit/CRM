<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods receiving with inspection (§13).
 *
 * Until now a receipt said one thing: this quantity arrived, put it on the
 * shelf. Real receiving has three different numbers that routinely disagree:
 *
 *   ordered   — what the purchase order said
 *   received  — what actually turned up on the lorry
 *   accepted  — what passed inspection and may be sold
 *
 * The gap between received and accepted is the whole point. Damaged or
 * out-of-spec goods are physically in the building but must NOT become sellable
 * stock, or the first customer order picks a broken unit. So posting a receipt
 * moves the ACCEPTED quantity, and the rejected balance stays visible as
 * something to send back rather than silently vanishing.
 *
 * Existing receipts have no inspection: accepted_qty is left null and the
 * posting path falls back to `quantity`, so nothing already in the system
 * changes meaning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_voucher_items', function (Blueprint $table) {
            $table->decimal('ordered_qty', 15, 3)->nullable()->after('quantity');
            $table->decimal('received_qty', 15, 3)->nullable()->after('ordered_qty');
            $table->decimal('accepted_qty', 15, 3)->nullable()->after('received_qty');
            $table->decimal('rejected_qty', 15, 3)->nullable()->after('accepted_qty');
            // pending | passed | failed | partial — null until someone inspects.
            $table->string('qc_status', 12)->nullable()->after('rejected_qty');
            $table->string('rejection_reason', 500)->nullable()->after('qc_status');
        });

        Schema::table('inventory_vouchers', function (Blueprint $table) {
            $table->timestamp('inspected_at')->nullable()->after('submitted_at');
            $table->unsignedBigInteger('inspected_by')->nullable()->after('inspected_at');
            // Set when a vendor return note has been raised for the rejects, so
            // the same rejection can't be chased twice.
            $table->timestamp('vendor_returned_at')->nullable()->after('inspected_by');
        });

        // Paperwork that arrives WITH the goods: delivery challan, test
        // certificate, inspection photos. Kept against the document rather than
        // the product, because that is what an auditor asks to see.
        Schema::create('inventory_voucher_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('voucher_id')->index();
            $table->string('file_path');
            $table->string('file_name');
            $table->unsignedBigInteger('file_size')->default(0);
            $table->string('mime_type', 120)->nullable();
            $table->string('kind', 20)->default('document');   // document | inspection | pod
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'voucher_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_voucher_files');

        Schema::table('inventory_vouchers', function (Blueprint $table) {
            $table->dropColumn(['inspected_at', 'inspected_by', 'vendor_returned_at']);
        });

        Schema::table('inventory_voucher_items', function (Blueprint $table) {
            $table->dropColumn([
                'ordered_qty', 'received_qty', 'accepted_qty', 'rejected_qty',
                'qc_status', 'rejection_reason',
            ]);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chequebook inventory — a physical book of cheque leaves issued by a bank
 * against one of the company's bank accounts (spec §1). Each book owns a
 * contiguous serial range (start_no … end_no); issuing a cheque draws the next
 * unused leaf via next_no, which the allocator advances one at a time. When
 * next_no passes end_no the book is exhausted.
 *
 * Cheque numbers frequently carry leading zeros (000001), so the human-facing
 * number is prefix + zero-padded(next_no, digits); the numeric columns exist
 * purely so the "next available leaf" is unambiguous and lockable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_chequebooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_account_id')->constrained('acc_bank_accounts')->cascadeOnDelete();
            $table->string('name', 120);                 // book identifier, e.g. "Book #1"
            $table->string('prefix', 20)->nullable();    // optional printed prefix before the number
            $table->unsignedBigInteger('start_no');      // first leaf serial (numeric)
            $table->unsignedBigInteger('end_no');        // last leaf serial (numeric)
            $table->unsignedBigInteger('next_no');       // next unused leaf; starts at start_no
            $table->unsignedTinyInteger('digits')->default(6); // zero-pad width for display
            $table->unsignedInteger('total_leaves');     // end_no - start_no + 1 (denormalised)
            $table->string('status', 20)->default('active'); // active | exhausted | closed
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'bank_account_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('acc_chequebooks');
    }
};

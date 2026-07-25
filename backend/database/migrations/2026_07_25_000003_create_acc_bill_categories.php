<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bill category master — a classification for vendor bills (Utilities, Rent,
 * Marketing, …), separate from the expense LEDGER a bill posts to. Manageable
 * from Settings and inline on the New Bill form. Stored as plain text on the
 * bill so historical rows keep their label even if a category is renamed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acc_bill_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('acc_bills', function (Blueprint $table) {
            $table->string('category', 100)->nullable()->after('vendor_name');
        });
    }

    public function down(): void
    {
        Schema::table('acc_bills', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::dropIfExists('acc_bill_categories');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_items', function (Blueprint $table) {
            $table->string('hsn_sac_code', 20)->nullable()->after('category');
            $table->enum('hsn_sac_type', ['HSN', 'SAC'])->default('SAC')->after('hsn_sac_code');
        });

        Schema::table('sales_line_items', function (Blueprint $table) {
            $table->string('hsn_sac_code', 20)->nullable()->after('item_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_line_items', function (Blueprint $table) {
            $table->dropColumn('hsn_sac_code');
        });

        Schema::table('sales_items', function (Blueprint $table) {
            $table->dropColumn(['hsn_sac_code', 'hsn_sac_type']);
        });
    }
};

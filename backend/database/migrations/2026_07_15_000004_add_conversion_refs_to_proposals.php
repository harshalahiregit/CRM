<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedBigInteger('converted_estimate_id')->nullable()->after('terms');
            $table->unsignedBigInteger('converted_invoice_id')->nullable()->after('converted_estimate_id');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['converted_estimate_id', 'converted_invoice_id']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(true)->after('phone');
            $table->string('whatsapp_number')->nullable()->after('whatsapp_opt_in');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_opt_in', 'whatsapp_number']);
        });
    }
};

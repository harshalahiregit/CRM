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
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('whatsapp_sent');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hr_interview_rounds', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};

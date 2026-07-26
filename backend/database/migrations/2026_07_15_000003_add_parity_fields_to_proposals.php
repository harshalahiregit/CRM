<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('reference_no')->nullable()->after('subject');
            $table->text('terms')->nullable()->after('notes');

            $table->index('reference_no');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropIndex(['reference_no']);
            $table->dropColumn(['reference_no', 'terms']);
        });
    }
};

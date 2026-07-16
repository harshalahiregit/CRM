<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->string('pan', 20)->nullable()->after('website');
            $table->string('gst', 20)->nullable()->after('pan');
            $table->string('industry')->nullable()->after('gst');
            $table->string('campaign')->nullable()->after('industry');
            $table->enum('priority', ['low', 'medium', 'high'])->default('medium')->after('campaign');
            $table->date('expected_close_date')->nullable()->after('priority');

            $table->index('expected_close_date');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['expected_close_date']);
            $table->dropColumn(['pan', 'gst', 'industry', 'campaign', 'priority', 'expected_close_date']);
        });
    }
};

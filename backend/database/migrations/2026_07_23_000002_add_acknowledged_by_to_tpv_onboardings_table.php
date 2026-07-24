<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            if (! Schema::hasColumn('tpv_onboardings', 'acknowledged_by')) {
                $table->string('acknowledged_by')->nullable()->after('acknowledged');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tpv_onboardings', function (Blueprint $table) {
            if (Schema::hasColumn('tpv_onboardings', 'acknowledged_by')) {
                $table->dropColumn('acknowledged_by');
            }
        });
    }
};

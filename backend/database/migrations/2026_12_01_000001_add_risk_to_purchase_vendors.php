<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Purchase-side Risk Score — a lean tier+score the admin sets, the vendor views. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            // Idempotent: an earlier draft added these under a different filename.
            if (! Schema::hasColumn('purchase_vendors', 'risk_level')) {
                $table->string('risk_level')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'risk_score')) {
                $table->unsignedInteger('risk_score')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'risk_notes')) {
                $table->text('risk_notes')->nullable();
            }
            if (! Schema::hasColumn('purchase_vendors', 'risk_assessed_at')) {
                $table->timestamp('risk_assessed_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            $table->dropColumn(['risk_level', 'risk_score', 'risk_notes', 'risk_assessed_at']);
        });
    }
};

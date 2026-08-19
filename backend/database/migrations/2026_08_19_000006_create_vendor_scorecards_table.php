<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Rating System snapshots (Doc 5). The scorecard is computed live from
 * incidents / strikes / document currency / workforce, but a monthly snapshot is
 * persisted here so a vendor's trend over time is visible and auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_scorecards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('period', 7);                 // YYYY-MM
            $table->unsignedTinyInteger('safety_score');
            $table->unsignedTinyInteger('compliance_score');
            $table->unsignedTinyInteger('workforce_score');
            $table->unsignedTinyInteger('overall_score');
            $table->string('band', 2);                   // A | B | C | D
            $table->json('breakdown')->nullable();       // the contributing figures
            $table->timestamp('computed_at')->nullable();
            $table->timestamps();

            $table->unique(['vendor_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_scorecards');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Worker recognition + statutory health card (Doc_4 §12 / Document_15 card).
 * `awards` records reward/recognition entries surfaced on the digital access
 * card; `bocw_number` carries the Building & Other Construction Workers welfare
 * board health-card number that the card is meant to display.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            if (! Schema::hasColumn('tpv_workers', 'awards')) {
                $table->text('awards')->nullable()->after('remarks');
            }
            if (! Schema::hasColumn('tpv_workers', 'bocw_number')) {
                $table->string('bocw_number', 60)->nullable()->after('awards');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tpv_workers', function (Blueprint $table) {
            foreach (['awards', 'bocw_number'] as $col) {
                if (Schema::hasColumn('tpv_workers', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

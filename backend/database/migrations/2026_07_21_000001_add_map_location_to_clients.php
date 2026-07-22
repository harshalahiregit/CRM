<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pinned map location for a customer — kept separate from the postal address
 * so the pin can be the actual site/gate/warehouse entrance rather than the
 * billing address. Coordinates come from the map search (geocode) or manual
 * entry; when unset the Map tab falls back to the postal address.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->text('map_address')->nullable()->after('country');
            $table->decimal('latitude', 10, 7)->nullable()->after('map_address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['map_address', 'latitude', 'longitude']);
        });
    }
};

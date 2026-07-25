<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bin capacity, with a unit attached (§16).
 *
 * `capacity` has existed since the module shipped as a bare number with no unit,
 * which makes it unusable the moment a bin holds more than one kind of thing: a
 * shelf that fits 500 washers does not fit 500 engines, so "capacity 500" says
 * nothing on its own.
 *
 * So a location now declares WHAT its capacity counts:
 *
 *   units  — a plain count. Honest only where a bin holds one kind of thing,
 *            which is the common case for a pick face.
 *   volume — sum of quantity x the product's volume.
 *   weight — sum of quantity x the product's weight. The one that matters for
 *            a mezzanine or a rack beam, where the limit is structural.
 *
 * Default 'units', so every capacity already entered keeps meaning exactly what
 * whoever typed it assumed it meant.
 *
 * Volume and weight depend on product data that many catalogues only partly
 * fill in. The service reports that coverage rather than quietly treating a
 * missing weight as zero — a utilisation figure that silently ignores half the
 * stock is worse than no figure, because people act on it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->string('capacity_uom', 10)->default('units')->after('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_locations', function (Blueprint $table) {
            $table->dropColumn('capacity_uom');
        });
    }
};

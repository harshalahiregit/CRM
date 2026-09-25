<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-COST — a fuel benchmark per truck (T-19).
 *
 * `config/stos.php` has said this was coming since M2:
 *
 *   "Per TYPE rather than per vehicle because a per-vehicle benchmark is a
 *    master-data screen nobody has built yet; when that lands, a column on
 *    `vehicles` overrides this table."
 *
 * This is that column. A type default is a reasonable first guess and a poor
 * long-run answer: a ten-year-old tipper and last year's do not return the same
 * kilometres per litre, and flagging the old one on every fill teaches people
 * to ignore the exception queue — which is the failure that matters, because
 * the queue is the only thing that catches real theft.
 *
 * NULLABLE, and null means "use the type default". Not seeded with the type
 * value: copying the default into every row would make it impossible to tell a
 * truck somebody has actually measured from one nobody has looked at, and the
 * whole point is to know which.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('vehicles', 'benchmark_kmpl')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table) {
            // 5,2 — up to 999.99 km/l. An LCV does 8; the headroom is for a
            // unit nobody has thought of, not for a plausible reading.
            $table->decimal('benchmark_kmpl', 5, 2)->nullable()->after('capacity_tonnes');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('vehicles', 'benchmark_kmpl')) {
            return;
        }

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn('benchmark_kmpl');
        });
    }
};

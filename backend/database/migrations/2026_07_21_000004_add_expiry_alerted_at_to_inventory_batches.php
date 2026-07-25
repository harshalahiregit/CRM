<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency stamp for the daily expiry digest.
 *
 * Without it, a command scheduled daily would re-send the same batch every
 * morning until someone acts on it — which trains people to ignore it. Stamping
 * the row means each batch is announced once as it enters the alert window; the
 * stamp is cleared if the expiry date is pushed out, so a genuinely new
 * situation alerts again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->timestamp('expiry_alerted_at')->nullable()->after('quality_status');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            $table->dropColumn('expiry_alerted_at');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Warehouse type behaviour + environment monitoring + per-movement provenance.
 *
 *  • Warehouses gain a temperature/humidity band (what "in range" means for a
 *    cold store or a controlled room) and per-site compliance switches that can
 *    require a GPS fix and/or a photo on every hand-made stock move there.
 *  • Movements gain the GPS fix, a reverse-geocoded address and a photo path, so
 *    the ledger can answer "where was this actually done, and what did it look
 *    like" — the field-audit trail the spec asks for.
 *  • A readings table logs the actual temperature/humidity taken at a site, each
 *    flagged in- or out-of-band so a breach is a queryable event, not a note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_warehouses', 'temp_min')) {
                $table->decimal('temp_min', 6, 2)->nullable()->after('type');
                $table->decimal('temp_max', 6, 2)->nullable()->after('temp_min');
                $table->decimal('humidity_min', 6, 2)->nullable()->after('temp_max');
                $table->decimal('humidity_max', 6, 2)->nullable()->after('humidity_min');
                $table->boolean('track_environment')->default(false)->after('humidity_max');
                $table->boolean('require_move_gps')->default(false)->after('track_environment');
                $table->boolean('require_move_photo')->default(false)->after('require_move_gps');
            }
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_movements', 'gps_lat')) {
                $table->decimal('gps_lat', 10, 7)->nullable()->after('notes');
                $table->decimal('gps_lng', 10, 7)->nullable()->after('gps_lat');
                $table->string('geo_address')->nullable()->after('gps_lng');
                $table->string('photo_path')->nullable()->after('geo_address');
            }
        });

        if (! Schema::hasTable('inventory_warehouse_env_readings')) {
            Schema::create('inventory_warehouse_env_readings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('inventory_warehouses')->cascadeOnDelete();
                $table->decimal('temperature', 6, 2)->nullable();
                $table->decimal('humidity', 6, 2)->nullable();
                $table->boolean('in_band')->default(true);
                $table->text('note')->nullable();
                $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('recorded_at')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'warehouse_id', 'recorded_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_warehouse_env_readings');

        Schema::table('inventory_movements', function (Blueprint $table) {
            foreach (['gps_lat', 'gps_lng', 'geo_address', 'photo_path'] as $c) {
                if (Schema::hasColumn('inventory_movements', $c)) {
                    $table->dropColumn($c);
                }
            }
        });

        Schema::table('inventory_warehouses', function (Blueprint $table) {
            foreach (['temp_min', 'temp_max', 'humidity_min', 'humidity_max', 'track_environment', 'require_move_gps', 'require_move_photo'] as $c) {
                if (Schema::hasColumn('inventory_warehouses', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer Health (§8) and Risk (§9) storage.
 *
 * The score is computed live on the customer page — it is a read over data that
 * changes constantly, and a cached number on a detail view would be wrong the
 * moment an invoice is paid. The stored copy exists for the LIST, where
 * computing ten aggregate queries per row would mean thousands per page.
 *
 * So this is not a cache of anyone else's data: it is Customer's own derived
 * value, refreshed on a schedule, and shown with the time it was calculated so
 * nobody mistakes it for live.
 *
 * Relationship and Compliance risk are stored rather than derived because
 * neither has an honest signal in the system. A judgement someone actually made
 * is worth more than a number invented from proxies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedTinyInteger('health_score')->nullable()->after('lifecycle_status')->index();
            $table->string('health_status', 20)->nullable()->after('health_score')->index();
            $table->timestamp('health_calculated_at')->nullable()->after('health_status');

            // Set by the account owner, not derived.
            $table->string('risk_relationship', 10)->nullable()->after('health_calculated_at');
            $table->string('risk_compliance', 10)->nullable()->after('risk_relationship');
            $table->string('risk_overall', 10)->nullable()->after('risk_compliance')->index();
        });

        Schema::create('client_health_history', function (Blueprint $table) {
            // §8 asks for history as well as the current number: a customer at
            // 72 and falling is a different conversation from one at 72 and
            // climbing, and a single stored score cannot tell them apart.
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedTinyInteger('score');
            $table->string('status', 20);
            $table->unsignedTinyInteger('measured')->default(0);
            $table->unsignedTinyInteger('of')->default(0);
            $table->timestamp('recorded_at')->index();
            $table->timestamps();

            $table->unique(['client_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_health_history');
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn([
            'health_score', 'health_status', 'health_calculated_at',
            'risk_relationship', 'risk_compliance', 'risk_overall',
        ]));
    }
};

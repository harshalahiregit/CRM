<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring the lean purchase_hsse_incidents table up to the full incident-engine
 * shape (parity with tpv hsse_incidents): reporter, stop-work / auto-suspend
 * flags, the RCA record and the closure stamp. Purely additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_hsse_incidents', function (Blueprint $table) {
            $table->unsignedBigInteger('reported_by')->nullable()->after('purchase_vendor_id');
            $table->boolean('stop_work')->default(false)->after('immediate_action');
            $table->boolean('triggered_suspension')->default(false)->after('stop_work');
            $table->string('rca_method')->nullable()->after('triggered_suspension');
            $table->text('root_cause')->nullable()->after('rca_method');
            $table->text('contributing_factors')->nullable()->after('root_cause');
            $table->dateTime('rca_completed_at')->nullable()->after('contributing_factors');
            $table->dateTime('closed_at')->nullable()->after('rca_completed_at');
            $table->unsignedBigInteger('closed_by')->nullable()->after('closed_at');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_hsse_incidents', function (Blueprint $table) {
            $table->dropColumn([
                'reported_by', 'stop_work', 'triggered_suspension', 'rca_method',
                'root_cause', 'contributing_factors', 'rca_completed_at', 'closed_at', 'closed_by',
            ]);
        });
    }
};

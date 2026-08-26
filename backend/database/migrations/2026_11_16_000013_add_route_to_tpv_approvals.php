<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * §12 — dimension-based approval routing. An approval now CARRIES the multi-level
 * approver route it was routed onto when raised (from TpvSettings::routeFor), and
 * a pointer to the level currently awaiting sign-off. A single-level route (the
 * default) behaves exactly like the previous one-shot approval, so existing flows
 * are unchanged; a High/Critical-risk or high-value request threads through the
 * two- or three-level chain the doc requires. Additive & nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_approvals', function (Blueprint $table) {
            // Ordered list of approver levels this request must pass, e.g.
            // ["manager","head","director"]. Null/empty ⇒ legacy one-shot approval.
            $table->json('route')->nullable()->after('status');
            // 0-based pointer to the level currently awaiting sign-off.
            $table->unsignedTinyInteger('route_index')->default(0)->after('route');
        });
    }

    public function down(): void
    {
        Schema::table('tpv_approvals', function (Blueprint $table) {
            $table->dropColumn(['route', 'route_index']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P0-2 — medical history. The table previously enforced ONE medical per worker
 * (`unique('tpv_worker_id')`), so a re-test overwrote the last and no history
 * survived. Relax it to one record per worker PER EXAM DATE: re-saving the same
 * day's exam still updates in place, but a new exam date accumulates as history.
 * `worker->medical` now resolves to the latest exam (see TpvWorker::medical).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropUnique(['tpv_worker_id']);
            $table->index('tpv_worker_id');
            $table->unique(['tpv_worker_id', 'exam_date']);
        });
    }

    public function down(): void
    {
        Schema::table('tpv_worker_medicals', function (Blueprint $table) {
            $table->dropUnique(['tpv_worker_id', 'exam_date']);
            $table->dropIndex(['tpv_worker_id']);
            $table->unique('tpv_worker_id');
        });
    }
};

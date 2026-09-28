<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 3 · Feature 2 — Approval delegation. An approver hands their authority to
 * another user for a bounded window (with a reason). Additive, standalone table.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_delegations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('delegator_id')->index();
            $table->unsignedBigInteger('delegate_id')->index();
            $table->text('reason')->nullable();
            // dateTime, not timestamp.
            //
            // MySQL gives the FIRST timestamp column in a table an implicit
            // DEFAULT CURRENT_TIMESTAMP and every later one an implicit
            // '0000-00-00', which strict mode then rejects:
            //
            //     SQLSTATE[42000] 1067: Invalid default value for 'ends_at'
            //
            // So `starts_at` silently got "now" and `ends_at` failed outright —
            // neither is what a delegation window means. dateTime has no
            // implicit default, so both stay NOT NULL and must be supplied.
            // SQLite treats the two types alike, which is why this passed
            // locally.
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};

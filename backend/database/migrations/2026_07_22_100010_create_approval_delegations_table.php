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
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_delegations');
    }
};

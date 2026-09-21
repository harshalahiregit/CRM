<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One configured approval ladder, per process, per tenant.
 *
 * Additive: nothing existing is altered and no row is backfilled. A tenant with
 * no row here keeps the behaviour it has today — the registry synthesises the
 * legacy HR-queue step rather than leaving anybody unable to approve.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_approval_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // A key from ApprovalProcess, validated on write. Stored as a string
            // rather than an enum so adding a process is a code change, not a
            // migration on a live table.
            $table->string('process', 60);
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);

            /*
             | Bumped on every step edit.
             |
             | An approval already in progress carries its own snapshot, so this
             | is not what protects it — it is how a request records WHICH
             | configuration it started under, so an auditor can tell why a
             | request from March took a different route from one in June.
             */
            $table->unsignedInteger('version')->default(1);

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // One workflow per process per tenant. A second ladder for the same
            // process would make "which one applies" ambiguous, and conditions
            // on the steps already express the branching that would motivate it.
            $table->unique(['tenant_id', 'process']);
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_workflows');
    }
};

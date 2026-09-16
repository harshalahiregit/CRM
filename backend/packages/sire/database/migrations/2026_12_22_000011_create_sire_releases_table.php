<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SIRE — the release register. Issues point at rows here for four version roles. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_releases')) {
            return;
        }

        Schema::create('sire_releases', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->string('version', 64);              // '2026.3.1', 'v4.2.0-hotfix'
            $table->string('name', 160)->nullable();    // 'Spring hardening'
            $table->string('release_type', 24);         // major | minor | patch | hotfix
            $table->date('release_date')->nullable();   // planned, then actual
            $table->string('status', 24)->default('planned'); // planned | in_progress | released | rolled_back
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->text('summary')->nullable();

            $table->dateTime('released_at')->nullable();
            $table->unsignedBigInteger('released_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            // A tenant cannot ship the same version twice.
            $table->unique(['tenant_id', 'version'], 'sire_rel_tenant_version_uq');
            $table->index(['tenant_id', 'status'], 'sire_rel_tenant_status_idx');
            $table->index(['tenant_id', 'release_date'], 'sire_rel_tenant_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_releases');
    }
};

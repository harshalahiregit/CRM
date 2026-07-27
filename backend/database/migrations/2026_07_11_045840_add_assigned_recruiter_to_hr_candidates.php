<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assign a recruiter (a tenant user) as the owner of a candidate. Nullable so
 * existing/portal candidates stay unassigned until HR picks them up.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->foreignId('assigned_recruiter_id')
                ->nullable()
                ->after('final_decision')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_recruiter_id');
        });
    }
};

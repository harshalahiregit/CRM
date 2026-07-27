<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Widen hr_manpower_requests.priority from enum(Low,Medium,High) to a string so
 * it can hold "Critical" (surfaced on Job Posting recruitment cards). Existing
 * values are unaffected. Idempotent / multi-tenant-neutral.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->string('priority', 20)->default('Medium')->change();
        });
    }

    public function down(): void
    {
        // Leave as string — reverting to the narrow enum would reject 'Critical'.
    }
};

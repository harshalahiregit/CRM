<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SPK-1: campaign tracking + work mode on job postings. Both nullable so
     * existing rows and flows are unaffected. Job ID, status and the public /
     * internal apply links reuse existing columns (id, status, slug + id).
     */
    public function up(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->string('campaign_number')->nullable()->after('manpower_request_id');
            $table->string('work_mode')->nullable()->after('posting_type'); // Onsite / Remote / Hybrid
        });
    }

    public function down(): void
    {
        Schema::table('hr_job_postings', function (Blueprint $table) {
            $table->dropColumn(['campaign_number', 'work_mode']);
        });
    }
};

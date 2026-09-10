<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — root cause analysis. One per issue.
 *
 * A separate table rather than more columns on sire_reports: RCA exists for a
 * minority of issues, carries ten fields nobody lists or sorts by, and is written
 * once at the end of an investigation. Keeping it out of the register table keeps
 * the dashboard queries narrow — the same reasoning as sire_report_contexts.
 *
 * `category` IS a column because defect-trend reporting groups by it. Contributing
 * factors and the five whys are json because they are ordered prose, displayed and
 * never filtered. If that stops being true, promote them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sire_root_causes')) {
            return;
        }

        Schema::create('sire_root_causes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('report_id');

            // code | design | requirements | data | configuration | infrastructure |
            // third_party | process | human_error | testing_gap | unknown
            $table->string('category', 40);
            $table->text('description');
            $table->json('contributing_factors')->nullable(); // ordered list of strings

            // Why did our own testing not catch this? The most useful question on
            // the form, and the one most often left blank if it is not required.
            $table->text('detection_gap')->nullable();

            $table->text('corrective_action')->nullable();
            $table->text('preventive_action')->nullable();

            // Five Whys, for serious issues. Ordered json: it is a chain, and a
            // child table for five display-only strings would be ceremony.
            $table->json('five_whys')->nullable();

            $table->unsignedBigInteger('confirmed_by')->nullable();
            $table->dateTime('confirmed_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->unique(['tenant_id', 'report_id'], 'sire_rc_tenant_report_uq');
            $table->index(['tenant_id', 'category'], 'sire_rc_tenant_category_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sire_root_causes');
    }
};

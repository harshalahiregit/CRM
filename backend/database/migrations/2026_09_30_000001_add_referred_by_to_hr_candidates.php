<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review comment #15 — the REFERENCE half of "AUTO FETCH CANDIDATE DETAIL AND
 * (PRESENT CO., DEPT, DESIGNATION, REFERENCE".
 *
 * WHY A COLUMN IS REQUIRED HERE. Nothing existing can hold this:
 *
 *  - `source` is a CATEGORY ('Career Portal', 'LinkedIn', 'Referral'). It says
 *    how the candidate arrived, never who sent them.
 *  - `professional_references` is the background-check list — past managers a
 *    recruiter may call. Putting the referrer in it would mix "who vouched for
 *    them afterwards" with "who introduced them", and the two are read on
 *    different screens for different reasons.
 *
 * `referred_by_id` points at the referring EMPLOYEE where there is one, so the
 * reference resolves to a real person rather than a typed name that nobody can
 * look up. `referred_by_name` carries the free-text case — a client, a former
 * colleague, someone with no employee record — so an external referral is still
 * recordable.
 *
 * Both nullable, no default: every existing row and API response is unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_candidates', 'referred_by_id')) {
                $table->unsignedBigInteger('referred_by_id')->nullable()->after('source');
                $table->index(['tenant_id', 'referred_by_id']);
                // nullOnDelete: losing the employee must not lose the candidate.
                $table->foreign('referred_by_id')->references('id')->on('hr_employees')->nullOnDelete();
            }
            if (! Schema::hasColumn('hr_candidates', 'referred_by_name')) {
                $table->string('referred_by_name')->nullable()->after('referred_by_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_candidates', function (Blueprint $table) {
            $table->dropForeign(['referred_by_id']);
            $table->dropIndex(['tenant_id', 'referred_by_id']);
            $table->dropColumn(['referred_by_id', 'referred_by_name']);
        });
    }
};

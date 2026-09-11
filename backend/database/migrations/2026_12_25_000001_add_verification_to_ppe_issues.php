<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somewhere to record that issued PPE was actually checked.
 *
 * `verification_required` has been on both engines' requirement matrices since
 * the matrix was built — settable, validated, saved and shown on screen — and
 * nothing has ever read it, because there was nowhere to record the
 * verification it asks for. A rule that says "this must be verified" could be
 * satisfied by handing the item over and walking away.
 *
 * A harness or a fall-arrest lanyard is the reason the flag exists: the item
 * being in someone's hands is not the same as the item being fit to use.
 */
return new class extends Migration
{
    private const TABLES = ['tpv_worker_ppe_issues', 'purchase_worker_ppe_issues'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                if (! Schema::hasColumn($table, 'verified_at')) {
                    $t->timestamp('verified_at')->nullable();
                }
                if (! Schema::hasColumn($table, 'verified_by')) {
                    $t->unsignedBigInteger('verified_by')->nullable();
                }
                if (! Schema::hasColumn($table, 'verification_notes')) {
                    $t->string('verification_notes', 500)->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['verified_at', 'verified_by', 'verification_notes']);
            });
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extending a temporary Purchase Vendor's window.
 *
 * Purchase could promote a temporary vendor to permanent and could let one
 * expire, and had nothing in between. So an admin whose contractor needed three
 * more days had two choices: make them permanent for ever, or let them be locked
 * out on the day. TPV has had the middle option since its temporary work landed.
 *
 * The reason is stored, not optional. An extension that records only a new date
 * cannot answer the question an auditor actually asks, which is why the window
 * moved — and if nobody has to give a reason, nobody does.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            $table->timestamp('access_extended_at')->nullable()->after('access_reminders_sent');
            $table->unsignedBigInteger('access_extended_by')->nullable()->after('access_extended_at');
            $table->string('extension_reason', 500)->nullable()->after('access_extended_by');

            // How long the window was granted for, kept so a later extension can
            // offer the same period rather than making somebody remember it.
            $table->unsignedSmallInteger('validity_days')->nullable()->after('extension_reason');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_vendors', function (Blueprint $table) {
            $table->dropColumn([
                'access_extended_at', 'access_extended_by', 'extension_reason', 'validity_days',
            ]);
        });
    }
};

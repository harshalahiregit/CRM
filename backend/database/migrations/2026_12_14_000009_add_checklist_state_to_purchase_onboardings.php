<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: the onboarding checklist (§10).
 *
 * TPV gates activation on a configurable checklist that varies by risk level,
 * project, site and work type. Purchase had the approval chain but no checklist
 * at all, so "was the JSA actually reviewed before we let them start?" had
 * nowhere to be recorded — only the approver's own memory.
 *
 * Stored as the same {item-label: bool} map TPV uses, so the two read alike and
 * one rule engine serves both.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_onboardings', 'checklist_state')) {
            return;
        }
        Schema::table('purchase_onboardings', function (Blueprint $t) {
            $t->json('checklist_state')->nullable()->after('remarks');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('purchase_onboardings', 'checklist_state')) {
            return;
        }
        Schema::table('purchase_onboardings', function (Blueprint $t) {
            $t->dropColumn('checklist_state');
        });
    }
};

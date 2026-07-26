<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Templates get a cover page too, so creating/editing a template uses the exact
 * same Cover + Pages editor as building a proposal, and "Use Template" carries
 * the cover straight into the new proposal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_templates', function (Blueprint $table) {
            $table->json('cover')->nullable()->after('terms');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_templates', function (Blueprint $table) {
            $table->dropColumn('cover');
        });
    }
};

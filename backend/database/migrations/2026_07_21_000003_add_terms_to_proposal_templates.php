<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Templates carry default terms so proposals built from them start complete. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposal_templates', function (Blueprint $table) {
            $table->text('terms')->nullable()->after('content');
        });
    }

    public function down(): void
    {
        Schema::table('proposal_templates', function (Blueprint $table) {
            $table->dropColumn('terms');
        });
    }
};

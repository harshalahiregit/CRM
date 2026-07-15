<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keyword tags for knowledge-base articles. Stored as a JSON array of strings —
 * article tags are free-text labels for organisation/search, not shared entities,
 * so a pivot table would be overhead. (Ticket tags stay a separate concept.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->json('tags')->nullable()->after('excerpt');
        });
    }

    public function down(): void
    {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};

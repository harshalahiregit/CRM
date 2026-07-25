<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated, customizable cover page (Page 1) for proposals: a main image,
 * a title, and a heading, shown before the content pages. Stored as one JSON
 * blob — {enabled, image, title, heading} — since it's a small, self-contained
 * unit rendered as a whole.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->json('cover')->nullable()->after('company_logo_url');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn('cover');
        });
    }
};

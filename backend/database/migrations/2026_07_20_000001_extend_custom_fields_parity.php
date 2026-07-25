<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring custom_fields to parity with the legacy tblcustomfields form:
 * bs_column (layout width), only_admin (staff-visibility gate) and
 * display_inline (inline checkbox/radio options). The richer field TYPES
 * (radio, datetime, color, link) need no schema change — `type` is a string.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->unsignedTinyInteger('bs_column')->default(12)->after('field_order'); // 12|6|4|3 → full/half/third/quarter
            $table->boolean('only_admin')->default(false)->after('bs_column');            // visible to admin staff only
            $table->boolean('display_inline')->default(false)->after('only_admin');        // inline options (checkbox/radio)
        });
    }

    public function down(): void
    {
        Schema::table('custom_fields', function (Blueprint $table) {
            $table->dropColumn(['bs_column', 'only_admin', 'display_inline']);
        });
    }
};

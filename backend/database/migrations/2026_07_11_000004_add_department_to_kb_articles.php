<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk Phase 5 — KB articles can optionally be scoped to a department, so KB
 * content can be organised the same way tickets are. Extension only; the rest of
 * the KB module is untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->foreignId('department_id')->nullable()->after('subcategory_id')
                  ->constrained('ticket_departments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_id');
        });
    }
};

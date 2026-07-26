<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integration 3a: link Helpdesk tickets to Projects (owner: Shivam).
 *
 * The spec assumed this column already existed — it did not. Added here as a
 * real nullable FK because Projects is also Shivam's module (both live in the
 * same branch), so unlike customer_id this is a genuine, testable integration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->foreignId('project_id')->nullable()->after('customer_id')
                  ->constrained('projects')->nullOnDelete();
            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('project_id');
        });
    }
};

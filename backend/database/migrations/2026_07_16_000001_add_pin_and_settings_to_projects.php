<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Project pinning + per-project settings (owner: Shivam).
 *
 * pinned_by is a JSON array of user ids rather than a boolean: "Pin Project" is
 * personal (it floats the project up YOUR list), so one shared flag would let one
 * user reorder everyone else's list.
 *
 * visible_tabs / customer_permissions are JSON blobs. They're a bag of booleans
 * read and written whole, never queried by key — columns would mean ~40 of them,
 * and every new tab would need another migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('pinned_by')->nullable()->after('progress_from_tasks');
            $table->json('visible_tabs')->nullable()->after('pinned_by');
            $table->json('customer_permissions')->nullable()->after('visible_tabs');
            $table->boolean('deadline_notified')->default(false)->after('customer_permissions');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['pinned_by', 'visible_tabs', 'customer_permissions', 'deadline_notified']);
        });
    }
};

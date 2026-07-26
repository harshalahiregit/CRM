<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product recall on a batch/lot. A recalled batch is held out of FEFO/issue
 * (its quality_status is moved to 'quarantine') and flagged so the shelf-life
 * and traceability screens can surface it. Idempotent-guarded so it is safe on
 * any DB state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            if (! Schema::hasColumn('inventory_batches', 'recalled_at')) {
                $table->timestamp('recalled_at')->nullable()->after('quality_status');
            }
            if (! Schema::hasColumn('inventory_batches', 'recall_reason')) {
                $table->string('recall_reason')->nullable()->after('recalled_at');
            }
            if (! Schema::hasColumn('inventory_batches', 'recalled_by')) {
                $table->foreignId('recalled_by')->nullable()->after('recall_reason')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('inventory_batches', function (Blueprint $table) {
            if (Schema::hasColumn('inventory_batches', 'recalled_by')) {
                $table->dropConstrainedForeignId('recalled_by');
            }
            $table->dropColumn(['recalled_at', 'recall_reason']);
        });
    }
};

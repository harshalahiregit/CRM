<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Compliance auto-suspension (Doc_2 / Doc_4 — auto-suspend on lapsed statutory
 * cover). `auto_suspended` marks a suspension the system applied (vs an admin's
 * manual one) so the nightly compliance sweep only ever auto-reinstates what it
 * itself suspended. `suspended_at` / `suspension_reason` record when and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (! Schema::hasColumn('vendors', 'auto_suspended')) {
                $table->boolean('auto_suspended')->default(false);
            }
            if (! Schema::hasColumn('vendors', 'suspended_at')) {
                $table->timestamp('suspended_at')->nullable();
            }
            if (! Schema::hasColumn('vendors', 'suspension_reason')) {
                $table->string('suspension_reason', 500)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            foreach (['auto_suspended', 'suspended_at', 'suspension_reason'] as $col) {
                if (Schema::hasColumn('vendors', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

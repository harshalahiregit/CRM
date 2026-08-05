<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The old milestone form had two separate customer-visibility switches:
 * "Show description to customer" and "Hide from customer". We already had the
 * latter; this adds the former so the form can be copied field-for-field.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('project_milestones') && ! Schema::hasColumn('project_milestones', 'show_description_to_customer')) {
            Schema::table('project_milestones', function (Blueprint $table) {
                $table->boolean('show_description_to_customer')->default(false)->after('description');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('project_milestones') && Schema::hasColumn('project_milestones', 'show_description_to_customer')) {
            Schema::table('project_milestones', function (Blueprint $table) {
                $table->dropColumn('show_description_to_customer');
            });
        }
    }
};

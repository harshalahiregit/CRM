<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enterprise Salary Engine — additive extension of hr_salary_structure_lines.
 *
 * Lets a structure line override the component's calculation with a per-line
 * calculation type and formula (e.g. Basic = "50% GROSS", Gratuity = "4.81% BASIC").
 * When null, resolution falls back to the component master exactly as before — so
 * existing structures behave identically.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_salary_structure_lines', function (Blueprint $table) {
            $table->string('calculation_type')->nullable()->after('component_id'); // Fixed|Percentage|Formula|Manual — overrides component when set
            $table->text('formula')->nullable()->after('based_on');
        });
    }

    public function down(): void
    {
        Schema::table('hr_salary_structure_lines', function (Blueprint $table) {
            $table->dropColumn(['calculation_type', 'formula']);
        });
    }
};

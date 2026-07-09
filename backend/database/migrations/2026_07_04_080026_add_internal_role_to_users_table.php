<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Add internal_role field for staff sub-roles (HR Executive, Hiring Manager, Team Lead, etc.)
            $table->string('internal_role')->nullable()->after('role')
                ->comment('Sub-role for staff members: hr_executive, hiring_manager, team_lead, etc.');
            
            // Add department field for organizational structure
            $table->string('department')->nullable()->after('internal_role')
                ->comment('Department: HR, Engineering, Sales, Marketing, etc.');
        });

        // Migrate existing hr_executive and hiring_manager roles to staff with internal_role
        DB::table('users')
            ->whereIn('role', ['hr_executive', 'hiring_manager'])
            ->update([
                'internal_role' => DB::raw('role'),
                'role' => 'staff',
            ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['internal_role', 'department']);
        });
    }
};

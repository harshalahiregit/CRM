<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // SQLite doesn't support MODIFY COLUMN, so we'll just add the new columns
        // The role field already exists and allows any string value in SQLite
        
        // Add manager assignment to manpower requests
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->foreignId('assigned_manager_id')->nullable()->after('requested_by')->constrained('users')->onDelete('set null');
            $table->timestamp('manager_notified_at')->nullable()->after('approved_at');
        });
        
        // Add approval history tracking
        Schema::create('hr_approval_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('manpower_request_id')->constrained('hr_manpower_requests')->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('action', 50); // Created, Assigned, Approved, Rejected, Modified
            $table->text('comment')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->timestamps();
            
            $table->index(['manpower_request_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_approval_history');
        
        Schema::table('hr_manpower_requests', function (Blueprint $table) {
            $table->dropForeign(['assigned_manager_id']);
            $table->dropColumn(['assigned_manager_id', 'manager_notified_at']);
        });
    }
};

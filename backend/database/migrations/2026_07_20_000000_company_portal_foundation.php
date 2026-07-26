<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * External Company Portal — Sprint 1 foundation.
 *
 * Extends the existing hr_external_companies (this module's own table) with the
 * organization profile + company code/type, links portal users to their company
 * via users.external_company_id, and lays the future-ready multi-recruiter pivot.
 * Nothing in the existing recruitment/interview/offer schema is modified.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_external_companies', function (Blueprint $table) {
            $table->string('company_code', 20)->nullable()->after('id');
            $table->string('company_type', 30)->default('Client')->after('name'); // Client|Vendor|Partner|Consultant|Sub Contractor
            $table->string('logo_path')->nullable()->after('website');
            $table->string('company_size', 40)->nullable()->after('industry');
            $table->string('gst_number', 20)->nullable()->after('logo_path');
            $table->string('pan_number', 15)->nullable()->after('gst_number');
            $table->string('secondary_contact_person', 120)->nullable()->after('contact_phone');
            $table->string('secondary_contact_email', 150)->nullable()->after('secondary_contact_person');
            $table->string('secondary_contact_phone', 30)->nullable()->after('secondary_contact_email');
            $table->text('billing_address')->nullable()->after('location');
            $table->text('office_address')->nullable()->after('billing_address');
            $table->unique(['tenant_id', 'company_code']);
        });

        // Link a portal login to its company (N users per company).
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('external_company_id')->nullable()->after('tenant_id')
                ->constrained('hr_external_companies')->nullOnDelete();
        });

        // Future-ready: multiple recruiters per hiring request. The existing
        // hr_hiring_requests.assigned_recruiter_id remains the denormalized
        // PRIMARY recruiter so all current code keeps working; this pivot is the
        // extension point for multi-recruiter assignment.
        Schema::create('hr_hiring_request_recruiters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->boolean('is_primary')->default(false);
            $table->unsignedBigInteger('assigned_by')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamps();
            $table->unique(['hiring_request_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_hiring_request_recruiters');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('external_company_id');
        });
        Schema::table('hr_external_companies', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'company_code']);
            $table->dropColumn([
                'company_code', 'company_type', 'logo_path', 'company_size', 'gst_number', 'pan_number',
                'secondary_contact_person', 'secondary_contact_email', 'secondary_contact_phone',
                'billing_address', 'office_address',
            ]);
        });
    }
};

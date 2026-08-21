<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vendor Master profile completion (Sangoe TPV §5).
 *
 * The doc's Vendor Master carries more identity than the current row: a trade
 * name distinct from the legal entity, category + subcategory, the engagement
 * class (Contractor/Subcontractor/Consultant/Service Provider), parent company,
 * the statutory CIN + Udyam registrations, a separate site address, an emergency
 * contact, and the internal ownership (sponsor + contract owner). All additive
 * and nullable so existing vendors and every current caller are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->string('trade_name')->nullable()->after('legal_name');
            $table->string('subcategory')->nullable()->after('category');
            // Contractor / Subcontractor / Consultant / Service_Provider.
            $table->string('vendor_class', 32)->nullable()->after('subcategory');
            $table->string('parent_company')->nullable()->after('vendor_class');
            $table->string('cin_number', 32)->nullable()->after('pan_number');
            $table->string('udyam_number', 32)->nullable()->after('cin_number');
            $table->string('site_address')->nullable()->after('address');
            $table->string('emergency_contact')->nullable()->after('phone');
            // Internal ownership — who sponsors the relationship, who owns the contract.
            $table->string('internal_sponsor')->nullable()->after('emergency_contact');
            $table->string('contract_owner')->nullable()->after('internal_sponsor');
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $table->dropColumn([
                'trade_name', 'subcategory', 'vendor_class', 'parent_company',
                'cin_number', 'udyam_number', 'site_address', 'emergency_contact',
                'internal_sponsor', 'contract_owner',
            ]);
        });
    }
};

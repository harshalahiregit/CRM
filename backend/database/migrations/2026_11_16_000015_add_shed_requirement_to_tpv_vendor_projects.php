<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shed requirement capture on the TPV vendor↔project engagement. The business
 * builds industrial sheds, so each vendor project carries the shed's spec: site
 * location, size (L×W), height, purpose, and the yes/no scope items (side wall,
 * flooring, footing, office/toilet) plus the gate/shutter size. All nullable and
 * additive — an engagement without a shed spec is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tpv_vendor_projects', function (Blueprint $table) {
            $table->string('shed_site_location', 255)->nullable()->after('notes');
            $table->decimal('shed_length', 8, 2)->nullable()->after('shed_site_location');   // metres
            $table->decimal('shed_width', 8, 2)->nullable()->after('shed_length');            // metres
            $table->string('shed_height', 40)->nullable()->after('shed_width');               // free text, e.g. "19 Meter"
            $table->string('shed_purpose', 160)->nullable()->after('shed_height');            // e.g. Industrial Plant
            $table->boolean('shed_side_wall')->nullable()->after('shed_purpose');             // required or not
            $table->boolean('shed_flooring')->nullable()->after('shed_side_wall');            // required or not
            $table->string('shed_gate_shutter_size', 120)->nullable()->after('shed_flooring');
            $table->boolean('shed_footing_done')->nullable()->after('shed_gate_shutter_size');
            $table->boolean('shed_office_toilet')->nullable()->after('shed_footing_done');    // office/toilet required
        });
    }

    public function down(): void
    {
        Schema::table('tpv_vendor_projects', function (Blueprint $table) {
            $table->dropColumn([
                'shed_site_location', 'shed_length', 'shed_width', 'shed_height', 'shed_purpose',
                'shed_side_wall', 'shed_flooring', 'shed_gate_shutter_size', 'shed_footing_done', 'shed_office_toilet',
            ]);
        });
    }
};

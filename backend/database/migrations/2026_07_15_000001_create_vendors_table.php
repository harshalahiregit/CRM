<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unified vendor master — one entity for both TPV (onboarding/HSSE workflow)
 * and Purchase (procurement transactions). Legacy kept these split across
 * `tblvendors` and `pur_vendor`; that split is deliberately not carried forward.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            // The login User (role = vendor | third_party_vendor). Null until the
            // vendor is invited to the self-service portal.
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('account_manager_id')->nullable()->index();

            $table->string('vendor_code');
            $table->string('company_name');
            $table->string('legal_name')->nullable();
            $table->string('vendor_type')->default('standard');   // standard | temporary
            $table->json('engagements')->nullable();              // ['purchase','tpv']

            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->string('category')->nullable();

            $table->string('registration_number')->nullable();
            $table->string('gst_number')->nullable();
            $table->string('pan_number')->nullable();

            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('pincode')->nullable();

            $table->string('status')->default('Draft')->index();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['tenant_id', 'vendor_code']);
        });

        Schema::create('vendor_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('vendor_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->index();
            // gst | pan | insurance_wcp | pf | esic | bocw | clr | mlwf | mscb |
            // udyam | company_registration | loi_wo_po
            $table->string('type');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime')->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status')->default('Pending')->index();
            $table->text('remarks')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_documents');
        Schema::dropIfExists('vendor_contacts');
        Schema::dropIfExists('vendors');
    }
};

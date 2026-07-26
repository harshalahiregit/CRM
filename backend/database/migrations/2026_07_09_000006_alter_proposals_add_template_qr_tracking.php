<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Note: proposals.portal_token already exists and serves the same
        // purpose as the plan's "public_view_token" — reused instead of
        // adding a duplicate column.
        Schema::table('proposals', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->nullable()->after('id');
            $table->text('qr_code_data')->nullable()->after('portal_token');
            $table->boolean('public_view_otp_enabled')->default(false)->after('qr_code_data');
            $table->dateTime('email_opened_at')->nullable()->after('public_view_otp_enabled');
            $table->string('email_opened_device')->nullable()->after('email_opened_at');
            $table->unsignedInteger('email_opened_count')->default(0)->after('email_opened_device');
            $table->text('pdf_header')->nullable()->after('email_opened_count');
            $table->text('pdf_footer')->nullable()->after('pdf_header');
            $table->string('company_logo_url', 500)->nullable()->after('pdf_footer');
            $table->string('company_stamp_url', 500)->nullable()->after('company_logo_url');
        });

        Schema::create('proposal_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category')->nullable();
            $table->longText('content')->nullable();
            $table->string('thumbnail_url', 500)->nullable();
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_templates');

        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn([
                'template_id', 'qr_code_data', 'public_view_otp_enabled',
                'email_opened_at', 'email_opened_device', 'email_opened_count',
                'pdf_header', 'pdf_footer', 'company_logo_url', 'company_stamp_url',
            ]);
        });
    }
};

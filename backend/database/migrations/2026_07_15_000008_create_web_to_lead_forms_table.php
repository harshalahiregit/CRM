<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_to_lead_forms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('form_key', 40)->unique();
            $table->string('name');
            // form_data = ordered field layout, e.g.
            // [{key:'name',label:'Full Name',type:'text',required:true}, …]
            $table->json('form_data')->nullable();
            $table->foreignId('lead_source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('lead_status_id')->nullable()->constrained('lead_statuses')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('success_message')->nullable();
            $table->string('redirect_url')->nullable();
            $table->boolean('allow_duplicate')->default(false);
            $table->boolean('recaptcha_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('submissions_count')->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_to_lead_forms');
    }
};

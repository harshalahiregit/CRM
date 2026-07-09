<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // ── Lead Pipeline Statuses ──────────────────────────────────
        Schema::create('lead_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 9)->default('#3b82f6');   // hex color
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_default')->default(false);     // auto-assign to new leads
            $table->boolean('is_won_status')->default(false);  // marks "converted/won"
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('sort_order');
        });

        // ── Lead Sources ────────────────────────────────────────────
        Schema::create('lead_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });

        // ── Leads (Main Table) ──────────────────────────────────────
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('hash', 40)->unique();                // public URL identifier

            // Core contact info
            $table->string('name');                               // REQUIRED
            $table->string('title')->nullable();                  // job title / position
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();

            // Value & scoring
            $table->decimal('lead_value', 15, 2)->default(0);    // potential revenue
            $table->unsignedInteger('lead_score')->default(0);   // 0-100 scoring
            $table->enum('lead_temperature', ['Hot', 'Warm', 'Cold'])->default('Cold');
            $table->unsignedTinyInteger('conversion_chance')->default(0); // 0-100 %

            // Location
            $table->string('country')->nullable();
            $table->string('state')->nullable();
            $table->string('city')->nullable();
            $table->string('zip', 20)->nullable();
            $table->text('address')->nullable();

            // Pipeline & assignment
            $table->foreignId('status_id')->nullable()->constrained('lead_statuses')->nullOnDelete();
            $table->foreignId('source_id')->nullable()->constrained('lead_sources')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users');
            $table->boolean('is_public')->default(false);
            $table->date('last_contact_date')->nullable();
            $table->timestamp('date_assigned')->nullable();

            // Lost / Junk tracking
            $table->boolean('lost')->default(false);
            $table->boolean('junk')->default(false);
            $table->unsignedBigInteger('last_lead_status_id')->nullable(); // status before lost/junk

            // Conversion
            $table->timestamp('date_converted')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();  // set after conversion

            // Kanban ordering
            $table->unsignedInteger('lead_order')->default(0);

            // Referral tracking
            $table->enum('referral_type', ['none', 'percentage', 'fixed'])->default('none');
            $table->decimal('referral_value', 12, 2)->default(0);
            $table->string('referral_contact')->nullable();       // who referred

            // Tags
            $table->text('tags')->nullable();                     // comma-separated

            $table->timestamps();
            $table->softDeletes();

            // Performance indexes
            $table->index('tenant_id');
            $table->index('name');
            $table->index('company');
            $table->index('email');
            $table->index('assigned_to');
            $table->index('status_id');
            $table->index('source_id');
            $table->index('lead_score');
            $table->index('lead_temperature');
            $table->index('last_contact_date');
            $table->index('lead_order');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('lead_sources');
        Schema::dropIfExists('lead_statuses');
    }
};

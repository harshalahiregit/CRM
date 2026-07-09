<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Lead Goals / Targets ────────────────────────────────────
        Schema::create('lead_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // null = team-wide
            $table->enum('type', ['monthly', 'quarterly', 'yearly']);
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('target_count')->default(0);        // target # of leads
            $table->decimal('target_value', 15, 2)->default(0);         // target ₹ value
            $table->unsignedInteger('achieved_count')->default(0);
            $table->decimal('achieved_value', 15, 2)->default(0);
            $table->enum('incentive_type', ['none', 'fixed', 'percentage'])->default('none');
            $table->decimal('incentive_value', 12, 2)->default(0);      // ₹ or %
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index(['period_start', 'period_end']);
        });

        // ── Lead Questionnaires ─────────────────────────────────────
        Schema::create('lead_questionnaires', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
        });

        // ── Questionnaire Fields (form builder) ─────────────────────
        Schema::create('lead_questionnaire_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('questionnaire_id')->constrained('lead_questionnaires')->cascadeOnDelete();
            $table->string('label');
            $table->enum('field_type', [
                'text', 'textarea', 'number', 'email', 'phone', 'date',
                'select', 'multi_select', 'checkbox', 'radio', 'file',
            ]);
            $table->json('options')->nullable();       // for select/multi_select/radio/checkbox
            $table->string('placeholder')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('questionnaire_id');
        });

        // ── Questionnaire Responses ─────────────────────────────────
        Schema::create('lead_questionnaire_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('questionnaire_id')->constrained('lead_questionnaires')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->json('answers');                   // { field_id: value, ... }
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('questionnaire_id');
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_questionnaire_responses');
        Schema::dropIfExists('lead_questionnaire_fields');
        Schema::dropIfExists('lead_questionnaires');
        Schema::dropIfExists('lead_goals');
    }
};

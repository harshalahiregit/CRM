<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Lead Activity Log (Audit Trail) ─────────────────────────
        Schema::create('lead_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->enum('type', [
                'created', 'updated', 'status_changed', 'assigned',
                'note_added', 'converted', 'lost', 'junk', 'restored',
                'contact', 'proposal_sent', 'questionnaire_submitted',
            ]);
            $table->text('description');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->foreignId('performed_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('lead_id');
            $table->index('type');
            $table->index('created_at');
        });

        // ── Lead Notes ──────────────────────────────────────────────
        Schema::create('lead_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->text('content');
            $table->date('contact_date')->nullable();  // optional: "when was this contact made?"
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('lead_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
        Schema::dropIfExists('lead_activities');
    }
};

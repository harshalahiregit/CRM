<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Unified Sales Activity Timeline (polymorphic) ────────────
        // Cross-entity audit feed for proposals/estimates/invoices/contracts/
        // tasks/deals. Leads keep their own `lead_activities` table (untouched);
        // SalesActivityService merges both when the subject is a lead.
        // `subject_type` stores the fully-qualified class name (same convention
        // as sales_line_items.lineable_type) — no global morph map is used.
        Schema::create('sales_activities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('type');
            $table->text('description');
            $table->string('old_value')->nullable();
            $table->string('new_value')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['subject_type', 'subject_id']);
            $table->index('type');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_activities');
    }
};

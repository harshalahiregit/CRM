<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Somebody asking for a demo.
 *
 * SangoeTrack collects these from its marketing site and the CRM could only view
 * them through a proxy. The fields match theirs — name, company, email, phone,
 * address, headcount, notes, status — plus the two they lack and everybody ends
 * up wanting: who is handling it, and when it was last touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_demo_requests', function (Blueprint $table) {
            $table->id();
            // Nullable: a request can arrive before anybody knows which workspace
            // it belongs to, which is the normal case for an inbound enquiry.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();

            $table->string('name', 120);
            $table->string('company_name', 160)->nullable();
            $table->string('email', 191)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('address', 500)->nullable();
            $table->unsignedInteger('num_employees')->nullable();

            // What the enquirer said, distinct from what staff wrote afterwards.
            // SangoeTrack has one `notes` and the two get mixed together.
            $table->text('message')->nullable();
            $table->text('notes')->nullable();

            $table->enum('status', ['new', 'contacted', 'scheduled', 'converted', 'declined'])->default('new');
            $table->timestamp('demo_at')->nullable();

            $table->unsignedBigInteger('assigned_to')->nullable()->index();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->string('source', 60)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_demo_requests');
    }
};

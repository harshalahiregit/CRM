<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * STOS-MAINT — workshop job cards.
 *
 * One row is one job card: the complaint that brought the vehicle in, what the
 * workshop found, and what it cost. All three money columns are DECIMAL(18,2)
 * (golden rule 5).
 *
 * `total_cost` is stored rather than computed on read. A job card is a document
 * that gets signed off, and it may legitimately differ from parts + labour —
 * a discount, a warranty credit, a rounded settlement. The domain service sets
 * it; nothing recalculates it behind the workshop's back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();

            $table->string('job_card_number', 40);
            $table->foreignId('vehicle_id')->constrained('vehicles')->cascadeOnDelete();

            // What the driver reported, and what the workshop actually found.
            $table->text('complaint')->nullable();
            $table->text('diagnosis')->nullable();

            $table->decimal('parts_cost', 18, 2)->default(0);
            $table->decimal('labour_cost', 18, 2)->default(0);
            $table->decimal('total_cost', 18, 2)->default(0);

            // open | in_progress | awaiting_parts | completed | cancelled
            $table->string('status', 20)->default('open');

            $table->timestamps();

            $table->unique(['company_id', 'job_card_number'], 'maintenance_company_job_card_unique');
            $table->index(['company_id', 'vehicle_id']);
            $table->index(['company_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_jobs');
    }
};

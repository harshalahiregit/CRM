<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One declared investment line: a section, what the employee declared, and what
 * payroll actually verified against proof.
 *
 * The two amounts are separate columns on purpose. Overwriting `declared_amount`
 * with the verified figure would destroy the record of what was claimed, which is
 * exactly what an audit needs. Until proof is checked, `verified_amount` is null —
 * distinct from a verified zero, which means "claimed, and rejected".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hr_investment_declaration_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('declaration_id')->index();

            $table->string('section', 20);          // 80C, 80D, 24B … (TaxSections)
            $table->string('particulars', 191)->nullable();  // free text: "LIC policy 1234"
            $table->decimal('declared_amount', 14, 2)->default(0);
            $table->decimal('verified_amount', 14, 2)->nullable();
            $table->boolean('proof_submitted')->default(false);
            $table->string('proof_path', 500)->nullable();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'declaration_id', 'section'], 'hr_inv_item_section_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_investment_declaration_items');
    }
};

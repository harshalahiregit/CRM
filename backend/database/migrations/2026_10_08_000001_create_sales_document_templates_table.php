<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reusable templates for the documents that have a line-items grid: invoices,
 * estimates and proposals.
 *
 * Credit notes are excluded on purpose — that form has no grid, it derives one
 * line from a reason and an amount, so there is nothing to template.
 *
 * Proposal templates deliberately carry content and terms but NEVER pricing.
 * These are the opposite case — the bulk of an invoice form is its line items,
 * and re-keying the same package of items on every document is the actual chore.
 * So a template here owns real line items.
 *
 * They live in `sales_line_items` via the existing polymorphic `lineable`, the
 * same table proposals/estimates/invoices already use, so a template's items and
 * a document's items are literally the same shape and copy across exactly.
 *
 * One table for all three document types with a `doc_type` discriminator rather
 * than three near-identical tables: the columns are identical and the pickers
 * only ever query one type at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_document_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // invoice | estimate | proposal. Kept as a plain string rather than a
            // native enum so SQLite (dev) and MySQL behave the same, and so adding
            // a type later doesn't need an ALTER; the allowed set is validated in
            // SalesDocumentTemplate::TYPES.
            $table->string('doc_type', 20);

            $table->string('name');
            $table->string('description')->nullable();

            // Document defaults the template pre-fills.
            $table->text('terms')->nullable();
            $table->text('adminnote')->nullable();
            $table->text('clientnote')->nullable();
            $table->string('currency', 8)->nullable();

            // Document-level discount, mirroring the document tables.
            $table->string('discount_type', 20)->nullable();
            $table->string('discount_mode', 20)->nullable();
            $table->decimal('discount_value', 15, 2)->default(0);

            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Every list is "this tenant's templates for this document type".
            $table->index(['tenant_id', 'doc_type', 'sort_order']);
            // A name is how people tell templates apart, so keep it unique per
            // tenant AND type — "Standard" can exist for both invoices and estimates.
            $table->unique(['tenant_id', 'doc_type', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_document_templates');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Multi-page rich content, polymorphic ─────────────────────
        // Shared by Proposals, Proposal Templates and Sales Contracts.
        // `pageable_type` stores the FQCN (same convention as
        // sales_line_items.lineable_type — no morph map).
        // `content` holds server-sanitized HTML (see App\Support\HtmlSanitizer).
        Schema::create('content_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('pageable_type');
            $table->unsignedBigInteger('pageable_id');
            $table->string('title');
            $table->longText('content')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['pageable_type', 'pageable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_pages');
    }
};

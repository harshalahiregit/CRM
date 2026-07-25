<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk module — feature extension (owner: Shivam).
 *
 * SQLite-safe, additive, and reversible. Never edits an earlier migration.
 *  - tickets: widen priority (adds "urgent"), rename deadline → due_date, add source
 *  - kb: 3-level hierarchy (kb_subcategories) + article publishing/rich content
 *  - helpdesk_widget_settings: per-tenant public key for the embeddable widget
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Tickets: priority(urgent), due_date, source ─────────────
        Schema::table('tickets', function (Blueprint $table) {
            // enum → plain string so "urgent" is allowed (the FormRequest enforces
            // the value set). SQLite can't alter a CHECK constraint in place, so a
            // string column is the portable choice.
            $table->string('priority', 20)->default('medium')->change();
            $table->renameColumn('deadline', 'due_date');
            $table->string('source', 20)->default('internal')->after('customer_id'); // internal | widget
            // External (widget) submitters have no customer record — capture contact here.
            $table->string('requester_name')->nullable()->after('source');
            $table->string('requester_email')->nullable()->after('requester_name');
        });

        // ── KB sub-categories (category → sub-category → article) ────
        Schema::create('kb_subcategories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('kb_categories')->cascadeOnDelete();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('category_id');
            $table->unique(['tenant_id', 'category_id', 'slug']);
        });

        // ── KB articles: sub-category link + publishing + richness ───
        Schema::table('kb_articles', function (Blueprint $table) {
            $table->foreignId('subcategory_id')->nullable()->after('category_id')
                  ->constrained('kb_subcategories')->nullOnDelete();
            $table->string('excerpt', 500)->nullable()->after('title');
            $table->boolean('is_published')->default(false)->after('content');
            $table->string('public_slug', 64)->nullable()->unique()->after('is_published');
            $table->timestamp('published_at')->nullable()->after('public_slug');

            $table->index('subcategory_id');
            $table->index('is_published');
        });

        // ── Public widget settings (per tenant) ─────────────────────
        Schema::create('helpdesk_widget_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('public_key', 64)->unique();      // embed identifies tenant with this
            $table->string('allowed_origin')->nullable();     // CORS/referer allowlist (null = any)
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_widget_settings');

        Schema::table('kb_articles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subcategory_id');
            $table->dropColumn(['excerpt', 'is_published', 'public_slug', 'published_at']);
        });

        Schema::dropIfExists('kb_subcategories');

        Schema::table('tickets', function (Blueprint $table) {
            $table->renameColumn('due_date', 'deadline');
            $table->dropColumn(['source', 'requester_name', 'requester_email']);
            // priority is left as a string on rollback — harmless and avoids a
            // second lossy CHECK-constraint rebuild on SQLite.
        });
    }
};

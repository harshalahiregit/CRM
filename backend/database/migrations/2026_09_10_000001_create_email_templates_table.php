<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email Templates — tenant CUSTOMISATIONS only.
 *
 * The shipped ("system") templates live in code, in EmailTemplateRegistry. A row
 * here is an override of one of those keys for one tenant. That split is what makes
 * "restore default" trivially safe and non-destructive: deleting the row restores
 * the shipped template, and no other tenant customisation is touched. It also means
 * a shipped template can be improved in a release and every tenant that never
 * customised it picks the improvement up automatically.
 *
 * Row-level multi-tenancy, no DB foreign keys — matching the rest of the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_templates')) {
            Schema::create('email_templates', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->string('key', 100);
                $table->string('category', 60);
                $table->string('name', 150);

                $table->string('subject', 255);
                $table->longText('body_html')->nullable();
                $table->longText('body_text')->nullable();
                $table->string('description', 500)->nullable();

                $table->boolean('enabled')->default(true);
                // Bumped on every save so a tenant can see their template has drifted
                // from the shipped one (version 1 == first customisation).
                $table->unsignedInteger('version')->default(1);
                // true  = an override of a registry (shipped) template
                // false = a tenant-authored template with no shipped counterpart
                $table->boolean('is_system')->default(true);
                $table->string('locale', 10)->nullable();

                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();

                $table->unique(['tenant_id', 'key'], 'email_templates_tenant_key_unique');
                $table->index(['tenant_id', 'category']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('email_templates');
    }
};

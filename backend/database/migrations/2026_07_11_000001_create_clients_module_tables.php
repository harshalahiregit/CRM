<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer module — core tables.
 *
 * Modeled on the legacy CRM's tblclients/tblcontacts split: the CLIENT is the
 * company (no login of its own, can exist with zero contacts — bulk-imported
 * marketing data), CONTACTS are people under a client. Portal login is NOT
 * stored here — a contact may link to a users row (role=client) via user_id.
 *
 * Cross-module note: `clients.vendor_id` intentionally has NO foreign key —
 * the Vendor entity is owned by the TPV module (Harshal) and doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('company');
            $table->string('gst_number', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('website')->nullable();
            $table->string('parent_company')->nullable();
            $table->decimal('opening_balance', 15, 2)->default(0);  // manual opening balance
            $table->date('opening_balance_date')->nullable();       // "balance as of"
            $table->unsignedBigInteger('vendor_id')->nullable();   // TPV link — no FK, module not built yet
            $table->unsignedBigInteger('lead_id')->nullable();     // source lead if converted

            // Registered address (Details tab)
            $table->string('address')->nullable();
            $table->string('city', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('zip', 20)->nullable();
            $table->string('country', 100)->nullable();

            // Billing & Shipping tab
            $table->string('billing_street')->nullable();
            $table->string('billing_city', 100)->nullable();
            $table->string('billing_state', 100)->nullable();
            $table->string('billing_zip', 20)->nullable();
            $table->string('billing_country', 100)->nullable();
            $table->string('shipping_street')->nullable();
            $table->string('shipping_city', 100)->nullable();
            $table->string('shipping_state', 100)->nullable();
            $table->string('shipping_zip', 20)->nullable();
            $table->string('shipping_country', 100)->nullable();

            // Profile extras (new spec: social links, key dates)
            $table->json('social_links')->nullable();              // {facebook, linkedin, twitter, instagram, website...}
            $table->date('foundation_date')->nullable();
            $table->date('dob')->nullable();
            $table->date('anniversary_date')->nullable();

            $table->string('default_currency', 3)->default('INR');
            $table->string('default_language', 40)->nullable();
            $table->boolean('show_primary_contact')->default(false);
            $table->boolean('active')->default(true);
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Composite indexes matching the list query (filter tenant[+active], sort company/created_at).
            $table->index(['tenant_id', 'active']);
            $table->index(['tenant_id', 'company']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::create('client_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete(); // portal login (role=client)
            $table->string('first_name');
            $table->string('last_name')->nullable();
            $table->string('email');
            $table->string('phone', 30)->nullable();
            $table->string('title', 100)->nullable();              // job position
            $table->boolean('is_primary')->default(false);
            $table->boolean('active')->default(true);
            $table->json('email_notifications')->nullable();       // {invoice, estimate, credit_note, proposal, ticket}
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'email']);
            $table->index('client_id');
        });

        Schema::create('client_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 100);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('client_group_client', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_group_id')->constrained('client_groups')->cascadeOnDelete();

            $table->unique(['client_id', 'client_group_id']);
        });

        // Account-manager pivot. No tenant_id — it's derivable via client_id and
        // a pivot can't use the BelongsToTenant auto-fill; scope through the client.
        Schema::create('customer_admins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete(); // internal staff account manager
            $table->timestamps();

            $table->unique(['client_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_admins');
        Schema::dropIfExists('client_group_client');
        Schema::dropIfExists('client_groups');
        Schema::dropIfExists('client_contacts');
        Schema::dropIfExists('clients');
    }
};

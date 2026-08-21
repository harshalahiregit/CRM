<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer 360 — the identity fields the module has never had.
 *
 * Sources, per docs/CUTOMER.docx and the zignls CRM this replaced:
 *
 *  • customer_type restores `tblclients.client_type`, which existed in the old
 *    CRM and was dropped in the rebuild (§13).
 *  • contact `role` restores `tblcontacts.contact_role_id`, likewise lost (§11).
 *  • account owner, tier, industry, region and the contact depth fields are
 *    new, asked for by §11–§13.
 *  • payment_terms, relationship_started_at and lifecycle_status are added here
 *    because Customer Health (§8) cannot be computed honestly without them —
 *    see the notes on each column.
 *
 * Every column is nullable. This runs against live data where none of it is
 * known yet, and a NOT NULL default would silently assert facts about real
 * customers that nobody has actually established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            // ── Ownership (§12) ──────────────────────────────────────────
            // The old CRM had tblcustomer_admins: a set of staff with access,
            // but no single name answerable for the account. These are that
            // missing accountability, kept separate from the access list.
            $table->unsignedBigInteger('account_owner_id')->nullable()->after('added_by')->index();
            $table->unsignedBigInteger('secondary_owner_id')->nullable()->after('account_owner_id');
            $table->unsignedBigInteger('customer_success_owner_id')->nullable()->after('secondary_owner_id');
            $table->string('business_unit', 80)->nullable()->after('customer_success_owner_id');
            $table->string('region', 80)->nullable()->after('business_unit');

            // ── Classification (§13) ─────────────────────────────────────
            // Real columns rather than custom fields so they can be filtered,
            // grouped and reported on. The option lists are tenant-editable.
            $table->string('customer_type', 40)->nullable()->after('region')->index();
            $table->string('customer_tier', 40)->nullable()->after('customer_type')->index();
            $table->string('industry', 80)->nullable()->after('customer_tier');

            // ── Health inputs (§8) ───────────────────────────────────────
            // Payment behaviour cannot be scored without the agreed terms:
            // paying on day 40 is excellent on Net 45 and delinquent on Net 15.
            $table->string('payment_terms', 40)->nullable()->after('industry');

            // Revenue trend needs a baseline, and a two-month-old customer
            // should be excluded from trend scoring rather than scored badly.
            $table->date('relationship_started_at')->nullable()->after('payment_terms');

            // `active` is a boolean, which cannot distinguish a customer who
            // never started from one who left. Without this a churned account
            // scores full marks for having no open tickets.
            $table->string('lifecycle_status', 20)->nullable()->after('relationship_started_at')->index();
        });

        Schema::table('client_contacts', function (Blueprint $table) {
            // §11. `title` already holds the job title ("Position" in the old
            // CRM) and stays as Designation; department is where they sit and
            // role is what they are to us — the document lists all three.
            $table->string('department', 80)->nullable()->after('title');
            $table->string('role', 60)->nullable()->after('department')->index();
            $table->string('whatsapp', 30)->nullable()->after('phone');
            $table->boolean('is_decision_maker')->default(false)->after('is_primary');
            $table->string('influence', 20)->nullable()->after('is_decision_maker');
            $table->boolean('is_secondary')->default(false)->after('influence');

            // Who they report to, within the same customer. Decision Maker and
            // Influence say little without the reporting line that gives them
            // context, so this is what turns a contact list into an org map.
            $table->unsignedBigInteger('reports_to')->nullable()->after('is_secondary');

            // Derived, never typed. "Relationship risk" (§9) has no signal in
            // the system; time since the last logged activity is the honest proxy.
            $table->timestamp('last_contacted_at')->nullable()->after('reports_to');
        });

        Schema::table('client_notes', function (Blueprint $table) {
            // §16. `visibility` already exists and stays as-is; only the type
            // taxonomy was missing.
            $table->string('type', 30)->nullable()->after('content')->index();
            // No customer_visible flag here on purpose: `visibility` already has
            // a 'client' value that means exactly that, and adding a second way
            // to express one idea is the duplication §6 warns about. Attachments
            // DO get the flag below, because they have no visibility column.
        });

        Schema::table('client_attachments', function (Blueprint $table) {
            // §15. The Vault holds credentials, not documents, so the
            // confidential-document need is met here rather than by widening it.
            $table->boolean('confidential')->default(false)->after('file_size')->index();
            $table->boolean('customer_visible')->default(false)->after('confidential');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $t) => $t->dropColumn([
            'account_owner_id', 'secondary_owner_id', 'customer_success_owner_id',
            'business_unit', 'region', 'customer_type', 'customer_tier', 'industry',
            'payment_terms', 'relationship_started_at', 'lifecycle_status',
        ]));
        Schema::table('client_contacts', fn (Blueprint $t) => $t->dropColumn([
            'department', 'role', 'whatsapp', 'is_decision_maker', 'influence',
            'is_secondary', 'reports_to', 'last_contacted_at',
        ]));
        Schema::table('client_notes', fn (Blueprint $t) => $t->dropColumn(['type']));
        Schema::table('client_attachments', fn (Blueprint $t) => $t->dropColumn(['confidential', 'customer_visible']));
    }
};

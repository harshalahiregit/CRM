<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The rest of the Customer 360 document: Activities (§4), Complaints and
 * Escalations (§17 SERVICE), Customer Experience (§10), Domain Manager and
 * customer Purchase Orders (§1, §17), and the Vault audit trail (§15).
 *
 * Every table here is Customer's own. Nothing reads or writes another module's
 * tables — Projects, Tasks, Tickets, Shipments and Finance stay where they are
 * and Customer reads them through a service seam, per §6.
 *
 * Two of these exist specifically to make Customer Health honest. §8 scores
 * "complaint frequency" and "customer feedback", and both returned "No data
 * yet" for every customer because the system had nowhere to record either.
 */
return new class extends Migration
{
    public function up(): void
    {
        // §4 — Activities. Distinct from Meetings: a call or a WhatsApp is a
        // touch, not an event with an agenda and minutes.
        Schema::create('client_activities', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            // Which person at the customer this touch was with. Nullable: a
            // site visit is with the company, not necessarily one named contact.
            $table->unsignedBigInteger('client_contact_id')->nullable()->index();
            $table->string('type', 30)->index();          // Call, Email, WhatsApp, Visit, Meeting, Follow-up, Note, Escalation
            $table->string('direction', 10)->nullable();  // Inbound / Outbound — meaningless for Note, hence nullable
            $table->string('subject');
            $table->text('summary')->nullable();
            $table->string('outcome', 30)->nullable();    // Connected, No answer, Rescheduled, Resolved…
            $table->timestamp('occurred_at')->index();
            $table->integer('duration_minutes')->nullable();
            // A touch that needs another touch. The follow-up itself becomes a
            // reminder rather than a second activity nobody has done yet.
            $table->date('follow_up_on')->nullable()->index();
            $table->boolean('follow_up_done')->default(false);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id', 'occurred_at']);
        });

        // §17 SERVICE — Complaints and Escalations. One table, because an
        // escalation is a complaint that has been raised a level, and splitting
        // them would mean copying a row to escalate it.
        Schema::create('client_complaints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('reference', 40)->nullable();
            $table->string('kind', 20)->default('Complaint')->index(); // Complaint | Escalation
            $table->string('subject');
            $table->text('description')->nullable();
            $table->string('category', 40)->nullable();   // Service, Delivery, Billing, Quality, Conduct…
            $table->string('severity', 20)->default('Medium')->index();
            $table->string('status', 20)->default('Open')->index();    // Open, Investigating, Resolved, Closed
            // Where it came from, so a complaint raised on a ticket does not
            // become a second unrelated record. Deliberately a loose reference:
            // Customer must not hold a foreign key into Shivan's tickets table.
            $table->string('source_type', 30)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->timestamp('raised_at')->index();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id', 'status']);
        });

        // §10 — Customer Experience. CSAT is per-interaction (0-5), NPS is
        // relationship-wide (0-10). Storing both in one table with a `metric`
        // column keeps "what did this customer last tell us" a single query.
        Schema::create('client_feedback', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('client_contact_id')->nullable()->index();
            $table->string('metric', 10)->index();        // CSAT | NPS
            $table->unsignedTinyInteger('score');         // CSAT 0-5, NPS 0-10
            $table->text('comments')->nullable();
            $table->string('about_type', 30)->nullable(); // what was being rated, if anything
            $table->unsignedBigInteger('about_id')->nullable();
            // Surveys the customer answers in the portal vs. a score staff
            // recorded from a phone call. Health should weigh these the same,
            // but a human reading the list needs to know which is which.
            $table->string('collected_via', 20)->default('portal'); // portal | staff | email
            $table->timestamp('responded_at')->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'client_id', 'metric', 'responded_at']);
        });

        // Domain Manager (§1, §17 ADMIN) — the customer's domains we look after.
        Schema::create('client_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('domain');
            $table->string('registrar')->nullable();
            $table->date('registered_on')->nullable();
            $table->date('expires_on')->nullable()->index();
            $table->boolean('auto_renew')->default(false);
            $table->string('dns_provider')->nullable();
            $table->string('hosting_provider')->nullable();
            $table->date('ssl_expires_on')->nullable();
            $table->string('status', 20)->default('Active')->index();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id']);
        });

        // Customer Purchase Orders (§1, §17 COMMERCIAL).
        //
        // NOT the same thing as the Purchase module's purchase_orders, which are
        // orders WE place with vendors. This is the PO the customer issues to
        // us — the reference finance quotes on every invoice against it, and the
        // value we are allowed to bill up to.
        Schema::create('client_purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('po_number');
            $table->date('po_date')->nullable();
            $table->date('valid_until')->nullable()->index();
            $table->string('currency', 8)->default('INR');
            $table->decimal('value', 15, 2)->default(0);
            // Kept as a stored figure rather than a live sum over invoices:
            // Customer must not reach into Sales to compute it, and the service
            // layer refreshes it from the invoice seam instead.
            $table->decimal('consumed', 15, 2)->default(0);
            $table->string('status', 20)->default('Open')->index(); // Open, Partially Billed, Exhausted, Closed, Cancelled
            $table->unsignedBigInteger('contract_id')->nullable();
            $table->text('scope')->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'client_id', 'status']);
        });

        // §15 — the Vault's audit trail.
        //
        // The document asks for Vault to differ from Files by having "stronger
        // RBAC and audit trails". The RBAC existed; this is the other half.
        // A credential's value is only meaningful once revealed, so the event
        // worth recording is the reveal, not the page view.
        Schema::create('client_vault_access_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('vault_entry_id')->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('action', 20);                 // revealed, copied, created, updated, deleted
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();

            $table->index(['tenant_id', 'vault_entry_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_vault_access_log');
        Schema::dropIfExists('client_purchase_orders');
        Schema::dropIfExists('client_domains');
        Schema::dropIfExists('client_feedback');
        Schema::dropIfExists('client_complaints');
        Schema::dropIfExists('client_activities');
    }
};

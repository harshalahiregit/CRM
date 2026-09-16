<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SIRE — which customer an issue affects.
 *
 * "Which customers are hitting this" is the question that turns a backlog into a
 * priority order. A P3 nobody has mentioned and a P3 three customers have raised
 * this month are not the same defect, and until now SIRE could not tell them
 * apart.
 *
 * NOT related_type/related_id. Those already carry the ENTITY the reporter was
 * looking at when it broke -- the lead, the purchase order, the ticket -- which
 * is a different fact and is set automatically from captured screen context.
 * Overloading one pair of columns with two meanings is how a register stops
 * being able to answer either question.
 *
 * A plain unsigned id with no foreign key, following the host's row-level
 * multi-tenancy convention: the customer lives on the other side of
 * SireCustomerProvider, and a host that resolves customers from an external
 * system has no `clients` table for a constraint to point at.
 *
 * Nullable, and it stays nullable. An internal-only workspace has no customers
 * at all, and most defects are nobody's in particular.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sire_reports') || Schema::hasColumn('sire_reports', 'customer_id')) {
            return;
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('customer_id')->nullable()->after('related_id');

            // The read is always "this tenant's issues for this customer", which
            // is the register filtered. Short name: MySQL caps identifiers at 64
            // characters and Laravel's generated ones run over.
            $table->index(['tenant_id', 'customer_id'], 'sire_reports_customer');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('sire_reports') || ! Schema::hasColumn('sire_reports', 'customer_id')) {
            return;
        }

        Schema::table('sire_reports', function (Blueprint $table) {
            $table->dropIndex('sire_reports_customer');
            $table->dropColumn('customer_id');
        });
    }
};

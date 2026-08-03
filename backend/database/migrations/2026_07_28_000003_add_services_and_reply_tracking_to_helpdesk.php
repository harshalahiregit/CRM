<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Helpdesk polish:
 *  - ticket_services: a tenant-managed "Service" list (Website, Software, …) so a
 *    ticket can be classified by the service it's about (mirrors departments).
 *  - tickets.service_id: the chosen service.
 *  - tickets.last_reply_at: stamped on every reply so the queue can show a real
 *    "Last reply" time instead of the generic updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ticket_services')) {
            Schema::create('ticket_services', function (Blueprint $table) {
                $table->id();
                $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
                $table->string('name');
                $table->unsignedInteger('order')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'order']);
            });
        }

        Schema::table('tickets', function (Blueprint $table) {
            if (! Schema::hasColumn('tickets', 'service_id')) {
                $table->unsignedBigInteger('service_id')->nullable()->after('department_id');
                $table->index('service_id');
            }
            if (! Schema::hasColumn('tickets', 'last_reply_at')) {
                $table->timestamp('last_reply_at')->nullable()->after('resolved_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            if (Schema::hasColumn('tickets', 'service_id')) {
                $table->dropIndex(['service_id']);
                $table->dropColumn('service_id');
            }
            if (Schema::hasColumn('tickets', 'last_reply_at')) {
                $table->dropColumn('last_reply_at');
            }
        });
        Schema::dropIfExists('ticket_services');
    }
};

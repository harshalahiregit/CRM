<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central Notification & Reminder Engine (platform foundation).
 *
 * Four reusable, tenant-scoped tables every HR module plugs into — no per-module
 * notification tables and no duplication of business data (entities are referenced
 * polymorphically via module + entity_type + entity_id). In-App notifications are
 * rows in hr_notifications; delivery on every channel is tracked in
 * hr_notification_queue. Templates + rules are configuration, editable per tenant.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('module');                                  // Recruitment | Leave | Probation | …
            $table->string('entity_type')->nullable();                 // e.g. HrEmployeeProbation
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('event');                                   // e.g. Confirmation Pending
            $table->string('priority')->default('Info');               // Info | Success | Warning | Critical
            $table->string('notification_type')->default('event');     // event | reminder | escalation
            $table->string('title');
            $table->text('message')->nullable();
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->unsignedBigInteger('recipient_user_id')->nullable()->index();
            $table->string('recipient_role')->nullable();
            $table->string('action_url')->nullable();
            $table->string('action_label')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'is_read']);
            $table->index(['tenant_id', 'module', 'event', 'entity_id']);
            $table->index(['tenant_id', 'recipient_user_id', 'is_read']);
        });

        Schema::create('hr_notification_templates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('module');
            $table->string('event');
            $table->string('subject');
            $table->text('body');
            $table->boolean('email_enabled')->default(true);
            $table->boolean('in_app_enabled')->default(true);
            $table->boolean('sms_enabled')->default(false);
            $table->boolean('whatsapp_enabled')->default(false);
            $table->boolean('teams_enabled')->default(false);
            $table->boolean('slack_enabled')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module', 'event']);
        });

        Schema::create('hr_notification_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->string('module');
            $table->string('event');
            $table->json('reminder_days')->nullable();                 // e.g. [30,15,7,5,2,1,0]
            $table->boolean('repeat_daily')->default(false);
            $table->json('escalation_days')->nullable();               // e.g. [{"days":1,"role":"hr"},{"days":3,"role":"hr_manager"}]
            $table->string('priority')->default('Warning');
            $table->boolean('enabled')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module', 'event']);
        });

        Schema::create('hr_notification_queue', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('notification_id')->index();
            $table->string('channel');                                 // in_app | email | sms | whatsapp | teams | slack | push
            $table->string('status')->default('Pending');             // Pending | Processing | Sent | Failed
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['status', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_notification_queue');
        Schema::dropIfExists('hr_notification_rules');
        Schema::dropIfExists('hr_notification_templates');
        Schema::dropIfExists('hr_notifications');
    }
};

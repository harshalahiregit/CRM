<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the lead profile: attachments, appointments and email activity, plus
 * an audit trail for the lost / junk / converted transitions.
 *
 * Mirrors the old CRM's lead tabs (Email Activity, Attachments, Reminders, Custom
 * Fields, Tasks) and its Convert / Mark-as-lost / Mark-as-junk actions, with the
 * pieces that were obviously thin there filled in — the old system recorded a
 * lost/junk flag and nothing else, so there was no way to answer "why did we lose
 * this?" later.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Attachments ────────────────────────────────────────────
        // Same shape as client_attachments so the storage/download handling is
        // identical and files land on the same tenant-scoped public disk.
        Schema::create('lead_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id']);
        });

        // ── Appointments ───────────────────────────────────────────
        // In the old CRM appointments came from a paid third-party module bolted
        // onto the lead profile. Built properly here instead: polymorphic from the
        // start so customers/contacts can reuse it, rather than lead-only.
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // subject_type/subject_id — 'lead' today, any record later.
            $table->string('subject_type', 40)->default('lead');
            $table->unsignedBigInteger('subject_id');

            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();
            // In-person / phone / video call, plus a link for the video case.
            $table->string('mode', 20)->default('in_person');
            $table->string('meeting_url', 500)->nullable();

            $table->string('status', 20)->default('scheduled'); // scheduled|completed|cancelled|no_show
            $table->text('outcome')->nullable();                // filled when completed

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            // Reminder lead time in minutes; null = no reminder wanted.
            $table->unsignedInteger('remind_before_minutes')->nullable();
            $table->timestamp('reminded_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'subject_type', 'subject_id']);
            // "What's coming up" is the most common read.
            $table->index(['tenant_id', 'starts_at']);
        });

        // ── Email activity ─────────────────────────────────────────
        // The old CRM only ever recorded INBOUND mail here, captured by an IMAP
        // cron. We have no inbound infrastructure, so this logs what we can
        // truthfully record — mail the CRM itself sent to the lead — and keeps a
        // `direction` column so inbound can be added later without a reshape.
        Schema::create('lead_emails', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('direction', 10)->default('outbound'); // outbound|inbound
            $table->string('to_email')->nullable();
            $table->string('from_email')->nullable();
            $table->string('subject');
            $table->longText('body')->nullable();                 // sanitized HTML

            // Delivery outcome, so a failed send is visible rather than silent.
            $table->string('status', 20)->default('sent');        // sent|failed
            $table->text('error')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'lead_id', 'created_at']);
        });

        // ── Lost / junk audit ──────────────────────────────────────
        // The old CRM stored only the boolean. Recording who, when and why turns
        // "12 lost leads" into something you can actually review.
        Schema::table('leads', function (Blueprint $table) {
            $table->string('lost_reason')->nullable()->after('lost');
            $table->timestamp('lost_at')->nullable()->after('lost_reason');
            $table->string('junk_reason')->nullable()->after('junk');
            $table->timestamp('junk_at')->nullable()->after('junk_reason');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['lost_reason', 'lost_at', 'junk_reason', 'junk_at']);
        });
        Schema::dropIfExists('lead_emails');
        Schema::dropIfExists('appointments');
        Schema::dropIfExists('lead_attachments');
    }
};

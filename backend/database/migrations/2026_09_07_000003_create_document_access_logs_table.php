<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who opened which document, when, and from what.
 *
 * Documents leave the system constantly — minutes, kickoff packs, medical
 * certificates, invoices, work-start letters — and until now almost none of it
 * was recorded. One flow (the TPV kickoff PDF) wrote an audit line; the other
 * fifty-odd file endpoints wrote nothing, so "who downloaded that certificate?"
 * had no answer.
 *
 * Recorded per access rather than per document, because the question is usually
 * about a particular opening ("who had this on the 4th?"), and because a second
 * download from a different device is a different fact from the first.
 *
 * What is kept, and why each piece:
 *   ip / device / browser   the honest, free answer to "from where and on what".
 *                           Taken from the request itself — no third party is
 *                           asked anything.
 *   location                filled in ONLY when an administrator has switched on
 *                           an IP lookup, because resolving an address to a place
 *                           means sending that address to somebody else. Off by
 *                           default; null is the normal state.
 *   subject_type/id         the record the document belongs to, when the route
 *                           makes it obvious, so a meeting can show its own
 *                           access history rather than everyone searching a
 *                           global log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();

            // Nullable: a vendor or a customer contact is not a User, and a
            // signed public link has no account behind it at all.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_type', 40)->nullable();   // user | purchase_vendor | client_contact | guest
            $table->string('actor_label', 160)->nullable(); // who, in words, for a reader

            $table->string('action', 12)->default('view');  // view | download
            $table->string('document', 191)->nullable();    // the filename served
            $table->string('mime', 100)->nullable();
            $table->string('route', 191)->nullable();       // the endpoint that served it
            $table->string('path', 255)->nullable();        // the URL that was called

            $table->string('subject_type', 120)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->string('ip', 45)->nullable();
            $table->string('device', 20)->nullable();
            $table->string('browser', 30)->nullable();
            $table->string('user_agent', 400)->nullable();
            $table->string('location', 120)->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_access_logs');
    }
};

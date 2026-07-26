<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Hiring Request Workspace — Sprint 2.
 *
 * Two genuinely-new persistence pieces the codebase has nothing to reuse for:
 * a two-way company↔recruiter message thread, and request-scoped documents
 * (JD/NDA/PO/Agreement/Scope/SOW). Both ride the existing audit + hr_documents
 * disk plumbing. No recruitment/candidate/interview/offer logic is duplicated.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Fields the company workspace collects that the request table lacked.
        Schema::table('hr_hiring_requests', function (Blueprint $table) {
            $table->string('business_unit', 150)->nullable()->after('department');
            $table->date('target_joining_date')->nullable()->after('experience_required');
        });

        // Two-way company ↔ recruiter conversation, per hiring request.
        Schema::create('hr_hiring_request_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->string('sender_kind', 20);          // company | internal
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['hiring_request_id', 'id']);
        });

        // Request-scoped documents (uploaded by the company or internal team).
        Schema::create('hr_hiring_request_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->foreignId('hiring_request_id')->constrained('hr_hiring_requests')->cascadeOnDelete();
            $table->string('uploader_kind', 20);        // company | internal
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('type', 30);                 // jd | nda | po | agreement | scope | sow | other
            $table->string('original_name');
            $table->string('path');
            $table->unsignedInteger('size_kb')->default(0);
            $table->string('mime', 120)->nullable();
            $table->timestamps();
            $table->index(['hiring_request_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_hiring_request_documents');
        Schema::dropIfExists('hr_hiring_request_messages');
        Schema::table('hr_hiring_requests', function (Blueprint $table) {
            $table->dropColumn(['business_unit', 'target_joining_date']);
        });
    }
};

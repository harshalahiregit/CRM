<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two things a medical certificate needs around it, TPV side.
 *
 * `tpv_medical_messages` is the back-and-forth timeline: every submission,
 * verdict and reply against one certificate, in order, with the iteration it
 * belongs to. It is what makes "the quality team asked twice and the vendor
 * answered once" answerable months later, and it is what the 10-iteration cap
 * counts.
 *
 * `tpv_medical_bulk_batches` records an external-certificate sheet upload as one
 * event, so a vendor can see that 40 rows went in, 38 landed and 2 failed with a
 * reason, instead of guessing from the register.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tpv_medical_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('medical_id');
            $table->unsignedBigInteger('tpv_worker_id')->index();

            // Which round of the back-and-forth this entry belongs to. Iteration 1
            // is the first submission; the counter advances each time the vendor
            // or doctor sends the certificate back after a Hold.
            $table->unsignedTinyInteger('iteration_no')->default(1);
            // submitted | resubmitted | approved | rejected | hold | comment | reexamined
            $table->string('action', 20);
            // Who is speaking, in workflow terms rather than by user role string:
            // doctor | vendor | quality | admin | system
            $table->string('author_side', 20)->default('system');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('author_name', 160)->nullable();

            $table->string('reason_code', 60)->nullable();
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name', 200)->nullable();

            $table->timestamps();
            $table->index(['tenant_id', 'medical_id']);
        });

        Schema::create('tpv_medical_bulk_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            // vendor | admin — who filed the sheet, which the vendor's own view
            // uses to separate "we uploaded this" from "the client did".
            $table->string('uploaded_side', 20)->default('vendor');

            $table->string('file_name', 200)->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            // [{row, worker_code, error}] — the per-row rejects, kept so a vendor
            // can fix and re-upload only what failed.
            $table->json('errors')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_medical_bulk_batches');
        Schema::dropIfExists('tpv_medical_messages');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple labelled documents per kickoff meeting. Previously a meeting had a
 * single replace-in-place MoM slot; this lets a coordinator attach any number of
 * supporting files, each named for what it is ("Signed MoM", "HSE plan", …), and
 * the vendor can download them once the minutes are distributed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kickoff_meeting_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('kickoff_meeting_id');
            // What the document is — shown to admin + vendor so each file is
            // clearly "this doc for this".
            $table->string('label', 160);
            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('kickoff_meeting_id')->references('id')->on('kickoff_meetings')->cascadeOnDelete();
            $table->index(['kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kickoff_meeting_documents');
    }
};

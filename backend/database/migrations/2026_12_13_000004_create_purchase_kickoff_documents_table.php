<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multiple labelled documents per Purchase kickoff meeting (parity with the
 * shared engine). Optionally linked to a MoM action item as its evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_kickoff_documents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('purchase_kickoff_meeting_id');
            $table->unsignedBigInteger('purchase_mom_action_item_id')->nullable()->index();
            $table->string('label', 160);
            $table->string('original_name', 255);
            $table->string('path', 255);
            $table->string('mime', 120)->nullable();
            $table->unsignedBigInteger('size')->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->timestamps();

            $table->foreign('purchase_kickoff_meeting_id')->references('id')->on('purchase_kickoff_meetings')->cascadeOnDelete();
            $table->index(['purchase_kickoff_meeting_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_kickoff_documents');
    }
};

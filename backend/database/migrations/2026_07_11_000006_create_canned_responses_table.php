<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canned responses (saved replies) — reusable reply snippets agents can insert
 * into a ticket conversation. Tenant-scoped, optional category + shortcut.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('canned_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title');
            $table->string('category')->nullable();
            $table->string('shortcut')->nullable();   // e.g. "/greeting"
            $table->longText('content');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamps();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('canned_responses');
    }
};

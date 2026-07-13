<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer profile extras to match the legacy customer profile:
 *  - opening balance + "as of" date on the client record
 *  - customer-owned sub-feature tables surfaced as profile tabs
 *    (notes, reminders, vault credentials, attachments)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->decimal('opening_balance', 15, 2)->default(0)->after('parent_company');
            $table->date('opening_balance_date')->nullable()->after('opening_balance');
        });

        Schema::create('client_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->text('content');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('client_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('description');
            $table->date('remind_at');
            $table->foreignId('remind_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_notified')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('client_vault_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('title');
            $table->string('username')->nullable();
            $table->text('password')->nullable();   // encrypted at rest
            $table->string('url')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('client_id');
        });

        Schema::create('client_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_attachments');
        Schema::dropIfExists('client_vault_entries');
        Schema::dropIfExists('client_reminders');
        Schema::dropIfExists('client_notes');
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn(['opening_balance', 'opening_balance_date']);
        });
    }
};

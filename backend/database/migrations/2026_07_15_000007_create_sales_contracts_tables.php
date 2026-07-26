<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();

            $table->index('tenant_id');
        });

        Schema::create('sales_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('reference_no')->nullable();
            $table->string('subject');
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('contract_type_id')->nullable()->constrained('contract_types')->nullOnDelete();
            $table->decimal('value', 15, 2)->default(0);
            $table->string('currency', 3)->default('INR');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->text('description')->nullable();
            $table->enum('status', ['draft', 'active', 'expired', 'terminated', 'renewed'])->default('draft');
            $table->boolean('is_renewed')->default(false);
            $table->unsignedBigInteger('renewed_from_id')->nullable();
            $table->unsignedInteger('renewal_notice_days')->default(30);
            $table->boolean('expiry_reminder_sent')->default(false);
            $table->text('signature_data')->nullable();
            $table->timestamp('signed_at')->nullable();
            $table->string('signed_by_name')->nullable();
            $table->json('attachments')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('client_id');
            $table->index('status');
            $table->index('end_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sales_contracts');
        Schema::dropIfExists('contract_types');
    }
};

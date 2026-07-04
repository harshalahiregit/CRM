<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('subject');
            $table->enum('rel_type', ['lead', 'customer'])->default('customer');
            $table->unsignedBigInteger('rel_id')->nullable(); // contact id
            $table->unsignedBigInteger('project_id')->nullable();
            $table->date('date');
            $table->date('open_till')->nullable();
            $table->string('currency', 3)->default('INR');
            $table->enum('discount_type', ['none', 'before_tax', 'after_tax'])->default('none');
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('tax_total', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->enum('status', ['Draft', 'Open', 'Sent', 'Accepted', 'Declined', 'Expired'])->default('Draft');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('proposal_to')->nullable();   // "Prepared for" company/person
            $table->string('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('zip')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->boolean('allow_comments')->default(true);
            $table->text('tags')->nullable();
            $table->text('notes')->nullable();
            $table->string('portal_token')->nullable()->unique(); // for client portal link
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('declined_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index('status');
            $table->index('rel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};

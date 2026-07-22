<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_otps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash');
            $table->dateTime('expires_at');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('consumed_at')->nullable();
            // One-time access token issued after a successful verify.
            $table->string('access_token_hash', 64)->nullable()->index();
            $table->dateTime('access_expires_at')->nullable();
            $table->timestamps();

            $table->index(['proposal_id', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_otps');
    }
};

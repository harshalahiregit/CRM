<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI engine settings. Forecasting itself is deterministic and needs no external
 * service — it reads the stock ledger. This table only holds the OPTIONAL hook to
 * a language-model provider for the plain-English narrative: provider, model and
 * an API key. The key is deliberately left blank until the tenant fills it in;
 * with no key the engine still works and returns a generated (non-LLM) summary.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_ai_settings')) {
            return;
        }

        Schema::create('inventory_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->boolean('enabled')->default(true);         // deterministic engine on/off
            $table->string('provider')->nullable();            // e.g. anthropic | openai
            $table->string('model')->nullable();               // e.g. claude-opus-5
            $table->text('api_key')->nullable();               // left blank for the tenant to fill
            $table->unsignedSmallInteger('lead_time_days')->default(7);
            $table->unsignedSmallInteger('history_days')->default(90);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_ai_settings');
    }
};

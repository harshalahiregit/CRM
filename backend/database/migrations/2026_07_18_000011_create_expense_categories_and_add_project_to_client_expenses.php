<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::table('client_expenses', function (Blueprint $table) {
            // Placeholder link (meeting 4.2: build the Billable→Project
            // dropdown NOW). No FK — the projects table doesn't exist yet.
            $table->unsignedBigInteger('project_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('client_expenses', function (Blueprint $table) {
            $table->dropColumn('project_id');
        });
        Schema::dropIfExists('expense_categories');
    }
};

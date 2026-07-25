<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('sales_contracts')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('sales_contracts', function (Blueprint $table) {
            // Public share link (portal view + client signing), like proposals.
            $table->string('public_token', 64)->nullable()->unique();
            // Draw/upload signatures are base64 PNG data-URLs — needs more
            // than a plain string column (SQLite TEXT is already unbounded;
            // MySQL gets MEDIUMTEXT).
            $table->mediumText('signature_data')->nullable()->change();
        });

        foreach (DB::table('sales_contracts')->whereNull('public_token')->pluck('id') as $id) {
            DB::table('sales_contracts')->where('id', $id)->update(['public_token' => Str::random(40)]);
        }
    }

    public function down(): void
    {
        Schema::table('sales_contracts', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });
        Schema::dropIfExists('contract_comments');
    }
};

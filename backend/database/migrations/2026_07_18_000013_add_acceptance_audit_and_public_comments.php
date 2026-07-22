<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Old-CRM parity: acceptance audit on proposals (who/where accepted).
        Schema::table('proposals', function (Blueprint $table) {
            $table->string('acceptance_ip', 45)->nullable();
            $table->string('acceptance_user_agent')->nullable();
        });

        // Old-CRM parity: the CLIENT can join the contract discussion from
        // the public page (staffless comments carry an author name instead).
        Schema::table('contract_comments', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->change();
            $table->string('author_name')->nullable()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('proposals', function (Blueprint $table) {
            $table->dropColumn(['acceptance_ip', 'acceptance_user_agent']);
        });
        Schema::table('contract_comments', function (Blueprint $table) {
            $table->dropColumn('author_name');
        });
    }
};

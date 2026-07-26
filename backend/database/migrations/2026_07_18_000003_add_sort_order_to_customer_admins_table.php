<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_admins', function (Blueprint $table) {
            // Fallback order: 0 = primary handler, 1 = second, … (meeting 1.3)
            $table->unsignedSmallInteger('sort_order')->default(0)->after('user_id');
        });

        // Deterministic order for existing rows: number them per client by user_id.
        $rows = DB::table('customer_admins')->orderBy('client_id')->orderBy('user_id')->get();
        $position = [];
        foreach ($rows as $row) {
            $i = $position[$row->client_id] = ($position[$row->client_id] ?? -1) + 1;
            DB::table('customer_admins')->where('id', $row->id)->update(['sort_order' => $i]);
        }
    }

    public function down(): void
    {
        Schema::table('customer_admins', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tpv_ppe_issue_logs')) {
            Schema::create('tpv_ppe_issue_logs', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('tenant_id')->index();
                $table->unsignedBigInteger('worker_id')->index();
                $table->unsignedBigInteger('vendor_id')->index();
                $table->unsignedBigInteger('commodity_id')->nullable()->index();
                $table->string('ppe_item_name');
                $table->unsignedInteger('qty_issued')->default(1);
                $table->string('issued_to_type')->nullable(); // vendor | staff | third_party | client | pmc
                $table->unsignedBigInteger('issued_by')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('tpv_ppe_issue_logs');
    }
};

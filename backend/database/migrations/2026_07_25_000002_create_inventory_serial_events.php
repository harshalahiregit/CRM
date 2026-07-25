<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Life-history log for a serialised unit — service, repair, replacement,
 * inspection, status changes and notes. The Serial row is the current state;
 * this is how it got there.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_serial_events')) {
            return;
        }

        Schema::create('inventory_serial_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('serial_id')->constrained('inventory_serials')->cascadeOnDelete();
            // service | repair | replacement | inspection | status_change | note
            $table->string('event_type');
            $table->string('status_from')->nullable();
            $table->string('status_to')->nullable();
            $table->string('description');
            $table->decimal('cost', 12, 2)->nullable();
            $table->string('vendor')->nullable();
            $table->string('reference')->nullable();      // job card / RMA no
            $table->date('performed_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'serial_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_serial_events');
    }
};

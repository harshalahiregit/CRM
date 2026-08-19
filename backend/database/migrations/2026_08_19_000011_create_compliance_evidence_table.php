<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidence locker (Doc 6). A central register of compliance evidence —
 * insurance, licences, certificates, audit reports, training records — each with
 * a validity window so the locker surfaces what is expiring or lapsed. Evidence
 * can be tied to a vendor and carries a link (URL) to the artefact itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_evidence', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('vendor_id')->nullable()->index();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('category');                 // Insurance | License | Certificate | Audit | Training | Policy | Other
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('file_url', 1000)->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_evidence');
    }
};

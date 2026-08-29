<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bring Purchase worker Medical + Training up to TPV depth.
 *
 * Medical gains the sign-off + restriction fields TPV already carries (§16), and
 * the fitness_status column is widened so it can hold 'Fit_With_Restrictions'
 * (21 chars — the old string(20) would truncate it). Training gains a typed
 * catalogue key (training_type) and a currency window (valid_until) so a lapsed
 * certificate can be derived, mirroring TpvWorkerTraining.
 *
 * Additive and idempotent — Schema::hasColumn guards every add so a re-run (or an
 * earlier draft under another filename) is a no-op. Purchase-owned; no TPV table
 * is touched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_worker_medicals', function (Blueprint $table) {
            // §16 sign-off — the medical officer who approved the verdict, distinct
            // from the clerk (created_by) who keyed the exam in.
            if (! Schema::hasColumn('purchase_worker_medicals', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable();
            }
            if (! Schema::hasColumn('purchase_worker_medicals', 'approved_at')) {
                $table->timestamp('approved_at')->nullable();
            }
            // Restrictions that qualify a "Fit with Restrictions" verdict.
            if (! Schema::hasColumn('purchase_worker_medicals', 'restrictions')) {
                $table->text('restrictions')->nullable();
            }
            if (! Schema::hasColumn('purchase_worker_medicals', 'examiner_name')) {
                $table->string('examiner_name', 150)->nullable();
            }
            if (! Schema::hasColumn('purchase_worker_medicals', 'certificate_path')) {
                $table->string('certificate_path', 255)->nullable();
            }
            if (! Schema::hasColumn('purchase_worker_medicals', 'document_path')) {
                $table->string('document_path', 255)->nullable();
            }
        });

        // Widen fitness_status so 'Fit_With_Restrictions' (21) fits — the create
        // migration made it string(20). Native change (Laravel 12, no dbal needed).
        // Wrapped so a re-run cannot fail the migration if the column already fits.
        try {
            Schema::table('purchase_worker_medicals', function (Blueprint $table) {
                $table->string('fitness_status', 40)->default('Pending')->change();
            });
        } catch (\Throwable $e) {
            // Non-fatal: on a driver that cannot introspect/alter, the column keeps
            // its current definition. Idempotency wins over a hard failure here.
        }

        Schema::table('purchase_worker_trainings', function (Blueprint $table) {
            // §15 typed catalogue key. Nullable — legacy rows keep their free-text
            // title, and readiness still honours them.
            if (! Schema::hasColumn('purchase_worker_trainings', 'training_type')) {
                $table->string('training_type', 40)->nullable()->after('title');
            }
            // TPV-parity currency window. Distinct from the legacy expiry_date so
            // existing rows are undisturbed; the model derives status from either.
            if (! Schema::hasColumn('purchase_worker_trainings', 'valid_until')) {
                $table->date('valid_until')->nullable();
            }
            if (! Schema::hasColumn('purchase_worker_trainings', 'provider')) {
                $table->string('provider', 150)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('purchase_worker_medicals', function (Blueprint $table) {
            foreach (['approved_by', 'approved_at', 'restrictions', 'examiner_name', 'certificate_path', 'document_path'] as $col) {
                if (Schema::hasColumn('purchase_worker_medicals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('purchase_worker_trainings', function (Blueprint $table) {
            foreach (['training_type', 'valid_until', 'provider'] as $col) {
                if (Schema::hasColumn('purchase_worker_trainings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase ← TPV parity: the induction session's depth.
 *
 * purchase_worker_inductions recorded only a date, a status, who conducted it
 * and a remark. TPV's induction is a session with a duration, a topic list, a
 * score, and — because it is the record that someone was actually taught the
 * site rules — a photo, a signature and a thumbprint. Without columns for them
 * the wizard could show the capture steps but had nowhere to put the result.
 *
 * Names follow tpv_worker_inductions so both modules read the same.
 *
 * `conducted_by` is kept as-is and `trainer_name` added alongside it rather than
 * renamed: existing rows use conducted_by, and TPV distinguishes the free-text
 * trainer from the catalogue-selected one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_worker_inductions', function (Blueprint $table) {
            $table->unsignedBigInteger('recorded_by')->nullable()->after('created_by');
            $table->string('trainer_name', 150)->nullable()->after('conducted_by');

            // The session itself. training_date is separate from induction_date:
            // an induction can be recorded after the fact, and conflating the two
            // loses when it was actually delivered.
            $table->date('training_date')->nullable()->after('induction_date');
            $table->date('valid_until')->nullable()->after('training_date');
            $table->unsignedSmallInteger('duration_minutes')->nullable()->after('training_date');
            $table->json('topics')->nullable()->after('duration_minutes');

            // Outcome. `passed` is separate from `status` — a session can be
            // Completed and still failed, and the badge gate cares which.
            $table->unsignedSmallInteger('score')->nullable()->after('topics');
            $table->boolean('passed')->nullable()->after('score');

            // Proof of attendance.
            $table->string('photo_path')->nullable()->after('remarks');
            $table->string('signature_path')->nullable()->after('photo_path');
            $table->string('thumbprint_path')->nullable()->after('signature_path');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_worker_inductions', function (Blueprint $table) {
            $table->dropColumn([
                'recorded_by', 'trainer_name', 'training_date', 'valid_until',
                'duration_minutes', 'topics', 'score', 'passed',
                'photo_path', 'signature_path', 'thumbprint_path',
            ]);
        });
    }
};

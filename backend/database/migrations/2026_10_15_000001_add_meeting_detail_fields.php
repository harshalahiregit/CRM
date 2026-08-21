<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Meeting.docx §2/§5/§6/§7 — the meeting-creation fields the engine was missing.
 *
 * Adds a human Meeting No (MTG-YYYY-NNNN), end time, priority, confidentiality,
 * chairperson/coordinator, department, client, and a nullable project/work-package
 * link (project wiring lands in a later slice; the columns hold the field now).
 * Attendees gain phone / designation / side (internal vs external).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->string('meeting_no', 32)->nullable()->after('reference')->index();
            $table->timestamp('end_at')->nullable()->after('scheduled_at');
            $table->string('priority', 16)->nullable()->after('duration_minutes');
            $table->string('confidentiality', 16)->nullable()->after('priority');
            $table->string('chairperson', 160)->nullable()->after('confidentiality');
            $table->string('coordinator', 160)->nullable()->after('chairperson');
            $table->string('department', 120)->nullable()->after('coordinator');
            $table->string('client_name', 200)->nullable()->after('department');
            // Project link is filled once the project subject is wired; the column
            // exists now so §2's Project/Work-Package inputs have somewhere to go.
            $table->unsignedBigInteger('project_id')->nullable()->after('client_name')->index();
            $table->string('work_package', 200)->nullable()->after('project_id');
        });

        Schema::table('kickoff_attendees', function (Blueprint $table) {
            $table->string('phone', 40)->nullable()->after('email');
            $table->string('designation', 120)->nullable()->after('role');
            $table->string('side', 16)->nullable()->after('designation');   // internal | external
        });

        // Back-fill a Meeting No for meetings that predate this column, per tenant
        // and creation order, so existing rows are not left blank.
        $year = date('Y');
        foreach (DB::table('kickoff_meetings')->orderBy('tenant_id')->orderBy('id')->get(['id', 'tenant_id', 'created_at']) as $m) {
            $y = $m->created_at ? substr((string) $m->created_at, 0, 4) : $year;
            $seq = DB::table('kickoff_meetings')
                ->where('tenant_id', $m->tenant_id)
                ->where('id', '<=', $m->id)
                ->whereYear('created_at', $y)
                ->count();
            DB::table('kickoff_meetings')->where('id', $m->id)
                ->update(['meeting_no' => sprintf('MTG-%s-%04d', $y, $seq)]);
        }
    }

    public function down(): void
    {
        Schema::table('kickoff_meetings', function (Blueprint $table) {
            $table->dropColumn([
                'meeting_no', 'end_at', 'priority', 'confidentiality', 'chairperson',
                'coordinator', 'department', 'client_name', 'project_id', 'work_package',
            ]);
        });
        Schema::table('kickoff_attendees', function (Blueprint $table) {
            $table->dropColumn(['phone', 'designation', 'side']);
        });
    }
};

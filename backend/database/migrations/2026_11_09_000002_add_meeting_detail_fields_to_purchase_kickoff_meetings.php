<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase meeting header fields (Meeting.docx §2). Adds the governance meta the
 * spec's creation form lists — an auto Meeting-No, end time, priority,
 * confidentiality, chairperson/coordinator/organizer, department, client — to
 * purchase_kickoff_meetings. Back-fills a Meeting-No for existing rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_kickoff_meetings', 'meeting_no')) {
                $table->string('meeting_no', 32)->nullable()->after('reference')->index();
            }
            if (! Schema::hasColumn('purchase_kickoff_meetings', 'end_at')) {
                $table->timestamp('end_at')->nullable()->after('scheduled_at');
            }
            foreach ([
                'priority' => 16, 'confidentiality' => 16, 'chairperson' => 160,
                'coordinator' => 160, 'organizer' => 160, 'department' => 120, 'client_name' => 200,
            ] as $col => $len) {
                if (! Schema::hasColumn('purchase_kickoff_meetings', $col)) {
                    $table->string($col, $len)->nullable();
                }
            }
        });

        // Back-fill Meeting-No (MTG-YYYY-NNNN) per tenant, in creation order.
        $counters = [];
        DB::table('purchase_kickoff_meetings')->whereNull('meeting_no')->orderBy('id')
            ->get(['id', 'tenant_id', 'created_at'])
            ->each(function ($row) use (&$counters) {
                $year = $row->created_at ? date('Y', strtotime($row->created_at)) : date('Y');
                $key = $row->tenant_id.'-'.$year;
                $counters[$key] = ($counters[$key] ?? 0) + 1;
                DB::table('purchase_kickoff_meetings')->where('id', $row->id)
                    ->update(['meeting_no' => sprintf('MTG-%s-%04d', $year, $counters[$key])]);
            });
    }

    public function down(): void
    {
        Schema::table('purchase_kickoff_meetings', function (Blueprint $table) {
            foreach (['end_at', 'priority', 'confidentiality', 'chairperson', 'coordinator', 'organizer', 'department', 'client_name'] as $col) {
                if (Schema::hasColumn('purchase_kickoff_meetings', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('purchase_kickoff_meetings', 'meeting_no')) {
                $table->dropIndex(['meeting_no']);
                $table->dropColumn('meeting_no');
            }
        });
    }
};

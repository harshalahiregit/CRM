<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Api\Customer\Concerns\AssertsClientTenant;
use App\Http\Controllers\Controller;
use App\Models\Customer\Client;
use App\Support\Shared\KickoffSubject;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §6 — "Don't duplicate the modules."
 *
 * Projects, Tasks, Delivery Notes and Meetings all belong to other modules.
 * Customer shows the customer's slice of each and links out to the module that
 * owns it; it does not offer create, edit or delete, because that would be a
 * second implementation of somebody else's feature.
 *
 * Every method here is a SWAP POINT: a guarded, read-only query builder against
 * another team's table. None of them touch another module's Eloquent models, so
 * a change to those models cannot break this screen, and when any of these
 * modules grows a proper API the change is confined to one method.
 */
class CustomerLinkedRecordsController extends Controller
{
    use AssertsClientTenant;

    /** SWAP POINT — Projects (Shivam). */
    public function projects(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        if (! Schema::hasTable('projects')) {
            return response()->json($this->unavailable('Projects'));
        }

        $rows = DB::table('projects')
            ->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get(['id', 'name', 'status', 'start_date', 'deadline', 'progress', 'project_cost']);

        return response()->json([
            'rows'      => $rows,
            'link'      => '/app/projects?customer_id='.$client->id,
            'owned_by'  => 'Projects',
            'read_only' => true,
        ]);
    }

    /**
     * SWAP POINT — Tasks (Shivam).
     *
     * Two sources of truth for "this customer's tasks": tasks raised directly
     * against the customer, and tasks on the customer's projects. Both are
     * genuinely theirs, and showing only the first would make the tab look
     * empty for every customer whose work is organised into projects.
     */
    public function tasks(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        if (! Schema::hasTable('tasks')) {
            return response()->json($this->unavailable('Tasks'));
        }

        $projectIds = Schema::hasTable('projects')
            ? DB::table('projects')->where('tenant_id', $client->tenant_id)
                ->where('customer_id', $client->id)->whereNull('deleted_at')->pluck('id')
            : collect();

        $rows = DB::table('tasks')
            ->where('tenant_id', $client->tenant_id)
            ->whereNull('deleted_at')
            ->where(function ($q) use ($client, $projectIds) {
                $q->where(fn ($w) => $w->where('rel_type', 'customer')->where('rel_id', $client->id));
                if ($projectIds->isNotEmpty()) {
                    $q->orWhere(fn ($w) => $w->where('rel_type', 'project')->whereIn('rel_id', $projectIds));
                }
            })
            ->orderByDesc('created_at')
            ->limit(200)
            ->get(['id', 'name', 'status', 'priority', 'start_date', 'due_date', 'rel_type']);

        return response()->json([
            'rows'      => $rows,
            'link'      => '/app/tasks?rel_type=customer&rel_id='.$client->id,
            'owned_by'  => 'Tasks',
            'read_only' => true,
        ]);
    }

    /** SWAP POINT — Delivery Notes (Sales/Operations). */
    public function deliveryNotes(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        if (! Schema::hasTable('delivery_notes')) {
            return response()->json($this->unavailable('Delivery Notes'));
        }

        $rows = DB::table('delivery_notes as d')
            ->leftJoin('sales_invoices as i', 'i.id', '=', 'd.invoice_id')
            ->where('d.tenant_id', $client->tenant_id)
            ->where('d.client_id', $client->id)
            ->whereNull('d.deleted_at')
            ->orderByDesc('d.delivery_date')
            ->get([
                'd.id', 'd.number', 'd.delivery_date', 'd.status',
                'd.shipping_city', 'd.shipping_state', 'i.number as invoice_number',
            ]);

        return response()->json([
            'rows'      => $rows,
            'link'      => null,
            'owned_by'  => 'Delivery Notes',
            'read_only' => true,
        ]);
    }

    /**
     * SWAP POINT — the shared meeting engine (§3).
     *
     * Customer is a registered subject type, so these are real meetings in the
     * shared engine rather than a Customer-local copy. Creating one goes to the
     * engine's own endpoint; this only lists what is already attached.
     */
    public function meetings(Client $client, Request $request)
    {
        $this->assertClientTenant($client, $request->user()->tenant_id);

        if (! Schema::hasTable('kickoff_meeting_subjects') || ! Schema::hasTable('kickoff_meetings')) {
            return response()->json($this->unavailable('Meetings'));
        }

        $rows = DB::table('kickoff_meeting_subjects as s')
            ->join('kickoff_meetings as m', 'm.id', '=', 's.kickoff_meeting_id')
            ->where('s.tenant_id', $client->tenant_id)
            ->where('s.subject_type', KickoffSubject::classFor(KickoffSubject::CUSTOMER))
            ->where('s.subject_id', $client->id)
            ->orderByDesc('m.scheduled_at')
            ->get(['m.id', 'm.title', 'm.status', 'm.scheduled_at', 'm.mom_status', 's.is_primary']);

        // Open action items across those meetings — the number people ask for.
        $openActions = Schema::hasTable('kickoff_mom_items') && $rows->isNotEmpty()
            ? DB::table('kickoff_mom_items')
                ->whereIn('kickoff_meeting_id', $rows->pluck('id'))
                ->whereNull('closed_at')->count()
            : 0;

        return response()->json([
            'rows'         => $rows,
            'open_actions' => $openActions,
            'subject_type' => KickoffSubject::CUSTOMER,
            'link'         => '/app/meetings',
            'owned_by'     => 'Meetings',
            'read_only'    => true,
        ]);
    }

    /**
     * A module that is not installed.
     *
     * Answers 200 with an empty set rather than 404 or 500: the customer screen
     * should degrade to "nothing to show" when a module is absent, not break.
     */
    private function unavailable(string $module): array
    {
        return [
            'rows'        => [],
            'link'        => null,
            'owned_by'    => $module,
            'read_only'   => true,
            'unavailable' => true,
        ];
    }
}

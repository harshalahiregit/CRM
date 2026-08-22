<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Models\Customer\ClientContract;
use App\Models\Customer\ClientReminder;
use App\Models\Customer\ClientShipment;
use App\Models\Sales\SalesInvoice;
use App\Support\Customer\CustomerOptions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Customer 360 read layer.
 *
 * Customer is the relationship/context layer: it owns the relationship, not the
 * transactions. Every number here is a LIVE count of another module's data,
 * shown beside a link into the module that owns it — never a second copy of
 * that module's records. Nothing is written and nothing is cached.
 *
 * ── The boundary ──────────────────────────────────────────────────────────
 * Each cross-module read is isolated in its own private method marked SWAP
 * POINT. Today it runs a query; when Projects or Helpdesk expose their own
 * endpoints, only that one method changes and no screen is touched. Screens
 * never query another module directly, which is what keeps that swap cheap.
 *
 * Modules owned by other developers are read through the query builder rather
 * than their Eloquent models, so Customer never depends on their class API and
 * a rename on their side cannot break this page. Tables that may not exist yet
 * are probed with Schema::hasTable so a half-installed module degrades to a
 * zero instead of a 500.
 */
class Customer360Service
{
    public function __construct(private CustomerHealthService $health)
    {
    }

    /** Contracts expiring inside this many days raise an alert. */
    private const CONTRACT_EXPIRY_WINDOW_DAYS = 30;

    public function overview(Client $client): array
    {
        return [
            'kpis'     => $this->kpis($client),
            'alerts'   => $this->alerts($client),
            'recent'   => $this->recentActivity($client),
            'owner'    => $this->owner($client),
            // Computed live here — a cached score on a detail page would be
            // wrong the moment an invoice is paid. The stored copy on `clients`
            // exists for the LIST, where ten aggregates per row is untenable.
            'health'   => $this->health->score($client),
            'risk'     => $this->health->risk($client),
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    //  KPIs
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Each tile carries the route to the owning module, so the UI never has to
     * know where a number came from — it just links where the tile says.
     */
    /**
     * §2 — the eight dashboard tiles.
     *
     * Each `link` must use the parameter name the DESTINATION reads, not a
     * name that reads well. Tasks takes rel_type/rel_id (it is polymorphic),
     * Projects and Helpdesk take customer_id, and ticket lists live under
     * /app/helpdesk — /app/tickets is a "coming soon" placeholder. All three
     * previously sent `?customer=`, which nothing consumed: the tiles landed
     * users on the whole tenant's records with no error and no filter chip,
     * which is harder to notice than a 404.
     */
    public function kpis(Client $client): array
    {
        $finance = $this->financeSnapshot($client);

        return [
            ['key' => 'projects',   'label' => 'Active Projects',  'value' => $this->activeProjectCount($client),   'link' => '/app/projects?customer_id='.$client->id],
            ['key' => 'tasks',      'label' => 'Open Tasks',       'value' => $this->openTaskCount($client),        'link' => '/app/tasks?rel_type=customer&rel_id='.$client->id],
            ['key' => 'tickets',    'label' => 'Open Tickets',     'value' => $this->openTicketCount($client),      'link' => '/app/helpdesk/tickets?customer_id='.$client->id],
            ['key' => 'contracts',  'label' => 'Active Contracts', 'value' => $this->activeContractCount($client),  'link' => null],
            ['key' => 'outstanding','label' => 'Outstanding',      'value' => $finance['outstanding'], 'money' => true, 'link' => null],
            ['key' => 'shipments',  'label' => 'Active Shipments', 'value' => $this->activeShipmentCount($client),  'link' => null],
            ['key' => 'actions',    'label' => 'Open Actions',     'value' => $this->openActionCount($client),      'link' => null],
            ['key' => 'meetings',   'label' => 'Meetings',         'value' => $this->meetingCount($client),         'link' => null],
        ];
    }

    /**
     * §2 — Open Actions.
     *
     * Meeting action items plus promised activity follow-ups: both are things
     * we owe this customer, and the dashboard should not make somebody check
     * two screens to find out how many there are.
     */
    private function openActionCount(Client $client): int
    {
        $count = 0;

        if (Schema::hasTable('kickoff_mom_items') && Schema::hasTable('kickoff_meeting_subjects')) {
            $count += DB::table('kickoff_mom_items as i')
                ->join('kickoff_meeting_subjects as s', 's.kickoff_meeting_id', '=', 'i.kickoff_meeting_id')
                ->where('s.tenant_id', $client->tenant_id)
                ->where('s.subject_type', Client::class)
                ->where('s.subject_id', $client->id)
                ->whereNull('i.closed_at')
                ->count();
        }

        if (Schema::hasTable('client_activities')) {
            $count += DB::table('client_activities')
                ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
                ->whereNull('deleted_at')
                ->whereNotNull('follow_up_on')->where('follow_up_done', false)
                ->count();
        }

        return $count;
    }

    /** §2/§3 — meetings this customer is a subject of, via the shared engine. */
    private function meetingCount(Client $client): int
    {
        if (! Schema::hasTable('kickoff_meeting_subjects')) {
            return 0;
        }

        return DB::table('kickoff_meeting_subjects')
            ->where('tenant_id', $client->tenant_id)
            ->where('subject_type', Client::class)
            ->where('subject_id', $client->id)
            ->count();
    }

    /** SWAP POINT — Projects (Shivam). */
    private function activeProjectCount(Client $client): int
    {
        if (! Schema::hasTable('projects')) {
            return 0;
        }

        return (int) DB::table('projects')
            ->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)
            ->whereIn('status', ['not_started', 'in_progress', 'on_hold'])
            ->count();
    }

    /**
     * SWAP POINT — Tasks (Shivam).
     *
     * Tasks have no customer_id column, but rel_type/rel_id reaches a customer
     * two ways and both count: a task attached straight to the customer
     * (rel_type='customer') and a task on one of the customer's projects. Only
     * counting the project route would silently under-report.
     */
    private function openTaskCount(Client $client): int
    {
        if (! Schema::hasTable('tasks')) {
            return 0;
        }

        $projectIds = Schema::hasTable('projects')
            ? DB::table('projects')
                ->where('tenant_id', $client->tenant_id)
                ->where('customer_id', $client->id)
                ->pluck('id')
            : collect();

        return (int) DB::table('tasks')
            ->where('tenant_id', $client->tenant_id)
            ->whereNull('date_finished')
            ->where(function ($q) use ($client, $projectIds) {
                $q->where(fn ($w) => $w->where('rel_type', 'customer')->where('rel_id', $client->id));
                if ($projectIds->isNotEmpty()) {
                    $q->orWhere(fn ($w) => $w->where('rel_type', 'project')->whereIn('rel_id', $projectIds));
                }
            })
            ->count();
    }

    /** SWAP POINT — Helpdesk (Shivam). */
    private function openTicketCount(Client $client): int
    {
        if (! Schema::hasTable('tickets')) {
            return 0;
        }

        return (int) DB::table('tickets')
            ->where('tenant_id', $client->tenant_id)
            ->where('customer_id', $client->id)
            ->whereIn('status', ['open', 'in-progress'])
            ->whereNull('merged_into_id')
            ->count();
    }

    /** Customer-owned — no swap needed. */
    private function activeContractCount(Client $client): int
    {
        return ClientContract::where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->where('status', 'Active')
            ->count();
    }

    /** Customer-owned — anything not yet delivered. */
    private function activeShipmentCount(Client $client): int
    {
        return ClientShipment::where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->whereNotIn('status', ['Delivered', 'Cancelled'])
            ->count();
    }

    /**
     * SWAP POINT — Sales (own module, but still read-only from here).
     *
     * Outstanding is the sum of unpaid balances, not of totals: a part-paid
     * invoice must contribute only what is still owed.
     */
    private function financeSnapshot(Client $client): array
    {
        $rows = SalesInvoice::where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->whereNotIn('status', ['Draft', 'Cancelled'])
            ->get(['balance', 'due_date', 'status']);

        return [
            'outstanding' => (float) $rows->sum('balance'),
            'overdue'     => $rows->filter(fn ($i) => $this->isOverdue($i))->count(),
        ];
    }

    private function isOverdue(SalesInvoice $invoice): bool
    {
        return (float) $invoice->balance > 0
            && $invoice->due_date
            && Carbon::parse($invoice->due_date)->isPast();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Alerts
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Only things that need someone to act. An alert with a count of zero is
     * omitted entirely rather than rendered as a reassuring green row — a list
     * of non-problems trains people to stop reading it.
     */
    public function alerts(Client $client): array
    {
        $alerts = [];

        $overdue = $this->financeSnapshot($client)['overdue'];
        if ($overdue > 0) {
            $alerts[] = [
                'key'      => 'invoices_overdue',
                'severity' => 'critical',
                'message'  => $overdue.' '.($overdue === 1 ? 'invoice is' : 'invoices are').' overdue',
            ];
        }

        $expiring = $this->contractsExpiringSoon($client);
        if ($expiring > 0) {
            $alerts[] = [
                'key'      => 'contracts_expiring',
                'severity' => 'warning',
                'message'  => $expiring.' '.($expiring === 1 ? 'contract expires' : 'contracts expire')
                              .' within '.self::CONTRACT_EXPIRY_WINDOW_DAYS.' days',
            ];
        }

        $tickets = $this->openTicketCount($client);
        if ($tickets > 0) {
            $alerts[] = [
                'key'      => 'tickets_open',
                'severity' => 'info',
                'message'  => $tickets.' open '.($tickets === 1 ? 'ticket' : 'tickets'),
            ];
        }

        $reminders = $this->overdueReminderCount($client);
        if ($reminders > 0) {
            $alerts[] = [
                'key'      => 'reminders_overdue',
                'severity' => 'warning',
                'message'  => $reminders.' overdue '.($reminders === 1 ? 'reminder' : 'reminders'),
            ];
        }

        $complaints = $this->openComplaintCount($client);
        if ($complaints > 0) {
            $alerts[] = [
                'key'      => 'complaints_open',
                'severity' => 'critical',
                'message'  => $complaints.' open '.($complaints === 1 ? 'complaint' : 'complaints'),
            ];
        }

        $domains = $this->domainsExpiringSoon($client);
        if ($domains > 0) {
            $alerts[] = [
                'key'      => 'domains_expiring',
                'severity' => 'warning',
                'message'  => $domains.' '.($domains === 1 ? 'domain expires' : 'domains expire').' within 30 days',
            ];
        }

        // An expired PO with budget left means work may be happening with no
        // authority to bill for it — worth surfacing before the invoice bounces.
        $lapsed = $this->lapsedPurchaseOrderCount($client);
        if ($lapsed > 0) {
            $alerts[] = [
                'key'      => 'purchase_orders_lapsed',
                'severity' => 'warning',
                'message'  => $lapsed.' expired purchase '.($lapsed === 1 ? 'order still has' : 'orders still have').' value remaining',
            ];
        }

        return $alerts;
    }

    private function openComplaintCount(Client $client): int
    {
        if (! Schema::hasTable('client_complaints')) {
            return 0;
        }

        return DB::table('client_complaints')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['Open', 'Investigating'])
            ->count();
    }

    private function domainsExpiringSoon(Client $client): int
    {
        if (! Schema::hasTable('client_domains')) {
            return 0;
        }

        return DB::table('client_domains')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->whereNotNull('expires_on')
            ->whereNotIn('status', ['Cancelled', 'Transferred'])
            ->whereDate('expires_on', '<=', now()->addDays(30)->toDateString())
            ->count();
    }

    private function lapsedPurchaseOrderCount(Client $client): int
    {
        if (! Schema::hasTable('client_purchase_orders')) {
            return 0;
        }

        return DB::table('client_purchase_orders')
            ->where('tenant_id', $client->tenant_id)->where('client_id', $client->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['Open', 'Partially Billed'])
            ->whereNotNull('valid_until')
            ->whereDate('valid_until', '<', now()->toDateString())
            ->whereColumn('consumed', '<', 'value')
            ->count();
    }

    private function contractsExpiringSoon(Client $client): int
    {
        return ClientContract::where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->where('status', 'Active')
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(self::CONTRACT_EXPIRY_WINDOW_DAYS)->toDateString()])
            ->count();
    }

    /**
     * client_reminders has no completion column — only is_notified, which records
     * that a notification fired, not that anyone acted. So "overdue" can only mean
     * "past its date and never even notified", which is the one unambiguous
     * anomaly available. A reminder that was notified but ignored is invisible here.
     *
     * This is a concrete argument for the change-3 consolidation: the unified
     * follow-up engine has completed_at, so this alert becomes truthful the moment
     * reminders move onto it.
     */
    private function overdueReminderCount(Client $client): int
    {
        return ClientReminder::where('tenant_id', $client->tenant_id)
            ->where('client_id', $client->id)
            ->where('remind_at', '<', now())
            ->where('is_notified', false)
            ->count();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Recent activity
    // ─────────────────────────────────────────────────────────────────────

    /**
     * The last few things that happened, newest first.
     *
     * A deliberately shallow read of each source rather than one big UNION: each
     * table is asked for its own most-recent handful, then the merged set is cut
     * to $limit. A UNION across eight tables with different shapes would be both
     * slower and impossible to extend without touching every branch.
     *
     * This is the Overview's summary strip. The full Timeline (change 3) is the
     * same idea with paging, filtering and every source — this stays small on
     * purpose so the dashboard opens fast.
     */
    public function recentActivity(Client $client, int $limit = 10): array
    {
        $events = collect()
            ->concat($this->recentFrom('sales_invoices', 'client_id', $client, fn ($r) => [
                'type' => 'invoice', 'label' => 'Invoice '.$r->number.' raised', 'at' => $r->created_at,
            ]))
            ->concat($this->recentPayments($client))
            ->concat($this->recentFrom('client_shipments', 'client_id', $client, fn ($r) => [
                'type' => 'shipment', 'label' => 'Shipment '.$r->shipment_number.' '.strtolower($r->status ?: 'created'), 'at' => $r->created_at,
            ]))
            ->concat($this->recentFrom('client_contracts', 'client_id', $client, fn ($r) => [
                'type' => 'contract', 'label' => 'Contract "'.$r->subject.'" added', 'at' => $r->created_at,
            ]))
            ->concat($this->recentFrom('client_notes', 'client_id', $client, fn ($r) => [
                'type' => 'note', 'label' => 'Note added', 'at' => $r->created_at,
            ]))
            ->concat($this->recentFrom('client_attachments', 'client_id', $client, fn ($r) => [
                'type' => 'file', 'label' => 'File uploaded: '.$r->file_name, 'at' => $r->created_at,
            ]))
            ->concat($this->recentFrom('tickets', 'customer_id', $client, fn ($r) => [
                'type' => 'ticket', 'label' => 'Ticket opened: '.$r->subject, 'at' => $r->created_at,
            ]));

        return $events
            ->filter(fn ($e) => ! empty($e['at']))
            ->sortByDesc('at')
            ->take($limit)
            ->map(fn ($e) => $e + ['at' => Carbon::parse($e['at'])->toIso8601String()])
            ->values()
            ->all();
    }

    /**
     * Most-recent rows from one table, mapped to an event.
     *
     * Schema-guarded and column-guarded: a module that is not installed, or one
     * that renamed a column, yields nothing rather than breaking the dashboard.
     */
    private function recentFrom(string $table, string $fk, Client $client, callable $map, int $take = 5): array
    {
        if (! Schema::hasTable($table)) {
            return [];
        }

        return DB::table($table)
            ->where('tenant_id', $client->tenant_id)
            ->where($fk, $client->id)
            ->orderByDesc('created_at')
            ->limit($take)
            ->get()
            ->map($map)
            ->all();
    }

    /** Payments hang off the invoice, so they need the join rather than a client_id. */
    private function recentPayments(Client $client): array
    {
        if (! Schema::hasTable('sales_payments') || ! Schema::hasTable('sales_invoices')) {
            return [];
        }

        return DB::table('sales_payments as p')
            ->join('sales_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('i.tenant_id', $client->tenant_id)
            ->where('i.client_id', $client->id)
            ->orderByDesc('p.created_at')
            ->limit(5)
            ->get(['p.amount', 'p.created_at', 'i.number'])
            ->map(fn ($r) => [
                'type'  => 'payment',
                'label' => 'Payment received against '.$r->number,
                'at'    => $r->created_at,
            ])
            ->all();
    }

    // ─────────────────────────────────────────────────────────────────────
    //  Header
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Account ownership. The dedicated owner columns arrive with change 5; until
     * then this reports the assigned customer admins, which is the closest thing
     * the data currently supports. Shaped as its final form so the screen does
     * not change when the real column lands.
     */
    private function owner(Client $client): array
    {
        $admins = $client->admins()->take(3)->pluck('name')->all();

        return [
            'account_owner'  => $admins[0] ?? null,
            'also_assigned'  => array_slice($admins, 1),
            'is_placeholder' => true,
        ];
    }
}

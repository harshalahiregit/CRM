<?php

namespace App\Services\Customer;

use App\Models\Customer\Client;
use App\Support\Shared\KickoffSubject;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * §5 — the Customer Timeline.
 *
 * "This timeline should aggregate activity from connected Sangoe modules." It
 * aggregates by READING them, never by copying rows into a Customer-owned
 * events table. A denormalised feed would have to be kept in step with seven
 * modules forever, and would be wrong the first time somebody edited a record
 * without going through the writer that fanned the event out.
 *
 * Every cross-module read below is a SWAP POINT: a schema-guarded query builder
 * over another team's table, mapped to a neutral event shape at the boundary. If
 * a module moves behind an API, only the method changes.
 *
 * This is a superset of Customer360Service::recentActivity(), which stays as-is:
 * the Overview wants the last handful of events cheaply, the Timeline wants
 * everything in a window with filters. Merging them would make the dashboard pay
 * for a screen it does not show.
 */
class CustomerTimelineService
{
    /** Event groups the UI filters on. */
    public const CATEGORIES = [
        'finance'      => ['invoice', 'payment', 'credit_note', 'expense'],
        'commercial'   => ['estimate', 'proposal', 'contract', 'subscription', 'purchase_order'],
        'operations'   => ['shipment', 'package', 'pre_alert', 'delivery_note', 'project', 'task'],
        'relationship' => ['activity', 'meeting', 'note', 'contact'],
        'service'      => ['ticket', 'complaint', 'feedback'],
        'admin'        => ['file', 'domain', 'reminder'],
    ];

    /**
     * @param  array<int,string>  $types  restrict to these event types (empty = all)
     * @return array{days: array, counts: array, total: int, from: ?string, to: ?string}
     */
    public function timeline(
        Client $client,
        ?string $from = null,
        ?string $to = null,
        array $types = [],
        int $limit = 200,
    ): array {
        $events = collect($this->gather($client));

        if ($from) {
            $events = $events->filter(fn ($e) => $e['at'] >= Carbon::parse($from)->startOfDay());
        }
        if ($to) {
            $events = $events->filter(fn ($e) => $e['at'] <= Carbon::parse($to)->endOfDay());
        }

        // Counts are taken BEFORE the type filter so the filter chips can show
        // how many of each type exist, including the ones currently hidden.
        $counts = $events->countBy('type')->all();

        if ($types !== []) {
            $events = $events->filter(fn ($e) => in_array($e['type'], $types, true));
        }

        $total  = $events->count();
        $events = $events->sortByDesc('at')->take($limit);

        $days = $events
            ->groupBy(fn ($e) => $e['at']->toDateString())
            ->map(fn ($group, $date) => [
                'date'   => $date,
                'label'  => Carbon::parse($date)->isToday() ? 'Today'
                            : (Carbon::parse($date)->isYesterday() ? 'Yesterday'
                            : Carbon::parse($date)->format('j M Y')),
                'events' => $group->sortByDesc('at')->map(fn ($e) => [
                    'type'     => $e['type'],
                    'category' => $this->categoryOf($e['type']),
                    'label'    => $e['label'],
                    'detail'   => $e['detail'] ?? null,
                    'link'     => $e['link'] ?? null,
                    'at'       => $e['at']->toIso8601String(),
                ])->values()->all(),
            ])
            ->sortKeysDesc()
            ->values()
            ->all();

        return [
            'days'   => $days,
            'counts' => $counts,
            'total'  => $total,
            'from'   => $from,
            'to'     => $to,
        ];
    }

    private function categoryOf(string $type): string
    {
        foreach (self::CATEGORIES as $category => $types) {
            if (in_array($type, $types, true)) {
                return $category;
            }
        }

        return 'other';
    }

    /** Every source, each independently guarded. */
    private function gather(Client $client): array
    {
        return array_merge(
            // ── Customer's own tables ──
            $this->from($client, 'client_activities', 'client_id', 'occurred_at', fn ($r) => [
                'type' => 'activity',
                'label' => $r->type.': '.$r->subject,
                'detail' => $r->outcome,
            ]),
            $this->from($client, 'client_complaints', 'client_id', 'raised_at', fn ($r) => [
                'type' => 'complaint',
                'label' => $r->kind.' raised: '.$r->subject,
                'detail' => $r->severity.' · '.$r->status,
            ]),
            $this->from($client, 'client_feedback', 'client_id', 'responded_at', fn ($r) => [
                'type' => 'feedback',
                'label' => $r->metric.' response: '.$r->score,
                'detail' => $r->comments,
            ]),
            $this->from($client, 'client_notes', 'client_id', 'created_at', fn ($r) => [
                'type' => 'note', 'label' => 'Note added', 'detail' => $r->type ?? null,
            ]),
            $this->from($client, 'client_attachments', 'client_id', 'created_at', fn ($r) => [
                'type' => 'file', 'label' => 'File uploaded: '.$r->file_name,
            ]),
            $this->from($client, 'client_contracts', 'client_id', 'created_at', fn ($r) => [
                'type' => 'contract', 'label' => 'Contract "'.$r->subject.'" added',
            ]),
            $this->from($client, 'client_subscriptions', 'client_id', 'created_at', fn ($r) => [
                'type' => 'subscription', 'label' => 'Subscription "'.($r->name ?? 'subscription').'" added',
            ]),
            $this->from($client, 'client_purchase_orders', 'client_id', 'created_at', fn ($r) => [
                'type' => 'purchase_order', 'label' => 'Purchase order '.$r->po_number.' recorded',
            ]),
            $this->from($client, 'client_domains', 'client_id', 'created_at', fn ($r) => [
                'type' => 'domain', 'label' => 'Domain '.$r->domain.' added',
            ]),
            $this->from($client, 'client_shipments', 'client_id', 'created_at', fn ($r) => [
                'type' => 'shipment',
                'label' => 'Shipment '.$r->shipment_number.' '.strtolower($r->status ?: 'created'),
            ]),
            $this->from($client, 'client_packages', 'client_id', 'created_at', fn ($r) => [
                'type' => 'package', 'label' => 'Package recorded',
            ]),
            $this->from($client, 'client_pre_alerts', 'client_id', 'created_at', fn ($r) => [
                'type' => 'pre_alert', 'label' => 'Pre-alert raised',
            ]),
            $this->from($client, 'client_reminders', 'client_id', 'created_at', fn ($r) => [
                'type' => 'reminder', 'label' => 'Reminder set: '.($r->description ?? ''),
            ]),

            // ── SWAP POINT — Sales ──
            $this->from($client, 'sales_invoices', 'client_id', 'created_at', fn ($r) => [
                'type' => 'invoice', 'label' => 'Invoice '.$r->number.' raised',
                'link' => '/app/sales/invoices/'.$r->id,
            ]),
            $this->from($client, 'estimates', 'client_id', 'created_at', fn ($r) => [
                'type' => 'estimate', 'label' => 'Estimate '.($r->number ?? '').' created',
            ]),
            $this->from($client, 'proposals', 'client_id', 'created_at', fn ($r) => [
                'type' => 'proposal', 'label' => 'Proposal '.($r->subject ?? '').' created',
            ]),
            $this->from($client, 'credit_notes', 'client_id', 'created_at', fn ($r) => [
                'type' => 'credit_note', 'label' => 'Credit note '.($r->number ?? '').' issued',
            ]),
            $this->payments($client),

            // ── SWAP POINT — Helpdesk / Projects / Tasks (Shivam) ──
            $this->from($client, 'tickets', 'customer_id', 'created_at', fn ($r) => [
                'type' => 'ticket', 'label' => 'Ticket opened: '.$r->subject,
                'detail' => $r->status, 'link' => '/app/tickets/'.$r->id,
            ]),
            $this->from($client, 'projects', 'customer_id', 'created_at', fn ($r) => [
                'type' => 'project', 'label' => 'Project started: '.$r->name,
                'detail' => $r->status, 'link' => '/app/projects/'.$r->id,
            ]),
            $this->customerTasks($client),

            // ── SWAP POINT — the shared meeting engine ──
            $this->meetings($client),
        );
    }

    /**
     * Rows from one table as events.
     *
     * Guarded on the table AND on the date column, because a module that renamed
     * a column should cost the Timeline that one source, not the whole screen.
     */
    private function from(Client $client, string $table, string $fk, string $dateColumn, callable $map, int $take = 50): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $dateColumn) || ! Schema::hasColumn($table, $fk)) {
            return [];
        }

        $query = DB::table($table)->where($fk, $client->id)->whereNotNull($dateColumn);

        if (Schema::hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', $client->tenant_id);
        }
        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull('deleted_at');
        }

        return $query->orderByDesc($dateColumn)->limit($take)->get()
            ->map(fn ($r) => $map($r) + ['at' => Carbon::parse($r->{$dateColumn})])
            ->all();
    }

    private function payments(Client $client): array
    {
        if (! Schema::hasTable('sales_payments') || ! Schema::hasTable('sales_invoices')) {
            return [];
        }

        return DB::table('sales_payments as p')
            ->join('sales_invoices as i', 'i.id', '=', 'p.invoice_id')
            ->where('i.tenant_id', $client->tenant_id)
            ->where('i.client_id', $client->id)
            ->orderByDesc('p.created_at')->limit(50)
            ->get(['p.amount', 'p.created_at', 'i.number'])
            ->map(fn ($r) => [
                'type'  => 'payment',
                'label' => 'Payment received against '.$r->number,
                'detail' => number_format((float) $r->amount, 2),
                'at'    => Carbon::parse($r->created_at),
            ])->all();
    }

    /**
     * Tasks raised against this customer.
     *
     * Tasks are polymorphic (rel_type/rel_id), and 'customer' is one of the
     * types the module already uses — so this needs no change on Shivam's side.
     */
    private function customerTasks(Client $client): array
    {
        if (! Schema::hasTable('tasks') || ! Schema::hasColumn('tasks', 'rel_type')) {
            return [];
        }

        return DB::table('tasks')
            ->where('tenant_id', $client->tenant_id)
            ->where('rel_type', 'customer')->where('rel_id', $client->id)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')->limit(50)->get()
            ->map(fn ($r) => [
                'type' => 'task', 'label' => 'Task: '.$r->name, 'detail' => $r->status,
                'link' => '/app/tasks/'.$r->id, 'at' => Carbon::parse($r->created_at),
            ])->all();
    }

    /** Meetings where this customer is a subject of the shared engine (§3). */
    private function meetings(Client $client): array
    {
        if (! Schema::hasTable('kickoff_meeting_subjects') || ! Schema::hasTable('kickoff_meetings')) {
            return [];
        }

        return DB::table('kickoff_meeting_subjects as s')
            ->join('kickoff_meetings as m', 'm.id', '=', 's.kickoff_meeting_id')
            ->where('s.tenant_id', $client->tenant_id)
            ->where('s.subject_type', KickoffSubject::classFor(KickoffSubject::CUSTOMER))
            ->where('s.subject_id', $client->id)
            ->orderByDesc('m.scheduled_at')->limit(50)
            ->get(['m.id', 'm.title', 'm.status', 'm.scheduled_at', 'm.created_at'])
            ->map(fn ($r) => [
                'type' => 'meeting',
                'label' => 'Meeting: '.$r->title,
                'detail' => $r->status,
                'link' => '/app/meetings/'.$r->id,
                'at' => Carbon::parse($r->scheduled_at ?: $r->created_at),
            ])->all();
    }
}

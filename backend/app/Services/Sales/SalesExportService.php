<?php

namespace App\Services\Sales;

use App\Models\Sales\CreditNote;
use App\Models\Sales\DeliveryNote;
use App\Models\Sales\Estimate;
use App\Models\Sales\Lead;
use App\Models\Sales\Proposal;
use App\Models\Sales\SalesContract;
use App\Models\Sales\SalesInvoice;
use App\Models\Sales\SalesPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * CSV / XLSX export for the Sales lists.
 *
 * The old CRM got export on every table for free from DataTables; ours had it on
 * exactly two lists (Customers and Group Reports) out of eighteen. Rather than
 * bolt a bespoke exporter onto each page, each list declares its columns here and
 * shares one streaming path.
 *
 * Rows are chunked, and the same `search` matching the UI applies, so what you
 * download is what you were looking at rather than the whole table.
 */
class SalesExportService
{
    /**
     * type => [model, header row, row mapper, eager-loads, searchable columns]
     *
     * Money is exported as a bare number (no ₹, no thousands separators) so the
     * file opens as numeric in a spreadsheet instead of text.
     */
    private function definitions(): array
    {
        $money = static fn ($v) => (float) ($v ?? 0);
        $date  = static fn ($v) => $v ? Carbon::parse($v)->toDateString() : '';

        return [
            'invoices' => [
                'model'  => SalesInvoice::class,
                'with'   => ['customer:id,company'],
                'search' => ['number', 'reference', 'status'],
                'searchRelations' => ['customer' => ['company']],
                'header' => ['Number', 'Client', 'Date', 'Due date', 'Status', 'Subtotal', 'Tax', 'Total', 'Paid', 'Balance'],
                'row'    => fn ($r) => [
                    $r->number, $r->client ?? '', $date($r->date), $date($r->due_date), $r->status,
                    $money($r->subtotal), $money($r->tax_total ?? $r->tax), $money($r->total),
                    $money($r->total - $r->balance), $money($r->balance),
                ],
            ],
            'estimates' => [
                'model'  => Estimate::class,
                'with'   => ['customer:id,company'],
                'search' => ['reference', 'subject', 'status'],
                'searchRelations' => ['customer' => ['company']],
                'header' => ['Reference', 'Subject', 'Client', 'Type', 'Date', 'Valid until', 'Status', 'Subtotal', 'Total'],
                'row'    => fn ($r) => [
                    $r->reference, $r->subject, $r->client ?? '', $r->estimate_type,
                    $date($r->date), $date($r->valid_until), $r->status,
                    $money($r->subtotal), $money($r->total),
                ],
            ],
            'proposals' => [
                'model'  => Proposal::class,
                'with'   => [],
                'search' => ['reference_no', 'subject'],
                'header' => ['Reference', 'Subject', 'Related to', 'Date', 'Open till', 'Status', 'Total'],
                'row'    => fn ($r) => [
                    $r->reference_no, $r->subject, $r->rel_type, $date($r->date), $date($r->open_till),
                    $r->status, $money($r->total),
                ],
            ],
            'credit-notes' => [
                'model'  => CreditNote::class,
                'with'   => ['customer:id,company'],
                'search' => ['number', 'reference', 'status'],
                'searchRelations' => ['customer' => ['company']],
                'header' => ['Number', 'Client', 'Date', 'Status', 'Total', 'Remaining'],
                'row'    => fn ($r) => [
                    $r->number, $r->client ?? '', $date($r->date), $r->status,
                    $money($r->total), $money($r->remaining_amount ?? $r->total),
                ],
            ],
            'delivery-notes' => [
                'model'  => DeliveryNote::class,
                'with'   => ['customer:id,company'],
                'search' => ['number', 'status'],
                'header' => ['Number', 'Client', 'Delivery date', 'Status', 'Shipping city', 'Shipping state'],
                'row'    => fn ($r) => [
                    $r->number, $r->client ?? '', $date($r->delivery_date), $r->status,
                    $r->shipping_city, $r->shipping_state,
                ],
            ],
            'payments' => [
                'model'  => SalesPayment::class,
                'with'   => ['invoice:id,number'],
                'search' => ['transaction_id', 'mode'],
                'header' => ['Date', 'Invoice', 'Mode', 'Transaction ID', 'Amount', 'TDS'],
                'row'    => fn ($r) => [
                    $date($r->date), $r->invoice->number ?? '', $r->mode, $r->transaction_id,
                    $money($r->amount), $money($r->tds_amount),
                ],
            ],
            'contracts' => [
                'model'  => SalesContract::class,
                // Contracts already had a client() RELATION (unlike the documents
                // above, where `client` is a string accessor), so read .company.
                'with'   => ['client:id,company'],
                'search' => ['subject', 'status'],
                'searchRelations' => ['client' => ['company']],
                'header' => ['Subject', 'Client', 'Start', 'End', 'Value', 'Status', 'Signed'],
                'row'    => fn ($r) => [
                    $r->subject, $r->client->company ?? '', $date($r->start_date), $date($r->end_date),
                    $money($r->contract_value), $r->status, $r->signature_data ? 'Yes' : 'No',
                ],
            ],
            'leads' => [
                'model'  => Lead::class,
                'with'   => ['status:id,name', 'source:id,name', 'assignedUser:id,name'],
                'search' => ['name', 'company', 'email', 'phone'],
                'header' => ['Name', 'Company', 'Email', 'Phone', 'Status', 'Source', 'Assigned to', 'Value', 'Created'],
                'row'    => fn ($r) => [
                    $r->name, $r->company, $r->email, $r->phone,
                    $r->status->name ?? '', $r->source->name ?? '', $r->assignedUser->name ?? '',
                    $money($r->lead_value), $date($r->created_at),
                ],
            ],
        ];
    }

    public function types(): array
    {
        return array_keys($this->definitions());
    }

    /**
     * Header + data rows for one list.
     *
     * @param array $filters  status / type / search — mirrors the list endpoints
     */
    public function rows(string $type, int $tenantId, array $filters = []): array
    {
        $def = $this->definitions()[$type] ?? null;
        if (! $def) {
            abort(404, 'Unknown export type.');
        }

        $model = $def['model'];
        $query = $model::query()->where('tenant_id', $tenantId);

        if (! empty($def['with'])) {
            $query->with($def['with']);
        }

        // Only apply a filter the target actually has, so one shared exporter can
        // serve lists with different column sets.
        $table = (new $model)->getTable();

        if (! empty($filters['status']) && $filters['status'] !== 'All' && Schema::hasColumn($table, 'status')) {
            // "Expired" is derived, never stored. The Estimates screen computes it
            // as Sent + past its valid_until, and no code ever writes that status,
            // so matching the literal string exported an empty file for the tab
            // the user was looking at. Reproduce the screen's own rule instead.
            if ($filters['status'] === 'Expired' && Schema::hasColumn($table, 'valid_until')) {
                $query->where(function ($q) use ($table) {
                    $q->where('status', 'Expired')
                      ->orWhere(fn ($e) => $e->where('status', 'Sent')
                                             ->whereDate('valid_until', '<', now()->toDateString()));
                });
            } else {
                $query->where('status', $filters['status']);

                // The same screens hide expired estimates from the plain "Sent"
                // tab, so an export of Sent must hide them too or the file
                // disagrees with the list it came from.
                if ($filters['status'] === 'Sent' && Schema::hasColumn($table, 'valid_until')) {
                    $query->where(fn ($q) => $q->whereNull('valid_until')
                                               ->orWhereDate('valid_until', '>=', now()->toDateString()));
                }
            }
        }

        if (! empty($filters['type']) && Schema::hasColumn($table, 'estimate_type')) {
            $query->where('estimate_type', $filters['type']);
        }

        // Leads filter by pipeline status and Payments by mode. Both were
        // narrowing the screen and neither was reaching the exporter, so the
        // file held every row regardless of the tab being viewed.
        if (! empty($filters['status_id']) && Schema::hasColumn($table, 'status_id')) {
            $query->where('status_id', $filters['status_id']);
        }
        if (! empty($filters['mode']) && $filters['mode'] !== 'All' && Schema::hasColumn($table, 'mode')) {
            $query->where('mode', $filters['mode']);
        }
        if (! empty($filters['search'])) {
            $term = '%' . $filters['search'] . '%';
            $cols = array_values(array_filter(
                $def['search'],
                fn ($c) => Schema::hasColumn($table, $c),
            ));
            // The list pages search the JOINED client name too, because that is
            // the column the user is looking at. Searching only own-table columns
            // made the file disagree with the screen it was exported from.
            $rels = $def['searchRelations'] ?? [];

            $query->where(function ($q) use ($cols, $rels, $term) {
                foreach ($cols as $c) {
                    $q->orWhere($c, 'like', $term);
                }
                foreach ($rels as $relation => $relCols) {
                    $q->orWhereHas($relation, function ($r) use ($relCols, $term) {
                        $r->where(function ($x) use ($relCols, $term) {
                            foreach ($relCols as $rc) {
                                $x->orWhere($rc, 'like', $term);
                            }
                        });
                    });
                }
            });
        }

        $rows = [$def['header']];
        $query->orderByDesc('id')->chunk(500, function ($chunk) use (&$rows, $def) {
            foreach ($chunk as $record) {
                $rows[] = ($def['row'])($record);
            }
        });

        return $rows;
    }

    public function filename(string $type, string $extension): string
    {
        return $type . '_' . now()->format('Y-m-d') . '.' . $extension;
    }
}

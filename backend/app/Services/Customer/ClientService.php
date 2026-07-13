<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ClientService
{
    /** Columns accepted from an import row / offered in an export file. */
    public const IMPORTABLE = [
        'company', 'gst_number', 'phone', 'website', 'parent_company',
        'address', 'city', 'state', 'zip', 'country',
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
        'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_title',
    ];

    public function __construct(private CustomFieldService $customFields)
    {
    }

    /* ── List + summary ───────────────────────────────────────── */
    public function list(int $tenantId, array $filters)
    {
        $query = Client::forTenant($tenantId)
            ->withCount('contacts')
            ->with('groups:id,name')
            ->search($filters['search'] ?? null);

        if (isset($filters['active']) && $filters['active'] !== '') {
            $query->where('active', (bool) $filters['active']);
        }

        if (! empty($filters['group_id'])) {
            $query->whereHas('groups', fn ($q) => $q->where('client_groups.id', $filters['group_id']));
        }

        $sort  = in_array($filters['sort'] ?? '', ['company', 'created_at']) ? $filters['sort'] : 'created_at';
        $order = ($filters['order'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sort, $order)->paginate((int) ($filters['per_page'] ?? 25));
    }

    public function summary(int $tenantId): array
    {
        $clients  = Client::forTenant($tenantId);
        $contacts = ClientContact::forTenant($tenantId);

        return [
            'total'            => (clone $clients)->count(),
            'active'           => (clone $clients)->where('active', true)->count(),
            'inactive'         => (clone $clients)->where('active', false)->count(),
            'contacts'         => (clone $contacts)->count(),
            'with_portal'      => (clone $contacts)->whereNotNull('user_id')->count(),
            'added_this_month' => (clone $clients)
                ->whereMonth('created_at', now()->month)
                ->whereYear('created_at', now()->year)
                ->count(),
        ];
    }

    /* ── CRUD ─────────────────────────────────────────────────── */
    public function create(array $data, int $tenantId, int $userId): Client
    {
        return DB::transaction(function () use ($data, $tenantId, $userId) {
            $contacts     = $data['contacts']     ?? [];
            $groupIds     = $data['group_ids']    ?? [];
            $customFields = $data['custom_fields'] ?? [];
            unset($data['contacts'], $data['group_ids'], $data['custom_fields']);

            $client = Client::create([...$data, 'tenant_id' => $tenantId, 'added_by' => $userId]);

            $this->syncContacts($client, $contacts, $tenantId);
            $client->groups()->sync($this->tenantGroupIds($groupIds, $tenantId));
            $this->customFields->saveValues($tenantId, 'customers', $client->id, $customFields);

            Log::channel('customer')->info('Client created', ['client_id' => $client->id, 'tenant_id' => $tenantId]);

            return $this->show($client->fresh(), $tenantId);
        });
    }

    public function show(Client $client, int $tenantId): Client
    {
        $this->assertTenant($client, $tenantId);

        return $client->load([
            'contacts',
            'groups:id,name',
            'admins:id,name,email',
        ])->loadCount('contacts');
    }

    public function update(Client $client, array $data, int $tenantId): Client
    {
        $this->assertTenant($client, $tenantId);

        return DB::transaction(function () use ($client, $data, $tenantId) {
            $propagate    = (bool) ($data['apply_to_previous_documents'] ?? false);
            $contacts     = $data['contacts']      ?? null;
            $groupIds     = $data['group_ids']     ?? null;
            $customFields = $data['custom_fields'] ?? [];
            unset($data['contacts'], $data['group_ids'], $data['custom_fields'], $data['apply_to_previous_documents']);

            $client->update($data);

            if (is_array($contacts)) {
                $this->syncContacts($client, $contacts, $tenantId, replace: true);
            }
            if (is_array($groupIds)) {
                $client->groups()->sync($this->tenantGroupIds($groupIds, $tenantId));
            }
            $this->customFields->saveValues($tenantId, 'customers', $client->id, $customFields);

            if ($propagate) {
                $this->propagateAddress($client, $tenantId);
            }

            Log::channel('customer')->info('Client updated', [
                'client_id' => $client->id, 'tenant_id' => $tenantId, 'propagated' => $propagate,
            ]);

            return $this->show($client->fresh(), $tenantId);
        });
    }

    public function delete(Client $client, int $tenantId): void
    {
        $this->assertTenant($client, $tenantId);

        DB::transaction(function () use ($client) {
            // Soft-delete cascades to children (a soft delete does not trigger the
            // DB-level FK cascade), and detach pivots so counts stay accurate.
            $client->contacts()->delete();
            $client->groups()->detach();
            $client->admins()->detach();
            $client->delete();
        });

        Log::channel('customer')->info('Client deleted', ['client_id' => $client->id, 'tenant_id' => $tenantId]);
    }

    /* ── Contacts ─────────────────────────────────────────────── */
    /**
     * Upsert contacts. When $replace, contacts omitted from the payload (by id)
     * are removed. Guarantees at most one primary.
     */
    private function syncContacts(Client $client, array $contacts, int $tenantId, bool $replace = false): void
    {
        $keptIds = [];
        $primarySeen = false;

        foreach ($contacts as $row) {
            if (empty($row['email']) && empty($row['first_name'])) {
                continue;
            }

            $isPrimary = ! $primarySeen && (bool) ($row['is_primary'] ?? false);
            if ($isPrimary) {
                $primarySeen = true;
            }

            $payload = [
                'tenant_id'           => $tenantId,
                'first_name'          => $row['first_name'] ?? '',
                'last_name'           => $row['last_name'] ?? null,
                'email'               => $row['email'] ?? null,
                'phone'               => $row['phone'] ?? null,
                'title'               => $row['title'] ?? null,
                'is_primary'          => $isPrimary,
                'active'              => $row['active'] ?? true,
                'email_notifications' => $row['email_notifications'] ?? null,
            ];

            if (! empty($row['id'])) {
                $contact = $client->contacts()->whereKey($row['id'])->first();
                if ($contact) {
                    $contact->update($payload);
                    $keptIds[] = $contact->id;
                    continue;
                }
            }

            $keptIds[] = $client->contacts()->create($payload)->id;
        }

        if ($replace) {
            $client->contacts()->whereNotIn('id', $keptIds ?: [0])->delete();
        }

        // Ensure exactly one primary when contacts exist and none was flagged.
        if (! $primarySeen && $client->contacts()->exists() && ! $client->contacts()->where('is_primary', true)->exists()) {
            $client->contacts()->oldest()->first()?->update(['is_primary' => true]);
        }
    }

    private function tenantGroupIds(array $groupIds, int $tenantId): array
    {
        if (empty($groupIds)) {
            return [];
        }

        return \App\Models\Customer\ClientGroup::forTenant($tenantId)
            ->whereIn('id', $groupIds)
            ->pluck('id')
            ->all();
    }

    /* ── Address propagation ──────────────────────────────────── */
    /**
     * Push the client's current billing/shipping address onto every existing
     * invoice and estimate for this client. Product decision: overwrite ALL
     * documents including paid ones (differs from legacy which skipped paid).
     */
    private function propagateAddress(Client $client, int $tenantId): void
    {
        $snapshot = $client->addressSnapshot();

        $invoices = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)
            ->update($snapshot);

        $estimates = DB::table('estimates')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)
            ->update($snapshot);

        Log::channel('customer')->warning('Client address propagated to documents', [
            'client_id' => $client->id, 'invoices' => $invoices, 'estimates' => $estimates,
        ]);
    }

    /* ── Tax tab: GST / TDS rollup ────────────────────────────── */
    public function taxSummary(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        $invoices = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at');

        $gstTotal    = (clone $invoices)->sum('gst_amount');
        $gstPaid     = (clone $invoices)->where('gst_paid', true)->sum('gst_amount');
        $invoiceRows = (clone $invoices)
            ->select('id', 'number', 'date', 'total', 'gst_amount', 'gst_paid', 'status')
            ->orderByDesc('date')->limit(100)->get();

        // TDS is captured per payment.
        $tdsTotal = DB::table('sales_payments')
            ->join('sales_invoices', 'sales_payments.invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.tenant_id', $tenantId)
            ->where('sales_invoices.client_id', $client->id)
            ->sum('sales_payments.tds_amount');

        return [
            'gst_total'      => (float) $gstTotal,
            'gst_paid'       => (float) $gstPaid,
            'gst_unpaid'     => (float) $gstTotal - (float) $gstPaid,
            'tds_deducted'   => (float) $tdsTotal,
            'invoice_count'  => $invoiceRows->count(),
            'invoices'       => $invoiceRows,
        ];
    }

    /* ── Financial + related-record rollups (loop-in tabs) ────── */
    public function financials(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        $invoices = DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at');

        $outstanding = (clone $invoices)->sum('balance');
        $billed      = (clone $invoices)->sum('total');
        $paid        = (clone $invoices)->sum('paid');

        $credit = 0.0;
        if (Schema::hasTable('credit_notes')) {
            $credit = (float) DB::table('credit_notes')
                ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
                ->sum('remaining');
        }

        return [
            'total_billed'      => (float) $billed,
            'total_paid'        => (float) $paid,
            'outstanding'       => (float) $outstanding,
            'available_credit'  => $credit,
        ];
    }

    /**
     * Support tickets that loop in from Shivam's Helpdesk module via
     * tickets.customer_id (documented integration point, no FK). Read-only and
     * guarded so it degrades gracefully if the helpdesk tables are absent.
     */
    public function relatedTickets(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        if (! Schema::hasTable('tickets')) {
            return [];
        }

        return DB::table('tickets')
            ->where('tenant_id', $tenantId)
            ->where('customer_id', $client->id)
            ->whereNull('deleted_at')
            ->select('id', 'subject', 'status', 'priority', 'created_at')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get()
            ->all();
    }

    /* ── Sales-document loop-ins (read-only, by client_id) ─────── */
    public function invoices(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('sales_invoices')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
            ->select('id', 'number', 'date', 'due_date', 'total', 'paid', 'balance', 'status')
            ->orderByDesc('date')->limit(200)->get()->all();
    }

    public function estimates(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('estimates')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
            ->select('id', 'reference', 'subject', 'date', 'valid_until', 'total', 'status')
            ->orderByDesc('date')->limit(200)->get()->all();
    }

    public function proposals(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('proposals')
            ->where('tenant_id', $tenantId)
            ->where('rel_type', 'customer')->where('rel_id', $client->id)
            ->whereNull('deleted_at')
            ->select('id', 'subject', 'total', 'status', 'created_at')
            ->orderByDesc('created_at')->limit(200)->get()->all();
    }

    public function creditNotes(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('credit_notes')
            ->where('tenant_id', $tenantId)->where('client_id', $client->id)->whereNull('deleted_at')
            ->select('id', 'number', 'date', 'total', 'remaining', 'status')
            ->orderByDesc('date')->limit(200)->get()->all();
    }

    public function payments(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        return DB::table('sales_payments')
            ->join('sales_invoices', 'sales_payments.invoice_id', '=', 'sales_invoices.id')
            ->where('sales_invoices.tenant_id', $tenantId)
            ->where('sales_invoices.client_id', $client->id)
            ->select(
                'sales_payments.id', 'sales_payments.date', 'sales_payments.amount',
                'sales_payments.mode', 'sales_payments.tds_amount', 'sales_invoices.number as invoice_number',
            )
            ->orderByDesc('sales_payments.date')->limit(200)->get()->all();
    }

    /**
     * Chronological account statement: opening balance, then invoices as debits
     * and payments + credit notes as credits, with a running balance.
     */
    public function statement(Client $client, int $tenantId): array
    {
        $this->assertTenant($client, $tenantId);

        $lines = [];

        foreach ($this->invoices($client, $tenantId) as $inv) {
            $lines[] = ['date' => $inv->date, 'type' => 'Invoice', 'ref' => $inv->number, 'debit' => (float) $inv->total, 'credit' => 0.0];
        }
        foreach ($this->payments($client, $tenantId) as $pay) {
            $lines[] = ['date' => (string) $pay->date, 'type' => 'Payment', 'ref' => $pay->invoice_number, 'debit' => 0.0, 'credit' => (float) $pay->amount];
        }
        foreach ($this->creditNotes($client, $tenantId) as $cn) {
            $lines[] = ['date' => $cn->date, 'type' => 'Credit Note', 'ref' => $cn->number, 'debit' => 0.0, 'credit' => (float) $cn->total];
        }

        usort($lines, fn ($a, $b) => strcmp((string) $a['date'], (string) $b['date']));

        $opening = (float) $client->opening_balance;
        $running = $opening;
        foreach ($lines as &$line) {
            $running += $line['debit'] - $line['credit'];
            $line['balance'] = round($running, 2);
        }

        return [
            'opening_balance'      => $opening,
            'opening_balance_date' => optional($client->opening_balance_date)->toDateString(),
            'lines'                => $lines,
            'closing_balance'      => round($running, 2),
        ];
    }

    /* ── Customer admins (account managers) ───────────────────── */
    public function admins(Client $client, int $tenantId): Collection
    {
        $this->assertTenant($client, $tenantId);
        return $client->admins()->select('users.id', 'users.name', 'users.email')->get();
    }

    /** Staff users of the tenant who can be assigned as account managers. */
    public function assignableStaff(int $tenantId): Collection
    {
        return User::where('tenant_id', $tenantId)
            ->whereIn('role', ['admin', 'staff'])
            ->select('id', 'name', 'email')
            ->orderBy('name')
            ->get();
    }

    public function syncAdmins(Client $client, array $userIds, int $tenantId): Collection
    {
        $this->assertTenant($client, $tenantId);

        // The pivot carries a (redundant but NOT NULL) tenant_id, so each synced
        // row must supply it.
        $validIds = User::where('tenant_id', $tenantId)->whereIn('id', $userIds)->pluck('id')->all();
        $syncData = array_fill_keys($validIds, ['tenant_id' => $tenantId]);
        $client->admins()->sync($syncData);

        Log::channel('customer')->info('Customer admins updated', [
            'client_id' => $client->id, 'admins' => $validIds,
        ]);

        return $this->admins($client, $tenantId);
    }

    /* ── Import / Export ──────────────────────────────────────── */
    /**
     * Import parsed CSV rows. First row = header. Duplicate contact emails and
     * existing companies are skipped/merged. Returns a per-file result summary.
     *
     * @param array<int, array<int, string>> $rows raw CSV rows including header
     */
    public function import(array $rows, int $tenantId, int $userId, bool $simulate = false): array
    {
        if (count($rows) < 2) {
            throw new BusinessException('The file has no data rows to import.');
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows));
        $imported = 0; $skipped = 0; $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $raw) {
                $line = $i + 2; // human-friendly (1-based + header)
                if (count(array_filter($raw, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue; // blank line
                }

                $row = $this->mapRow($header, $raw);

                if (empty($row['company'])) {
                    $errors[] = "Row {$line}: missing company"; $skipped++; continue;
                }

                $email = trim((string) ($row['contact_email'] ?? ''));
                if ($email && ClientContact::forTenant($tenantId)->where('email', $email)->exists()) {
                    $errors[] = "Row {$line}: contact email already exists ({$email})"; $skipped++; continue;
                }

                // Merge into an existing company of the same name, else create.
                $client = Client::forTenant($tenantId)->where('company', $row['company'])->first();
                if (! $client) {
                    $client = Client::create([
                        'tenant_id' => $tenantId,
                        'added_by'  => $userId,
                        'company'   => $row['company'],
                        ...array_intersect_key($row, array_flip([
                            'gst_number', 'phone', 'website', 'parent_company',
                            'address', 'city', 'state', 'zip', 'country',
                            'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
                            'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
                        ])),
                    ]);
                }

                if ($row['contact_first_name'] ?? $email) {
                    $client->contacts()->create([
                        'tenant_id'  => $tenantId,
                        'first_name' => $row['contact_first_name'] ?? $row['company'],
                        'last_name'  => $row['contact_last_name'] ?? null,
                        'email'      => $email ?: null,
                        'phone'      => $row['contact_phone'] ?? null,
                        'title'      => $row['contact_title'] ?? null,
                        'is_primary' => ! $client->contacts()->exists(),
                    ]);
                }

                $imported++;
            }

            if ($simulate) {
                DB::rollBack();
            } else {
                DB::commit();
                Log::channel('customer')->info('Clients imported', [
                    'tenant_id' => $tenantId, 'imported' => $imported, 'skipped' => $skipped,
                ]);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new BusinessException('Import failed: ' . $e->getMessage());
        }

        return [
            'imported'  => $imported,
            'skipped'   => $skipped,
            'errors'    => array_slice($errors, 0, 50),
            'simulated' => $simulate,
        ];
    }

    private function mapRow(array $header, array $raw): array
    {
        $row = [];
        foreach ($header as $idx => $key) {
            if (in_array($key, self::IMPORTABLE, true)) {
                $row[$key] = trim((string) ($raw[$idx] ?? ''));
            }
        }
        return $row;
    }

    /** Flat rows for CSV/Excel export (header + data). */
    public function exportRows(int $tenantId, array $filters): array
    {
        $clients = Client::forTenant($tenantId)
            ->with('primaryContact')
            ->search($filters['search'] ?? null)
            ->orderBy('company')
            ->get();

        $header = self::IMPORTABLE;
        $data = [$header];

        foreach ($clients as $c) {
            $pc = $c->primaryContact;
            $data[] = [
                $c->company, $c->gst_number, $c->phone, $c->website, $c->parent_company,
                $c->address, $c->city, $c->state, $c->zip, $c->country,
                $c->billing_street, $c->billing_city, $c->billing_state, $c->billing_zip, $c->billing_country,
                $c->shipping_street, $c->shipping_city, $c->shipping_state, $c->shipping_zip, $c->shipping_country,
                $pc?->first_name, $pc?->last_name, $pc?->email, $pc?->phone, $pc?->title,
            ];
        }

        return $data;
    }

    /* ── Guards ───────────────────────────────────────────────── */
    private function assertTenant(Client $client, int $tenantId): void
    {
        if ($client->tenant_id !== $tenantId) {
            Log::channel('customer')->warning('Unauthorized client access', [
                'client_id' => $client->id, 'tenant_id' => $tenantId,
            ]);
            throw new UnauthorizedTenantException('Unauthorized');
        }
    }
}

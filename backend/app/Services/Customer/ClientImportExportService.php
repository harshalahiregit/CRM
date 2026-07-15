<?php

namespace App\Services\Customer;

use App\Exceptions\BusinessException;
use App\Models\Customer\Client;
use App\Models\Customer\ClientContact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer CSV / Excel import + export. The row format is format-agnostic
 * (plain array of string cells) — the controller reads .csv/.xlsx into rows via
 * App\Support\Spreadsheet and hands them here. IMPORTABLE is the single source
 * of truth for column order across import mapping, export, and the template.
 */
class ClientImportExportService
{
    /** Columns accepted from an import row / offered in an export file. */
    public const IMPORTABLE = [
        'company', 'gst_number', 'phone', 'website', 'parent_company',
        'address', 'city', 'state', 'zip', 'country',
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
        'contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_title',
    ];

    /** Client-table columns an import row may set on a new company. */
    private const COMPANY_FIELDS = [
        'gst_number', 'phone', 'website', 'parent_company',
        'address', 'city', 'state', 'zip', 'country',
        'billing_street', 'billing_city', 'billing_state', 'billing_zip', 'billing_country',
        'shipping_street', 'shipping_city', 'shipping_state', 'shipping_zip', 'shipping_country',
    ];

    /**
     * Import parsed rows. First row = header (name-based mapping, order-tolerant).
     * A company is created or reused by name; a contact is added unless its email
     * already exists. A duplicate contact email skips only the CONTACT, never the
     * company. Returns { created, merged, contacts_added, skipped, errors, simulated }.
     *
     * @param array<int, array<int, string>> $rows raw rows including the header
     */
    public function import(array $rows, int $tenantId, int $userId, bool $simulate = false): array
    {
        if (count($rows) < 2) {
            throw new BusinessException('The file has no data rows to import.');
        }

        $header = array_map(fn ($h) => strtolower(trim($h)), array_shift($rows));
        $created = 0; $merged = 0; $contactsAdded = 0; $skipped = 0; $errors = [];

        DB::beginTransaction();
        try {
            foreach ($rows as $i => $raw) {
                $line = $i + 2; // 1-based + header
                if (count(array_filter($raw, fn ($c) => trim((string) $c) !== '')) === 0) {
                    continue; // blank line
                }

                $row = $this->mapRow($header, $raw);

                if (empty($row['company'])) {
                    $errors[] = "Row {$line}: missing company"; $skipped++; continue;
                }

                // Company: reuse by name, else create. Never blocked by contact dedup.
                $client = Client::forTenant($tenantId)->where('company', $row['company'])->first();
                if ($client) {
                    $merged++;
                } else {
                    $client = Client::create([
                        'tenant_id' => $tenantId,
                        'added_by'  => $userId,
                        'company'   => $row['company'],
                        ...array_intersect_key($row, array_flip(self::COMPANY_FIELDS)),
                    ]);
                    $created++;
                }

                // Contact: add unless the email already exists (dedup on contact only).
                $email = trim((string) ($row['contact_email'] ?? ''));
                $firstName = trim((string) ($row['contact_first_name'] ?? ''));

                if ($email === '' && $firstName === '') {
                    continue; // company-only row
                }

                if ($email !== '' && ClientContact::forTenant($tenantId)->where('email', $email)->exists()) {
                    $errors[] = "Row {$line}: contact email already exists ({$email})"; $skipped++; continue;
                }

                $client->contacts()->create([
                    'tenant_id'  => $tenantId,
                    'first_name' => $firstName !== '' ? $firstName : $row['company'],
                    'last_name'  => $row['contact_last_name'] ?? null,
                    'email'      => $email ?: null,
                    'phone'      => $row['contact_phone'] ?? null,
                    'title'      => $row['contact_title'] ?? null,
                    'is_primary' => ! $client->contacts()->exists(),
                ]);
                $contactsAdded++;
            }

            if ($simulate) {
                DB::rollBack();
            } else {
                DB::commit();
                Log::channel('customer')->info('Clients imported', [
                    'tenant_id' => $tenantId, 'created' => $created, 'merged' => $merged,
                    'contacts_added' => $contactsAdded, 'skipped' => $skipped,
                ]);
            }
        } catch (\Throwable $e) {
            DB::rollBack();
            throw new BusinessException('Import failed: ' . $e->getMessage());
        }

        return [
            'created'        => $created,
            'merged'         => $merged,
            'contacts_added' => $contactsAdded,
            'imported'       => $created + $contactsAdded, // rows that produced a new record
            'skipped'        => $skipped,
            'errors'         => array_slice($errors, 0, 50),
            'simulated'      => $simulate,
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

    /** Flat rows for CSV/Excel export (header + data), streamed to avoid memory blowup. */
    public function exportRows(int $tenantId, array $filters): array
    {
        $data = [self::IMPORTABLE];

        Client::forTenant($tenantId)
            ->with('primaryContact')
            ->search($filters['search'] ?? null)
            ->orderBy('company')
            ->chunk(500, function ($clients) use (&$data) {
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
            });

        return $data;
    }

    /** Sample import template: header + one example row (same column order). */
    public function sampleRows(): array
    {
        return [
            self::IMPORTABLE,
            [
                'Acme Pvt Ltd', '27AABCU9603R1ZM', '9876543210', 'acme.example.com', 'Acme Group',
                '12 MG Road', 'Pune', 'Maharashtra', '411001', 'India',
                '12 MG Road', 'Pune', 'Maharashtra', '411001', 'India',
                'Warehouse 4, Hinjewadi', 'Pune', 'Maharashtra', '411057', 'India',
                'Ravi', 'Sharma', 'ravi@acme.example.com', '9876543211', 'Finance Head',
            ],
        ];
    }
}

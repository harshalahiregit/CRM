<?php

namespace App\Domains\Fleet\Directory;

use App\Domains\Fleet\Contracts\DriverDirectory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Drivers, read live out of the CRM's existing customer and vendor directories.
 *
 * Four separate registers already hold people in this system, each hanging off
 * a customer or a vendor. STOS reads all of them and normalises the rows; it
 * copies nothing, so a phone number corrected in the customer directory is
 * correct in Transport on the next page load, with no sync and no re-entry.
 *
 * Deliberately raw queries against tables rather than imports of Customer,
 * Vendor or Tpv models: those belong to Developers 1 and 3, and a STOS class
 * that imports their Eloquent models would couple this module to their
 * refactors AND break the standalone build (golden rule 3). Every table is
 * probed with Schema::hasTable first, so a deployment missing one of these
 * modules degrades to "that directory is not installed" instead of a 500.
 */
class CrmDriverDirectory implements DriverDirectory
{
    /**
     * The registers, in the order a dispatcher would expect to find someone.
     *
     * Each maps one CRM table onto the common shape. `employer` joins out to
     * the company the person belongs to, because "Rajesh" is not an answer —
     * "Rajesh, Sharma Transport" is.
     */
    private function registers(): array
    {
        return [
            [
                'source' => 'crm_tpv_worker', 'table' => 'tpv_workers', 'label' => 'TPV workforce',
                'name' => 'tpv_workers.name', 'phone' => 'tpv_workers.mobile',
                'designation' => 'tpv_workers.designation',
                'employer' => ['table' => 'vendors', 'on' => 'tpv_workers.vendor_id', 'column' => 'company_name', 'type' => 'vendor'],
            ],
            [
                'source' => 'crm_purchase_worker', 'table' => 'purchase_workers', 'label' => 'Purchase workforce',
                'name' => 'purchase_workers.full_name', 'phone' => 'purchase_workers.phone',
                'designation' => 'purchase_workers.designation',
                'employer' => ['table' => 'purchase_vendors', 'on' => 'purchase_workers.purchase_vendor_id', 'column' => 'company_name', 'type' => 'vendor'],
            ],
            [
                'source' => 'crm_vendor_contact', 'table' => 'vendor_contacts', 'label' => 'Vendor contacts',
                'name' => 'vendor_contacts.name', 'phone' => 'vendor_contacts.phone',
                'designation' => 'vendor_contacts.designation',
                'employer' => ['table' => 'vendors', 'on' => 'vendor_contacts.vendor_id', 'column' => 'company_name', 'type' => 'vendor'],
            ],
            [
                'source' => 'crm_client_contact', 'table' => 'client_contacts', 'label' => 'Customer contacts',
                // client_contacts splits the name across two columns.
                'name' => null, 'phone' => 'client_contacts.phone',
                'designation' => 'client_contacts.title',
                'employer' => ['table' => 'clients', 'on' => 'client_contacts.client_id', 'column' => 'company', 'type' => 'customer'],
            ],
        ];
    }

    public function people(int $companyId, array $filters = []): array
    {
        $rows = [];

        foreach ($this->registers() as $register) {
            if (! Schema::hasTable($register['table'])) {
                continue;   // that module is not installed on this deployment
            }

            foreach ($this->read($register, $companyId, $filters) as $row) {
                $rows[] = $row;
            }
        }

        usort($rows, fn ($a, $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    public function find(int $companyId, string $source, int $sourceId): ?array
    {
        $register = collect($this->registers())->firstWhere('source', $source);

        if (! $register || ! Schema::hasTable($register['table'])) {
            return null;
        }

        return $this->read($register, $companyId, [], $sourceId)[0] ?? null;
    }

    public function describe(): string
    {
        $installed = collect($this->registers())
            ->filter(fn ($r) => Schema::hasTable($r['table']))
            ->pluck('label')->all();

        return $installed === []
            ? 'No CRM directory is installed.'
            : 'Read live from '.implode(', ', $installed).'.';
    }

    /* ── reading one register ───────────────────────────────────── */

    private function read(array $register, int $companyId, array $filters, ?int $onlyId = null): array
    {
        $table = $register['table'];
        $nameExpr = $register['name'] ?? $this->splitNameExpression($table);

        $query = DB::table($table)
            ->where("{$table}.tenant_id", $companyId)
            ->select([
                DB::raw("{$table}.id as source_id"),
                DB::raw("{$nameExpr} as name"),
                DB::raw(($register['phone'] ?? 'NULL')." as phone"),
                DB::raw(($register['designation'] ?? 'NULL').' as designation'),
            ]);

        if (Schema::hasColumn($table, 'deleted_at')) {
            $query->whereNull("{$table}.deleted_at");
        }

        $employer = $register['employer'] ?? null;

        if ($employer && Schema::hasTable($employer['table'])) {
            $query->leftJoin($employer['table'], $employer['table'].'.id', '=', $employer['on'])
                ->addSelect(DB::raw($employer['table'].'.'.$employer['column'].' as employer'));
        } else {
            $query->addSelect(DB::raw('NULL as employer'));
        }

        if ($onlyId !== null) {
            $query->where("{$table}.id", $onlyId);
        }

        if ($term = trim((string) ($filters['q'] ?? ''))) {
            $like = '%'.$term.'%';
            $query->where(function ($w) use ($nameExpr, $register, $like) {
                $w->whereRaw("{$nameExpr} like ?", [$like]);
                if ($register['phone']) {
                    $w->orWhere(DB::raw($register['phone']), 'like', $like);
                }
            });
        }

        // "Drivers only" filters on designation, which is free text in every
        // one of these registers — so it is a keyword match, and the screen
        // says so rather than pretending it is a structured field.
        if (! empty($filters['drivers_only']) && $register['designation']) {
            $query->where(function ($w) use ($register) {
                foreach (config('stos.directory.driver_keywords', ['driver']) as $word) {
                    $w->orWhere(DB::raw('lower('.$register['designation'].')'), 'like', '%'.strtolower($word).'%');
                }
            });
        }

        return $query->limit(500)->get()->map(fn ($r) => [
            'source'        => $register['source'],
            'source_id'     => (int) $r->source_id,
            'ref'           => $register['source'].':'.$r->source_id,
            'name'          => (string) ($r->name ?: 'Unnamed'),
            'phone'         => $r->phone ? (string) $r->phone : null,
            'designation'   => $r->designation ? (string) $r->designation : null,
            'employer'      => $r->employer ? (string) $r->employer : null,
            'employer_type' => $employer['type'] ?? null,
            'directory'     => $register['label'],
        ])->all();
    }

    /** client_contacts keeps first and last name apart; the rest do not. */
    private function splitNameExpression(string $table): string
    {
        // CONCAT_WS is MySQL; SQLite has no such function, so use the portable
        // concatenation both understand for the one table that needs it.
        return DB::connection()->getDriverName() === 'sqlite'
            ? "trim(coalesce({$table}.first_name,'') || ' ' || coalesce({$table}.last_name,''))"
            : "trim(concat(coalesce({$table}.first_name,''),' ',coalesce({$table}.last_name,'')))";
    }
}

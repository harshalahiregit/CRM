<?php

namespace App\Services\Purchase;

use App\Models\Purchase\PurchaseCapa;
use App\Models\Purchase\PurchaseNcr;
use App\Models\Purchase\PurchaseVendor;
use App\Models\Purchase\PurchaseVendorCompliance;
use App\Support\Purchase\PurchaseComplianceCatalog as Catalog;
use Illuminate\Support\Carbon;

/**
 * Purchase Reports & Analytics — the Purchase-side mirror of TpvAnalyticsService
 * (parity rule). Read-only cross-module governance analytics over the Purchase
 * entities shipped so far (compliance, NCR, CAPA) plus a per-vendor benchmark and
 * flat datasets for CSV export. Distinct from the procurement PurchaseReportService.
 *
 * Additive and purely computational; it never writes. Governance dimensions whose
 * Purchase mirrors haven't landed yet (violations, inspections) are simply absent.
 */
class PurchaseAnalyticsService
{
    public const DATASETS = ['vendors', 'ncrs', 'capas', 'benchmark'];

    public function overview(int $tenantId): array
    {
        return [
            'portfolio'  => $this->portfolio($tenantId),
            'governance' => $this->governance($tenantId),
            'compliance' => $this->compliance($tenantId),
        ];
    }

    private function portfolio(int $tenantId): array
    {
        $byStatus = PurchaseVendor::forTenant($tenantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->toArray();

        return ['total' => array_sum($byStatus), 'by_status' => $byStatus];
    }

    private function governance(int $tenantId): array
    {
        $overdue = fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', now());

        return [
            'ncr' => [
                'open'    => PurchaseNcr::forTenant($tenantId)->where('status', '!=', 'Closed')->count(),
                'overdue' => $overdue(PurchaseNcr::forTenant($tenantId)->where('status', '!=', 'Closed'))->count(),
            ],
            'capa' => [
                'open'    => PurchaseCapa::forTenant($tenantId)->where('status', '!=', 'Verified')->count(),
                'overdue' => $overdue(PurchaseCapa::forTenant($tenantId)->where('status', '!=', 'Verified'))->count(),
            ],
        ];
    }

    private function compliance(int $tenantId): array
    {
        $rows = PurchaseVendorCompliance::forTenant($tenantId)->get(['status', 'valid_until']);
        $ok = $rows->filter(fn ($r) => in_array($r->effective_status, Catalog::OK_STATUSES, true))->count();
        $tracked = $rows->count();

        return [
            'tracked' => $tracked,
            'ok'      => $ok,
            'percent' => $tracked ? (int) round($ok / $tracked * 100) : 0,
        ];
    }

    /** Monthly counts for the last $months (oldest → newest) for NCRs and CAPAs. */
    public function trends(int $tenantId, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $buckets[$m->format('Y-m')] = ['label' => $m->format('M Y'), 'ncrs' => 0, 'capas' => 0];
        }
        $start = now()->startOfMonth()->subMonths($months - 1);

        $this->tallyByMonth($buckets, PurchaseNcr::forTenant($tenantId)->where('created_at', '>=', $start)->get(['created_at']), 'ncrs');
        $this->tallyByMonth($buckets, PurchaseCapa::forTenant($tenantId)->where('created_at', '>=', $start)->get(['created_at']), 'capas');

        return array_values($buckets);
    }

    private function tallyByMonth(array &$buckets, $rows, string $key): void
    {
        foreach ($rows as $row) {
            $k = Carbon::parse($row->created_at)->format('Y-m');
            if (isset($buckets[$k])) {
                $buckets[$k][$key]++;
            }
        }
    }

    /** Per-vendor benchmark: compliance %, open NCRs/CAPAs. Worst-compliance-first. */
    public function benchmark(int $tenantId): array
    {
        $vendors = PurchaseVendor::forTenant($tenantId)->get(['id', 'company_name', 'purchase_vendor_code', 'status']);

        $ncrOpen  = $this->countByVendor(PurchaseNcr::forTenant($tenantId)->where('status', '!=', 'Closed'));
        $capaOpen = $this->countByVendor(PurchaseCapa::forTenant($tenantId)->where('status', '!=', 'Verified'));

        $compliance = [];
        foreach (PurchaseVendorCompliance::forTenant($tenantId)->get(['purchase_vendor_id', 'status', 'valid_until']) as $c) {
            $compliance[$c->purchase_vendor_id] ??= ['tracked' => 0, 'ok' => 0];
            $compliance[$c->purchase_vendor_id]['tracked']++;
            if (in_array($c->effective_status, Catalog::OK_STATUSES, true)) {
                $compliance[$c->purchase_vendor_id]['ok']++;
            }
        }

        $rows = $vendors->map(function ($v) use ($ncrOpen, $capaOpen, $compliance) {
            $comp = $compliance[$v->id] ?? ['tracked' => 0, 'ok' => 0];
            $pct = $comp['tracked'] ? (int) round($comp['ok'] / $comp['tracked'] * 100) : null;

            return [
                'vendor_id'      => $v->id,
                'vendor'         => $v->company_name,
                'vendor_code'    => $v->purchase_vendor_code,
                'status'         => $v->status,
                'compliance_pct' => $pct,
                'open_ncrs'      => $ncrOpen[$v->id] ?? 0,
                'open_capas'     => $capaOpen[$v->id] ?? 0,
            ];
        })->all();

        usort($rows, fn ($a, $b) => ($a['compliance_pct'] ?? 101) <=> ($b['compliance_pct'] ?? 101));

        return $rows;
    }

    private function countByVendor($query): array
    {
        return $query->selectRaw('purchase_vendor_id, count(*) as c')
            ->groupBy('purchase_vendor_id')->pluck('c', 'purchase_vendor_id')->toArray();
    }

    /* ── CSV export ─────────────────────────────────────────────────────── */

    public function export(int $tenantId, string $dataset): array
    {
        return match ($dataset) {
            'vendors' => ['purchase-vendors', ['Code', 'Vendor', 'Status', 'Type', 'Email', 'Phone'],
                PurchaseVendor::forTenant($tenantId)->get()->map(fn ($v) => [
                    $v->purchase_vendor_code, $v->company_name, $v->status, $v->vendor_type, $v->email, $v->phone,
                ])->all()],

            'ncrs' => ['purchase-ncrs', ['Reference', 'Title', 'Vendor', 'Severity', 'Status', 'Due', 'Overdue'],
                PurchaseNcr::forTenant($tenantId)->with('vendor:id,company_name')->get()->map(fn ($n) => [
                    $n->reference, $n->title, $n->vendor?->company_name, $n->severity, $n->status,
                    optional($n->due_date)->toDateString(), $n->is_overdue ? 'Yes' : 'No',
                ])->all()],

            'capas' => ['purchase-capas', ['Reference', 'Title', 'Source', 'Type', 'Vendor', 'Priority', 'Status', 'Due', 'Evidence'],
                PurchaseCapa::forTenant($tenantId)->with('vendor:id,company_name')->get()->map(fn ($c) => [
                    $c->reference, $c->title, $c->source_kind, $c->type, $c->vendor?->company_name,
                    $c->priority, $c->status, optional($c->due_date)->toDateString(), $c->evidence_path ? 'Yes' : 'No',
                ])->all()],

            'benchmark' => ['purchase-benchmark', ['Code', 'Vendor', 'Status', 'Compliance %', 'Open NCRs', 'Open CAPAs'],
                array_map(fn ($r) => [
                    $r['vendor_code'], $r['vendor'], $r['status'], $r['compliance_pct'] ?? '—',
                    $r['open_ncrs'], $r['open_capas'],
                ], $this->benchmark($tenantId))],

            default => throw new \App\Exceptions\BusinessException("Unknown export dataset: {$dataset}."),
        };
    }

    public function toCsv(array $header, array $rows): string
    {
        $lines = [$this->csvRow($header)];
        foreach ($rows as $row) {
            $lines[] = $this->csvRow($row);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private function csvRow(array $cells): string
    {
        return implode(',', array_map(function ($c) {
            $c = (string) ($c ?? '');
            if (preg_match('/[",\r\n]/', $c)) {
                $c = '"'.str_replace('"', '""', $c).'"';
            }

            return $c;
        }, $cells));
    }
}

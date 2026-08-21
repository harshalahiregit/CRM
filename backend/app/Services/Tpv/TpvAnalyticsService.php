<?php

namespace App\Services\Tpv;

use App\Models\Tpv\TpvCapa;
use App\Models\Tpv\TpvInspection;
use App\Models\Tpv\TpvNcr;
use App\Models\Tpv\TpvVendorCompliance;
use App\Models\Tpv\TpvVendorViolation;
use App\Models\Vendor\Vendor;
use App\Support\Tpv\ComplianceCatalog;
use Illuminate\Support\Carbon;

/**
 * TPV Reports & Analytics (Sangoe TPV §33). Read-only cross-module analytics —
 * portfolio posture, governance activity, month-over-month trends, a per-vendor
 * benchmark leaderboard, and flat datasets for CSV export.
 *
 * Additive and purely computational: it reads the governance entities already
 * shipped (NCR, CAPA, Violations, Inspections, Compliance) and never writes.
 */
class TpvAnalyticsService
{
    /** Datasets the export endpoint can emit. */
    public const DATASETS = ['vendors', 'ncrs', 'capas', 'violations', 'inspections', 'benchmark'];

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
        $byStatus = Vendor::forTenant($tenantId)
            ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->toArray();

        return [
            'total'     => array_sum($byStatus),
            'by_status' => $byStatus,
        ];
    }

    private function governance(int $tenantId): array
    {
        $overdue = fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', now());

        return [
            'ncr' => [
                'open'    => TpvNcr::forTenant($tenantId)->where('status', '!=', 'Closed')->count(),
                'overdue' => $overdue(TpvNcr::forTenant($tenantId)->where('status', '!=', 'Closed'))->count(),
            ],
            'capa' => [
                'open'    => TpvCapa::forTenant($tenantId)->where('status', '!=', 'Verified')->count(),
                'overdue' => $overdue(TpvCapa::forTenant($tenantId)->where('status', '!=', 'Verified'))->count(),
            ],
            'violations' => [
                'open'   => TpvVendorViolation::forTenant($tenantId)->where('status', 'Open')->count(),
                'points' => (int) TpvVendorViolation::forTenant($tenantId)->where('status', 'Open')->sum('points'),
            ],
            'inspections' => [
                'planned'   => TpvInspection::forTenant($tenantId)->whereIn('status', ['Planned', 'In_Progress'])->count(),
                'completed' => TpvInspection::forTenant($tenantId)->whereIn('status', ['Completed', 'Closed'])->count(),
            ],
        ];
    }

    private function compliance(int $tenantId): array
    {
        $rows = TpvVendorCompliance::forTenant($tenantId)->get(['status', 'valid_until']);
        $ok = 0;
        foreach ($rows as $r) {
            if (in_array($r->effective_status, ComplianceCatalog::OK_STATUSES, true)) {
                $ok++;
            }
        }
        $tracked = $rows->count();

        return [
            'tracked' => $tracked,
            'ok'      => $ok,
            'percent' => $tracked ? (int) round($ok / $tracked * 100) : 0,
        ];
    }

    /**
     * Monthly counts for the last $months (oldest → newest) across the four
     * governance entities. Benchmarkable month-over-month trend series.
     */
    public function trends(int $tenantId, int $months = 6): array
    {
        $months = max(1, min(24, $months));
        $buckets = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $m = now()->startOfMonth()->subMonths($i);
            $buckets[$m->format('Y-m')] = ['label' => $m->format('M Y'), 'ncrs' => 0, 'capas' => 0, 'violations' => 0, 'inspections' => 0];
        }
        $start = now()->startOfMonth()->subMonths($months - 1);

        $this->tallyByMonth($buckets, TpvNcr::forTenant($tenantId)->where('created_at', '>=', $start)->get(['created_at']), 'ncrs');
        $this->tallyByMonth($buckets, TpvCapa::forTenant($tenantId)->where('created_at', '>=', $start)->get(['created_at']), 'capas');
        $this->tallyByMonth($buckets, TpvVendorViolation::forTenant($tenantId)->where('created_at', '>=', $start)->get(['created_at']), 'violations');
        $this->tallyByMonth($buckets, TpvInspection::forTenant($tenantId)->where('created_at', '>=', $start)->get(['created_at']), 'inspections');

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

    /**
     * Per-vendor benchmark leaderboard: compliance %, open NCRs/CAPAs, violation
     * points and strikes. Sorted worst-compliance-first so the vendors needing
     * attention surface at the top.
     */
    public function benchmark(int $tenantId): array
    {
        $vendors = Vendor::forTenant($tenantId)->get(['id', 'company_name', 'vendor_code', 'status']);

        $ncrOpen  = $this->countByVendor(TpvNcr::forTenant($tenantId)->where('status', '!=', 'Closed'));
        $capaOpen = $this->countByVendor(TpvCapa::forTenant($tenantId)->where('status', '!=', 'Verified'));
        $vioPts   = TpvVendorViolation::forTenant($tenantId)->where('status', 'Open')
            ->selectRaw('vendor_id, sum(points) as p')->groupBy('vendor_id')->pluck('p', 'vendor_id')->toArray();

        // Compliance % per vendor from the categorised register.
        $compliance = [];
        foreach (TpvVendorCompliance::forTenant($tenantId)->get(['vendor_id', 'status', 'valid_until']) as $c) {
            $compliance[$c->vendor_id] ??= ['tracked' => 0, 'ok' => 0];
            $compliance[$c->vendor_id]['tracked']++;
            if (in_array($c->effective_status, ComplianceCatalog::OK_STATUSES, true)) {
                $compliance[$c->vendor_id]['ok']++;
            }
        }

        $rows = $vendors->map(function ($v) use ($ncrOpen, $capaOpen, $vioPts, $compliance) {
            $comp = $compliance[$v->id] ?? ['tracked' => 0, 'ok' => 0];
            $pct = $comp['tracked'] ? (int) round($comp['ok'] / $comp['tracked'] * 100) : null;

            return [
                'vendor_id'      => $v->id,
                'vendor'         => $v->company_name,
                'vendor_code'    => $v->vendor_code,
                'status'         => $v->status,
                'compliance_pct' => $pct,
                'open_ncrs'      => $ncrOpen[$v->id] ?? 0,
                'open_capas'     => $capaOpen[$v->id] ?? 0,
                'violation_points' => (int) ($vioPts[$v->id] ?? 0),
            ];
        })->all();

        // Worst compliance first (untracked vendors sink below tracked ones).
        usort($rows, fn ($a, $b) => ($a['compliance_pct'] ?? 101) <=> ($b['compliance_pct'] ?? 101));

        return $rows;
    }

    private function countByVendor($query): array
    {
        return $query->selectRaw('vendor_id, count(*) as c')->groupBy('vendor_id')->pluck('c', 'vendor_id')->toArray();
    }

    /* ── CSV export ─────────────────────────────────────────────────────── */

    /** Build a [filename, header[], rows[][]] tuple for one dataset. */
    public function export(int $tenantId, string $dataset): array
    {
        return match ($dataset) {
            'vendors' => ['tpv-vendors', ['Code', 'Vendor', 'Status', 'Type', 'Email', 'Phone'],
                Vendor::forTenant($tenantId)->get()->map(fn ($v) => [
                    $v->vendor_code, $v->company_name, $v->status, $v->vendor_type, $v->email, $v->phone,
                ])->all()],

            'ncrs' => ['tpv-ncrs', ['Reference', 'Title', 'Vendor', 'Severity', 'Status', 'Due', 'Overdue'],
                TpvNcr::forTenant($tenantId)->with('vendor:id,company_name')->get()->map(fn ($n) => [
                    $n->reference, $n->title, $n->vendor?->company_name, $n->severity, $n->status,
                    optional($n->due_date)->toDateString(), $n->is_overdue ? 'Yes' : 'No',
                ])->all()],

            'capas' => ['tpv-capas', ['Reference', 'Title', 'Source', 'Type', 'Vendor', 'Priority', 'Status', 'Due', 'Evidence'],
                TpvCapa::forTenant($tenantId)->with('vendor:id,company_name')->get()->map(fn ($c) => [
                    $c->reference, $c->title, $c->source_kind, $c->type, $c->vendor?->company_name,
                    $c->priority, $c->status, optional($c->due_date)->toDateString(), $c->evidence_path ? 'Yes' : 'No',
                ])->all()],

            'violations' => ['tpv-violations', ['Reference', 'Vendor', 'Type', 'Severity', 'Points', 'Status', 'Occurred'],
                TpvVendorViolation::forTenant($tenantId)->with('vendor:id,company_name')->get()->map(fn ($v) => [
                    $v->reference, $v->vendor?->company_name, $v->type, $v->severity, $v->points, $v->status,
                    optional($v->occurred_at)->toDateString(),
                ])->all()],

            'inspections' => ['tpv-inspections', ['Reference', 'Type', 'Status', 'Score', 'Scheduled', 'Conducted'],
                TpvInspection::forTenant($tenantId)->get()->map(fn ($i) => [
                    $i->reference, $i->type, $i->status, $i->score,
                    optional($i->scheduled_date)->toDateString(), optional($i->conducted_date)->toDateString(),
                ])->all()],

            'benchmark' => ['tpv-benchmark', ['Code', 'Vendor', 'Status', 'Compliance %', 'Open NCRs', 'Open CAPAs', 'Violation Points'],
                array_map(fn ($r) => [
                    $r['vendor_code'], $r['vendor'], $r['status'], $r['compliance_pct'] ?? '—',
                    $r['open_ncrs'], $r['open_capas'], $r['violation_points'],
                ], $this->benchmark($tenantId))],

            default => throw new \App\Exceptions\BusinessException("Unknown export dataset: {$dataset}."),
        };
    }

    /** Render a header + rows into an RFC-4180 CSV string. */
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

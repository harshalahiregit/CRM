<?php

namespace App\Services\Sales;

use App\Models\Sales\Lead;
use App\Models\Sales\LeadStatus;

/**
 * Computed sales forecasting — no storage. Expected revenue is derived from
 * open leads weighted by conversion_chance, bucketed by expected_close_date
 * (falls back to created_at when a lead has no expected close).
 */
class ForecastService
{
    /**
     * @return array{period:string, buckets:array<int,array{label:string,weighted:float,gross:float,count:int}>, totals:array}
     */
    public function revenueForecast(int $tenantId, string $period = 'monthly'): array
    {
        $leads = Lead::forTenant($tenantId)->active()
            ->get(['id', 'lead_value', 'conversion_chance', 'expected_close_date', 'created_at']);

        $buckets = [];
        foreach ($leads as $lead) {
            $date  = $lead->expected_close_date ?? $lead->created_at;
            $key   = $this->bucketKey($date, $period);
            $chance = ($lead->conversion_chance ?? 0) / 100;
            $value  = (float) $lead->lead_value;

            $buckets[$key] ??= ['label' => $key, 'weighted' => 0.0, 'gross' => 0.0, 'count' => 0];
            $buckets[$key]['weighted'] += round($value * $chance, 2);
            $buckets[$key]['gross']    += $value;
            $buckets[$key]['count']    += 1;
        }

        ksort($buckets);
        $buckets = array_values($buckets);

        return [
            'period'  => $period,
            'buckets' => $buckets,
            'totals'  => [
                'weighted' => round(array_sum(array_column($buckets, 'weighted')), 2),
                'gross'    => round(array_sum(array_column($buckets, 'gross')), 2),
                'count'    => array_sum(array_column($buckets, 'count')),
            ],
        ];
    }

    public function pipelineByStage(int $tenantId): array
    {
        $statuses = LeadStatus::forTenant($tenantId)->ordered()->get();
        $leads    = Lead::forTenant($tenantId)->active()->get(['status_id', 'lead_value', 'conversion_chance']);

        return $statuses->map(function ($status) use ($leads) {
            $stageLeads = $leads->where('status_id', $status->id);
            return [
                'id'       => $status->id,
                'name'     => $status->name,
                'color'    => $status->color,
                'count'    => $stageLeads->count(),
                'value'    => round($stageLeads->sum('lead_value'), 2),
                'weighted' => round($stageLeads->sum(fn ($l) => (float) $l->lead_value * ($l->conversion_chance ?? 0) / 100), 2),
            ];
        })->values()->all();
    }

    public function funnel(int $tenantId): array
    {
        $statuses = LeadStatus::forTenant($tenantId)->ordered()->get();
        $total    = Lead::forTenant($tenantId)->count();
        $active   = Lead::forTenant($tenantId)->active()->get(['status_id']);
        $converted = Lead::forTenant($tenantId)->converted()->count();

        $stages = $statuses->map(fn ($s) => [
            'name'  => $s->name,
            'count' => $active->where('status_id', $s->id)->count(),
        ])->values()->all();

        return [
            'stages'          => $stages,
            'total_leads'     => $total,
            'converted'       => $converted,
            'conversion_rate' => $total > 0 ? round($converted / $total * 100, 1) : 0,
        ];
    }

    private function bucketKey($date, string $period): string
    {
        $c = $date instanceof \Carbon\Carbon ? $date : \Illuminate\Support\Carbon::parse($date);
        return match ($period) {
            'quarterly' => $c->format('Y') . '-Q' . $c->quarter,
            'annual'    => $c->format('Y'),
            default     => $c->format('Y-m'),
        };
    }
}

<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Services\SireQualityMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * SIRE — the quality panel of the dashboard.
 *
 * Counts, ratios and buckets. No forecasting, no anomaly detection, no scoring
 * model, no AI, and no external service.
 */
class SireQualityController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;

    private const MAX_WINDOW_DAYS = 730;

    public function __construct(private readonly SireQualityMetricsService $metrics)
    {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from'        => ['nullable', 'date'],
            'to'          => ['nullable', 'date', 'after_or_equal:from'],
            'bucket'      => ['nullable', Rule::in(['week', 'month'])],
            'module'      => ['nullable', 'string', 'max:64'],
            'severity_id' => ['nullable', 'integer'],
        ]);

        $to = isset($data['to']) ? CarbonImmutable::parse($data['to'])->endOfDay() : CarbonImmutable::now();
        $from = isset($data['from'])
            ? CarbonImmutable::parse($data['from'])->startOfDay()
            : $to->subDays(90)->startOfDay();

        // A two-year cap keeps one curious click from scanning the whole register
        // on a database shared by two deployments.
        if ($from->diffInDays($to) > self::MAX_WINDOW_DAYS) {
            $from = $to->subDays(self::MAX_WINDOW_DAYS);
        }

        $window = ['from' => $from, 'to' => $to];
        $user = $this->sireUser();
        $tenantId = (int) $user->tenantId;
        $filters = array_filter([
            'module'      => $data['module'] ?? null,
            'severity_id' => $data['severity_id'] ?? null,
        ]);

        return $this->success([
            'rates'                  => $this->metrics->rates($tenantId, $user, $window, $filters),
            'trend'                  => $this->metrics->trend($tenantId, $user, $window, $data['bucket'] ?? 'week', $filters),
            'top_modules'            => $this->metrics->topModules($tenantId, $user, $window),
            'regressions_by_release' => $this->metrics->regressionsByRelease($tenantId, $user),
            'recurring_issues'       => $this->metrics->recurringIssues($tenantId),
        ]);
    }
}

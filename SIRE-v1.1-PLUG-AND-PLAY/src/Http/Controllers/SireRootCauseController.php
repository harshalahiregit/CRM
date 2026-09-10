<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\StoreRootCauseRequest;
use Sire\Models\Report;
use Sire\Services\SireRootCauseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SireRootCauseController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireRootCauseService $rca)
    {
    }

    public function show(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success([
            'root_cause'         => $report->rootCause,
            'requires_five_whys' => $this->rca->requiresFiveWhys($report),
        ]);
    }

    public function store(StoreRootCauseRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->rca->save($report, $request->validated(), $this->sireUser()));
    }

    public function confirm(Request $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->rca->confirm($report, $this->sireUser()));
    }
}

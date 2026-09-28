<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Integration\Services\TelemetryIngestionService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Stos\IngestTelemetryBatchRequest;
use App\Http\Requests\Stos\IngestTelemetryRequest;
use Illuminate\Http\JsonResponse;

/**
 * STOS-INT — the endpoint hardware posts to.
 *
 * Ultra-thin by rule (golden rule 4): the FormRequest validates, the domain
 * service decides, and this only hands one to the other. Anything that looks
 * like a rule belongs in TelemetryIngestionService, not here.
 */
class TelemetryIngestionController extends Controller
{
    use ApiResponse;

    public function __construct(private TelemetryIngestionService $ingestion)
    {
    }

    public function ingest(IngestTelemetryRequest $request): JsonResponse
    {
        return $this->success(
            $this->ingestion->ingest($request->validated(), $this->deviceCompany($request)),
            'Telemetry recorded',
            201
        );
    }

    /**
     * T-13 — a buffered run in one request.
     *
     * Answers 201 even when some readings were rejected: the response body says
     * how many landed and names the ones that did not. A device cannot act on a
     * blanket 422 except by resending the whole buffer, which is how a bad ping
     * turns into an infinite retry of fifty-nine good ones.
     */
    public function ingestBatch(IngestTelemetryBatchRequest $request): JsonResponse
    {
        return $this->success(
            $this->ingestion->ingestBatch($request->validated()['readings'], $this->deviceCompany($request)),
            'Telemetry batch processed',
            201
        );
    }

    /**
     * Which company this unit's credential belongs to, if it has its own.
     *
     * Read from the request attribute the device-token middleware set, NEVER
     * from the payload: a GPS box does not get to nominate which company's
     * truck it is reporting for. Null means the caller used the legacy
     * fleet-wide secret, and resolution falls back to searching every company.
     */
    private function deviceCompany(\Illuminate\Http\Request $request): ?int
    {
        $companyId = $request->attributes->get('stos_device_company_id');

        return $companyId === null ? null : (int) $companyId;
    }
}

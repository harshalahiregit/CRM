<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Models\Trailer;
use App\Domains\Fleet\Services\TrailerService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — the trailer register and the coupling between (T-54).
 *
 * CLP §5 lets a client ask for a trailer type on an order. Until this existed
 * there was nowhere for that answer to land: `trailer` is one of seven values
 * of `vehicles.vehicle_type` with no sub-type beneath it.
 */
class TrailerController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private TrailerService $trailers)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $filters = $request->validate([
            'q'            => ['nullable', 'string', 'max:40'],
            'status'       => ['nullable', Rule::in(Trailer::STATUSES)],
            'trailer_type' => ['nullable', Rule::in(Trailer::TYPES)],
        ]);

        return $this->success($this->trailers->list($this->companyId($request), $filters), 'Trailers retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate($this->rules(true));

        return $this->success(
            $this->trailers->register($this->companyId($request), $data, $request->user()?->id),
            'Trailer registered',
            201
        );
    }

    public function update(Request $request, int $trailer): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate($this->rules(false));

        return $this->success(
            $this->trailers->update($trailer, $this->companyId($request), $data, $request->user()?->id),
            'Trailer updated'
        );
    }

    public function compliance(Request $request, int $trailer): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->trailers->complianceFor($trailer, $this->companyId($request)),
            'Trailer compliance retrieved'
        );
    }

    /* ── Coupling ───────────────────────────────────────────────────── */

    public function couple(Request $request, int $trailer): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'min:1'],
            'reason'     => ['nullable', 'string', 'max:500'],
        ]);

        return $this->success(
            $this->trailers->couple(
                $this->companyId($request), $data['vehicle_id'], $trailer,
                $request->user()?->id, $data['reason'] ?? null
            ),
            'Trailer coupled',
            201
        );
    }

    public function uncouple(Request $request, int $trailer): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return $this->success(
            $this->trailers->uncouple(
                $this->companyId($request), $trailer, $request->user()?->id, $data['reason'] ?? null
            ),
            'Trailer uncoupled'
        );
    }

    /**
     * Which trailer was under which truck, and when.
     *
     * The question the whole feature exists for, so it is an endpoint of its
     * own rather than a field on something else.
     */
    public function history(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'vehicle_id' => ['nullable', 'integer', 'min:1'],
            'trailer_id' => ['nullable', 'integer', 'min:1'],
            'limit'      => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return $this->success(
            $this->trailers->history(
                $this->companyId($request),
                $data['vehicle_id'] ?? null,
                $data['trailer_id'] ?? null,
                $data['limit'] ?? 50
            ),
            'Coupling history retrieved'
        );
    }

    private function rules(bool $creating): array
    {
        return [
            'trailer_number' => [$creating ? 'required' : 'nullable', 'string', 'max:40'],
            'trailer_type'   => [$creating ? 'required' : 'nullable', Rule::in(Trailer::TYPES)],
            'ownership_type' => ['nullable', Rule::in(Trailer::OWNERSHIPS)],
            'fleet_number'   => ['nullable', 'string', 'max:40'],

            'capacity_tonnes' => ['nullable', 'numeric', 'gt:0', 'max:999999'],
            'axles'           => ['nullable', 'integer', 'min:1', 'max:20'],
            'length_feet'     => ['nullable', 'numeric', 'gt:0', 'max:200'],

            'manufacturer'       => ['nullable', 'string', 'max:100'],
            'model'              => ['nullable', 'string', 'max:100'],
            'manufacturing_year' => ['nullable', 'integer', 'min:1950', 'max:2100'],
            'purchase_date'      => ['nullable', 'date'],
            'chassis_number'     => ['nullable', 'string', 'max:100'],

            // Four, not five. A trailer has no engine, so no PUC.
            //
            // `prohibited` rather than simply unlisted: Laravel ignores keys it
            // was not asked about, so a caller sending `puc_expiry` got a 201
            // and assumed it had been saved. Silently accepting a field that
            // can never be stored is worse than refusing it, and on a trailer
            // it is exactly the confusion this design exists to prevent.
            'puc_expiry' => ['prohibited'],

            'registration_expiry' => ['nullable', 'date'],
            'fitness_expiry'      => ['nullable', 'date'],
            'insurance_expiry'    => ['nullable', 'date'],
            'permit_expiry'       => ['nullable', 'date'],

            // MANUALLY_SETTABLE, not STATUSES: COUPLED is written by coupling
            // and COMPLIANCE_BLOCKED is derived from the dates.
            'status' => ['nullable', Rule::in(Trailer::MANUALLY_SETTABLE)],
            'note'   => ['nullable', 'string', 'max:500'],
        ];
    }
}

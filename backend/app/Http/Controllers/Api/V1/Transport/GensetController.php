<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Models\Genset;
use App\Domains\Fleet\Services\GensetService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-FLEET — the genset register and its fitments (T-05).
 *
 * Thin by rule: the FormRequest-equivalent validation is here, every decision is
 * in GensetService. Fitting and unfitting are their own endpoints rather than a
 * `vehicle_id` on the update, because they are events — a unit physically
 * moving between trailers — and they are logged as such.
 */
class GensetController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private GensetService $gensets)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->gensets->register($this->companyId($request), [
                'status'        => $request->query('status'),
                'unfitted_only' => $request->boolean('unfitted_only'),
                'q'             => $request->query('q'),
            ]),
            'Gensets retrieved'
        );
    }

    public function store(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'serial_number' => 'required|string|max:60',
            'status'        => ['nullable', Rule::in(Genset::STATUSES)],
            // Optional: a unit can be registered to the yard and fitted later.
            'vehicle_id'    => 'nullable|integer|min:1',
        ]);

        return $this->success(
            $this->gensets->create($this->companyId($request), $data, $request->user()->id),
            'Genset registered',
            201
        );
    }

    public function update(Request $request, int $genset): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'serial_number' => 'nullable|string|max:60',
            'status'        => ['nullable', Rule::in(Genset::STATUSES)],
        ]);

        return $this->success(
            $this->gensets->update($genset, $this->companyId($request), $data, $request->user()->id),
            'Genset updated'
        );
    }

    public function fit(Request $request, int $genset): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate(['vehicle_id' => 'required|integer|min:1']);

        return $this->success(
            $this->gensets->fit($genset, $this->companyId($request), (int) $data['vehicle_id'], $request->user()->id),
            'Genset fitted'
        );
    }

    public function unfit(Request $request, int $genset): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->gensets->unfit($genset, $this->companyId($request), $request->user()->id),
            'Genset removed'
        );
    }
}

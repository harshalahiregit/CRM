<?php

namespace App\Http\Controllers\Api\V1\Transport;

use App\Domains\Fleet\Models\TyreMaster;
use App\Domains\Fleet\Services\TyreMasterService;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * STOS-MAINT — the casing register (T-36 / T-37 / T-38).
 *
 * `TyreController` records what is on which axle. This owns the asset: what it
 * cost, how many lives it has had, and when it will need replacing.
 */
class TyreMasterController extends Controller
{
    use ApiResponse;
    use ResolvesTransportAccess;

    public function __construct(private TyreMasterService $tyres)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $filters = $request->validate([
            'q'      => ['nullable', 'string', 'max:60'],
            'status' => ['nullable', Rule::in(TyreMaster::STATUSES)],
            'brand'  => ['nullable', 'string', 'max:60'],
        ]);

        return $this->success($this->tyres->register_list($this->companyId($request), $filters), 'Tyres retrieved');
    }

    public function store(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->tyres->register($this->companyId($request), $request->validate($this->rules(true)), $request->user()?->id),
            'Tyre registered',
            201
        );
    }

    public function update(Request $request, int $tyre): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->tyres->update($tyre, $this->companyId($request), $request->validate($this->rules(false)), $request->user()?->id),
            'Tyre updated'
        );
    }

    /** T-36 / T-38 — cost per kilometre and the wear forecast. */
    public function economics(Request $request, int $tyre): JsonResponse
    {
        $this->denyExternal($request);

        return $this->success(
            $this->tyres->economics($tyre, $this->companyId($request)),
            'Tyre economics retrieved'
        );
    }

    /** T-37 — swap two fitted positions on one asset, in one operation. */
    public function rotate(Request $request): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'first_fitment_id'  => ['required', 'integer', 'min:1'],
            'second_fitment_id' => ['required', 'integer', 'min:1', 'different:first_fitment_id'],
            'odometer'          => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        return $this->success(
            $this->tyres->rotate(
                $this->companyId($request), $data['first_fitment_id'], $data['second_fitment_id'],
                $data['odometer'] ?? null, $request->user()?->id
            ),
            'Tyres rotated',
            201
        );
    }

    public function retread(Request $request, int $tyre): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'cost'            => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'new_tread_depth' => ['nullable', 'numeric', 'gt:0', 'max:99.99'],
        ]);

        return $this->success(
            $this->tyres->retread(
                $tyre, $this->companyId($request), $data['cost'] ?? null,
                $data['new_tread_depth'] ?? null, $request->user()?->id
            ),
            'Tyre retreaded'
        );
    }

    public function scrap(Request $request, int $tyre): JsonResponse
    {
        $this->denyExternal($request);

        $data = $request->validate([
            // Required, not nullable: a scrapped casing is money written off,
            // and "why" is the only thing that makes the next purchase better.
            'reason' => ['required', 'string', 'max:255'],
        ]);

        return $this->success(
            $this->tyres->scrap($tyre, $this->companyId($request), $data['reason'], $request->user()?->id),
            'Tyre scrapped'
        );
    }

    private function rules(bool $creating): array
    {
        return [
            'serial_number' => [$creating ? 'required' : 'nullable', 'string', 'max:60'],
            'brand'         => ['nullable', 'string', 'max:60'],
            // Free text: the format varies by market and a dropdown would be
            // wrong within a year.
            'size'          => ['nullable', 'string', 'max:40'],
            'pattern'       => ['nullable', 'string', 'max:60'],

            'purchase_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'purchase_date' => ['nullable', 'date'],
            'supplier'      => ['nullable', 'string', 'max:120'],

            'new_tread_depth'   => ['nullable', 'numeric', 'gt:0', 'max:99.99'],
            // The legal floor this casing is judged against. Without it the
            // forecast can say how fast it is wearing but not when to act.
            'scrap_tread_depth' => ['nullable', 'numeric', 'gt:0', 'max:99.99'],

            // MANUALLY_SETTABLE: FITTED is written by fitting, RETREADED by the
            // retread action. Either would be a box that always errors.
            'status' => ['nullable', Rule::in(TyreMaster::MANUALLY_SETTABLE)],
            'note'   => ['nullable', 'string', 'max:500'],
        ];
    }
}

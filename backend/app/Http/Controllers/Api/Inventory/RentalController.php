<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Rental;
use App\Services\Inventory\RentalService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Rental register. Internal staff manage rentals; deleting is admin-only. */
class RentalController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private RentalService $rentals)
    {
    }

    public function index(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->rentals->list($request->user()->tenant_id, $request->only(['status', 'search'])), 'Rentals retrieved');
    }

    public function show(Request $request, int $rental)
    {
        $this->denyExternal($request);

        return $this->success($this->rentals->show($rental, $request->user()->tenant_id), 'Rental retrieved');
    }

    public function store(Request $request)
    {
        $this->denyExternal($request);

        return $this->success($this->rentals->create($this->validated($request), $request->user()->tenant_id, $request->user()->id), 'Rental created', 201);
    }

    public function update(Request $request, int $rental)
    {
        $this->denyExternal($request);

        return $this->success($this->rentals->update($rental, $this->validated($request, false), $request->user()->tenant_id), 'Rental updated');
    }

    public function checkout(Request $request, int $rental)
    {
        $this->denyExternal($request);
        $data = $request->validate(['out_date' => 'nullable|date', 'due_date' => 'nullable|date']);

        return $this->success($this->rentals->checkout($rental, $data, $request->user()->tenant_id), 'Rental checked out');
    }

    public function returnItem(Request $request, int $rental)
    {
        $this->denyExternal($request);
        $data = $request->validate(['returned_date' => 'nullable|date', 'charged' => 'nullable|numeric|min:0']);

        return $this->success($this->rentals->returnItem($rental, $data, $request->user()->tenant_id), 'Rental returned');
    }

    public function cancel(Request $request, int $rental)
    {
        $this->denyExternal($request);

        return $this->success($this->rentals->cancel($rental, $request->user()->tenant_id), 'Rental cancelled');
    }

    public function destroy(Request $request, int $rental)
    {
        $this->requireAdmin($request, 'delete a rental');
        $this->rentals->delete($rental, $request->user()->tenant_id);

        return $this->success(null, 'Rental deleted');
    }

    private function validated(Request $request, bool $creating = true): array
    {
        return $request->validate([
            'customer_name'    => ($creating ? 'required' : 'sometimes').'|string|max:180',
            'customer_contact' => 'nullable|string|max:120',
            'product_id'       => 'nullable|integer',
            'asset_id'         => 'nullable|integer',
            'item_label'       => 'nullable|string|max:180',
            'warehouse_id'     => 'nullable|integer',
            'qty'              => 'nullable|numeric|min:0',
            'rate'             => 'nullable|numeric|min:0',
            'rate_period'      => ['nullable', Rule::in(Rental::PERIODS)],
            'deposit'          => 'nullable|numeric|min:0',
            'due_date'         => 'nullable|date',
            'note'             => 'nullable|string|max:2000',
        ]);
    }
}

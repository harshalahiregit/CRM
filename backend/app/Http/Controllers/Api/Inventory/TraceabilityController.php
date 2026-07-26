<?php

namespace App\Http\Controllers\Api\Inventory;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Models\Inventory\Batch;
use App\Models\Inventory\Reservation;
use App\Models\Inventory\Serial;
use App\Models\Inventory\SerialEvent;
use App\Services\Inventory\TraceabilityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Batches, serial numbers, reservations and the expiry dashboard.
 *
 * Reading is open to internal staff (you can't pick stock you can't see).
 * Deleting a batch or serial is master-data destruction, so it's admin-only,
 * matching how items and warehouses behave. A reservation can be released by
 * an admin or by whoever placed it — the Projects "creator owns it" rule.
 */
class TraceabilityController extends Controller
{
    use ApiResponse;
    use GuardsInventoryAccess;

    public function __construct(private TraceabilityService $trace)
    {
    }

    /* ── Batches ────────────────────────────────────────────────── */

    public function batches(Request $request)
    {
        $this->denyExternal($request);
        $f = $request->only(['product_id', 'warehouse_id', 'quality_status', 'search', 'expiring_days', 'expired', 'include_empty']);

        return $this->success($this->trace->batches($request->user()->tenant_id, $f), 'Batches retrieved');
    }

    public function storeBatch(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'product_id'      => 'required|integer|min:1',
            'warehouse_id'    => 'nullable|integer|min:1',
            'batch_no'        => 'required|string|max:60',
            'lot_number'      => 'nullable|string|max:60',
            'vendor_batch_no' => 'nullable|string|max:60',
            'manufactured_at' => 'nullable|date',
            'expiry_date'     => 'nullable|date|after_or_equal:manufactured_at',
            'received_qty'    => 'required|numeric|min:0',
            'cost_price'      => 'nullable|numeric|min:0',
            'quality_status'  => ['nullable', Rule::in(Batch::QUALITY)],
            'note'            => 'nullable|string|max:1000',
        ]);

        return $this->success(
            $this->trace->createBatch($data, $request->user()->tenant_id, $request->user()->id),
            'Batch created', 201
        );
    }

    public function updateBatch(Request $request, int $batch)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'warehouse_id'    => 'nullable|integer|min:1',
            'lot_number'      => 'nullable|string|max:60',
            'vendor_batch_no' => 'nullable|string|max:60',
            'manufactured_at' => 'nullable|date',
            'expiry_date'     => 'nullable|date',
            'remaining_qty'   => 'nullable|numeric|min:0',
            'cost_price'      => 'nullable|numeric|min:0',
            'quality_status'  => ['nullable', Rule::in(Batch::QUALITY)],
            'note'            => 'nullable|string|max:1000',
        ]);

        return $this->success(
            $this->trace->updateBatch($batch, array_filter($data, fn ($v) => $v !== null), $request->user()->tenant_id),
            'Batch updated'
        );
    }

    public function destroyBatch(Request $request, int $batch)
    {
        $this->requireAdmin($request, 'delete a batch');
        $this->trace->deleteBatch($batch, $request->user()->tenant_id);

        return $this->success(null, 'Batch deleted');
    }

    /** Recall a batch — quarantines it and alerts the responsible people. */
    public function recallBatch(Request $request, int $batch)
    {
        $this->denyExternal($request);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        return $this->success(
            $this->trace->recallBatch($batch, $data['reason'], $request->user()->tenant_id, $request->user()->id),
            'Batch recalled'
        );
    }

    /** Lift a recall — admin only, since it returns the stock to sellable. */
    public function liftRecall(Request $request, int $batch)
    {
        $this->requireAdmin($request, 'lift a recall');

        return $this->success(
            $this->trace->liftRecall($batch, $request->user()->tenant_id),
            'Recall lifted'
        );
    }

    /** FEFO pick plan — which batches to draw a quantity from, soonest expiry first. */
    public function fefo(Request $request)
    {
        $this->denyExternal($request);

        $d = $request->validate([
            'product_id'   => 'required|integer|min:1',
            'quantity'     => 'required|numeric|gt:0',
            'warehouse_id' => 'nullable|integer|min:1',
        ]);

        return $this->success(
            $this->trace->fefoPlan($d['product_id'], (float) $d['quantity'], $request->user()->tenant_id, $d['warehouse_id'] ?? null),
            'FEFO plan'
        );
    }

    public function expiry(Request $request)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->trace->expiryOverview($request->user()->tenant_id, $request->integer('days') ?: null),
            'Expiry overview'
        );
    }

    /* ── Serials ────────────────────────────────────────────────── */

    public function serials(Request $request)
    {
        $this->denyExternal($request);
        $f = $request->only(['product_id', 'warehouse_id', 'status', 'batch_id', 'search']);

        return $this->success($this->trace->serials($request->user()->tenant_id, $f), 'Serial numbers retrieved');
    }

    public function storeSerials(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'product_id'      => 'required|integer|min:1',
            'batch_id'        => 'nullable|integer|min:1',
            'warehouse_id'    => 'nullable|integer|min:1',
            // One number, or many pasted at once.
            'serial_no'       => 'required|array|min:1|max:500',
            'serial_no.*'     => 'string|max:80',
            'status'          => ['nullable', Rule::in(Serial::STATUSES)],
            'warranty_until'  => 'nullable|date',
            'customer_ref'    => 'nullable|string|max:180',
            'note'            => 'nullable|string|max:1000',
        ]);

        return $this->success(
            $this->trace->createSerials($data, $request->user()->tenant_id, $request->user()->id),
            'Serial numbers added', 201
        );
    }

    public function updateSerial(Request $request, int $serial)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'status'         => ['nullable', Rule::in(Serial::STATUSES)],
            'warehouse_id'   => 'nullable|integer|min:1',
            'batch_id'       => 'nullable|integer|min:1',
            'warranty_until' => 'nullable|date',
            'customer_ref'   => 'nullable|string|max:180',
            'note'           => 'nullable|string|max:1000',
        ]);

        return $this->success(
            $this->trace->updateSerial($serial, array_filter($data, fn ($v) => $v !== null), $request->user()->tenant_id),
            'Serial updated'
        );
    }

    public function destroySerial(Request $request, int $serial)
    {
        $this->requireAdmin($request, 'delete a serial number');
        $this->trace->deleteSerial($serial, $request->user()->tenant_id);

        return $this->success(null, 'Serial deleted');
    }

    /** A serialised unit's service/repair history. */
    public function serialEvents(Request $request, int $serial)
    {
        $this->denyExternal($request);

        return $this->success(
            $this->trace->serialEvents($serial, $request->user()->tenant_id),
            'Serial history retrieved'
        );
    }

    /** Log a service/repair/replacement/etc. event against a unit. */
    public function addSerialEvent(Request $request, int $serial)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'event_type'   => ['required', Rule::in(SerialEvent::TYPES)],
            'description'  => 'required|string|max:1000',
            'status_to'    => ['nullable', Rule::in(Serial::STATUSES)],
            'cost'         => 'nullable|numeric|min:0',
            'vendor'       => 'nullable|string|max:180',
            'reference'    => 'nullable|string|max:120',
            'performed_at' => 'nullable|date',
        ]);

        return $this->success(
            $this->trace->addSerialEvent($serial, $data, $request->user()->tenant_id, $request->user()->id),
            'Event logged', 201
        );
    }

    /* ── Reservations ───────────────────────────────────────────── */

    public function reservations(Request $request)
    {
        $this->denyExternal($request);
        $f = $request->only(['product_id', 'warehouse_id', 'status', 'reserved_for']);

        // A plain staff member's list is their own commitments; admins see all.
        if (! $this->isAdmin($request) && $request->boolean('mine', true) === true && $request->has('mine')) {
            $f['created_by'] = $request->user()->id;
        }

        $rows = $this->trace->reservations($request->user()->tenant_id, $f);

        // Mirror the release barrier so the UI can hide what it can't do.
        $isAdmin = $this->isAdmin($request);
        $uid = $request->user()->id;
        $rows->each(fn ($r) => $r->setAttribute('can_release', $isAdmin || (int) $r->created_by === (int) $uid));

        return $this->success($rows, 'Reservations retrieved');
    }

    public function reserve(Request $request)
    {
        $this->denyExternal($request);

        $data = $request->validate([
            'product_id'      => 'required|integer|min:1',
            'warehouse_id'    => 'required|integer|min:1',
            'quantity'        => 'required|numeric|gt:0',
            'reserved_for'    => ['nullable', Rule::in(Reservation::FOR)],
            'reference_id'    => 'nullable|integer|min:1',
            'reference_label' => 'nullable|string|max:180',
            'priority'        => 'nullable|integer|min:1|max:9',
            'expires_at'      => 'nullable|date|after_or_equal:today',
            'note'            => 'nullable|string|max:1000',
        ]);

        return $this->success(
            $this->trace->reserve($data, $request->user()->tenant_id, $request->user()->id),
            'Stock reserved', 201
        );
    }

    /** Release or fulfil — admin, or whoever placed the reservation. */
    public function closeReservation(Request $request, int $reservation)
    {
        $this->denyExternal($request);

        $owner = Reservation::forTenant($request->user()->tenant_id)->whereKey($reservation)->value('created_by');
        abort_unless(
            $this->isAdmin($request) || (int) $owner === (int) $request->user()->id,
            403,
            'Only an admin or the person who reserved it can release this.'
        );

        $data = $request->validate(['as' => ['nullable', Rule::in(['released', 'fulfilled'])]]);

        return $this->success(
            $this->trace->closeReservation($reservation, $request->user()->tenant_id, $request->user()->id, $data['as'] ?? 'released'),
            'Reservation closed'
        );
    }
}

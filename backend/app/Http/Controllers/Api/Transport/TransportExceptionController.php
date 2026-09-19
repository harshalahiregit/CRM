<?php

namespace App\Http\Controllers\Api\Transport;

use App\Exceptions\BusinessException;
use App\Exceptions\ResourceNotFoundException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Traits\ApiResponse;
use App\Http\Requests\Transport\RaiseExceptionRequest;
use App\Http\Requests\Transport\ResolveExceptionRequest;
use App\Models\Transport\TripException;
use App\Services\Transport\TransportTripService;
use App\Services\Transport\TripExceptionService;
use App\Support\Transport\ExceptionCategory;
use App\Support\Transport\ExceptionScope;
use App\Support\Transport\ExceptionSeverity;
use App\Support\Transport\ExceptionStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The exception register — API-007, SNG-TRN-013.
 *
 *   GET   /transport/trips/{trip}/exceptions        what is wrong with this trip
 *   POST  /transport/trips/{trip}/exceptions        API-007, verbatim path
 *   PATCH /transport/exceptions/{id}/acknowledge    STT-015
 *   PATCH /transport/exceptions/{id}/resolve        STT-016
 *
 * API-007 gives the path and the permission key; Step 11 gives no row for the
 * two transitions, so those paths follow this module's convention (a state
 * change is a PATCH on a named verb) and are recorded as derived.
 *
 * ── THE VOCABULARY TRAVELS WITH THE PAYLOAD ─────────────────────────────
 * Every response carries the eight categories, the four severities and which
 * statuses are reachable. A form that has to hardcode OPS §88's list is a form
 * that will disagree with the server the first time the list changes — and
 * D-37 records that this list exists in exactly one document, so a client
 * inventing its own copy has nothing to check it against.
 *
 * Thin, like every other transport controller. Tenancy resolves in
 * TransportTripService::find(), which raises ResourceNotFoundException — 404,
 * never 403.
 */
class TransportExceptionController extends Controller
{
    use ApiResponse;

    public function __construct(
        private TripExceptionService $exceptions,
        private TransportTripService $trips,
    ) {
    }

    public function index(Request $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);   // 404, never 403

        return $this->success([
            'exceptions' => $this->exceptions->forTrip($record->id, $tenantId)
                ->map(fn (TripException $e) => $this->present($e)),
            'summary'    => $this->exceptions->summaryForTrip($record->id, $tenantId),
            'vocabulary' => $this->vocabulary(),
        ], 'Exceptions retrieved');
    }

    /** API-007 — POST /api/v1/transport/trips/{trip}/exceptions. */
    public function store(RaiseExceptionRequest $request, int $trip): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $record   = $this->trips->find($trip, $tenantId);

        $e = $this->exceptions->raise(
            [...$request->validated(), 'trip_id' => $record->id],
            $tenantId,
            $request->user(),
        );

        return $this->success($this->present($e), 'Exception raised', 201);
    }

    /** STT-015 — "Assign owner". The SLA timer starts here. */
    public function acknowledge(Request $request, int $exception): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $e        = $this->find($exception, $tenantId);

        try {
            $moved = $this->exceptions->acknowledge(
                $e, $request->integer('owner_id') ?: null, $tenantId, $request->user(),
            );
        } catch (ResourceNotFoundException $ex) {
            throw $ex;
        } catch (BusinessException $ex) {
            return $this->refusal($ex, $e);
        }

        return $this->success($this->present($moved), 'Exception acknowledged');
    }

    /** STT-016 — "Resolution evidence". BR-P0-011. */
    public function resolve(ResolveExceptionRequest $request, int $exception): JsonResponse
    {
        $tenantId = $request->user()->tenant_id;
        $e        = $this->find($exception, $tenantId);

        try {
            $moved = $this->exceptions->resolve(
                $e, $request->validated()['resolution_note'], $tenantId, $request->user(),
            );
        } catch (ResourceNotFoundException $ex) {
            throw $ex;
        } catch (BusinessException $ex) {
            return $this->refusal($ex, $e);
        }

        return $this->success($this->present($moved), 'Exception resolved');
    }

    /* ── helpers ────────────────────────────────────────────────────── */

    private function find(int $id, int $tenantId): TripException
    {
        $e = TripException::forTenant($tenantId)->find($id);

        if (! $e) {
            // 404, never 403 — a 403 confirms the row exists.
            throw new ResourceNotFoundException('Exception');
        }

        return $e;
    }

    private function present(TripException $e): array
    {
        return [
            ...$e->toArray(),
            'status_label'   => $e->statusLabel(),
            'severity_label' => ExceptionSeverity::label($e->severity),
            'category_label' => ExceptionCategory::label($e->category),
            'is_open'        => $e->isOpen(),
            // Wall clock only (Q5). The label says so, so nobody reads it as a
            // working-hours calculation.
            'sla_state'      => $e->slaState(),
            'minutes_remaining' => $e->minutesRemaining(),
            'sla_basis'      => 'Elapsed wall-clock. Working hours, weekends and holidays are not modelled (D-34).',
            'can_acknowledge' => $e->canTransitionTo(ExceptionStatus::ACKNOWLEDGED),
            'can_resolve'     => $e->canTransitionTo(ExceptionStatus::RESOLVED),
        ];
    }

    private function vocabulary(): array
    {
        return [
            'categories' => array_map(
                fn (string $c) => ['value' => $c, 'label' => ExceptionCategory::label($c), 'gloss' => ExceptionScope::OPS_88_CATEGORIES[$c] ?? null],
                ExceptionCategory::ALL,
            ),
            'severities' => array_map(
                fn (string $s) => ['value' => $s, 'label' => ExceptionSeverity::label($s)],
                ExceptionSeverity::ALL,
            ),
            // Declared and reachable are different lists, and the screen should
            // know which is which rather than offering a status nothing reaches.
            'statuses'   => array_map(
                fn (string $s) => [
                    'value' => $s,
                    'label' => ExceptionStatus::label($s),
                    'reachable' => in_array($s, ExceptionStatus::REACHABLE, true),
                ],
                ExceptionStatus::ALL,
            ),
            'waiver' => ExceptionScope::WAIVER_MESSAGE,
        ];
    }

    private function refusal(BusinessException $e, TripException $record): JsonResponse
    {
        return response()->json([
            'status'  => 'error',
            'message' => $e->getMessage(),
            'data'    => $this->present($record->fresh()),
        ], $e->getStatusCode());
    }
}

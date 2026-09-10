<?php

namespace Sire\Http\Controllers;

use Sire\Http\Controllers\Concerns\AssertsSireTenantOwnership;
use Sire\Http\Controllers\Concerns\ResolvesSireUser;
use Sire\Http\Controllers\Concerns\SireApiResponse;
use Sire\Http\Requests\StoreCapaRequest;
use Sire\Models\CorrectiveAction;
use Sire\Models\RecurrenceGroup;
use Sire\Models\Report;
use Sire\Services\SireCapaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SireCapaController extends SireController
{
    use SireApiResponse;
    use ResolvesSireUser;
    use AssertsSireTenantOwnership;

    public function __construct(private readonly SireCapaService $capa)
    {
    }

    /** The CAPA queue: mine, overdue, or everything open. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->sireUser();

        return $this->success(
            CorrectiveAction::query()
                ->forTenant($user->tenantId)
                ->with(['owner:id,name', 'report:id,report_number,title', 'recurrenceGroup:id,reference,title'])
                ->when($request->boolean('mine'), fn ($q) => $q->where('owner_id', $user->id))
                ->when($request->boolean('overdue'), fn ($q) => $q->overdue())
                ->when($request->boolean('open_only', true), fn ($q) => $q->open())
                ->orderByRaw('due_at IS NULL, due_at ASC')
                ->paginate((int) $request->integer('per_page', 25)),
        );
    }

    public function storeForReport(StoreCapaRequest $request, Report $report): JsonResponse
    {
        $this->assertTenantOwnership($report);

        return $this->success($this->capa->create($report, $request->validated(), $this->sireUser()), 201);
    }

    /** CAPA against the PATTERN — survives the closure of any one occurrence. */
    public function storeForGroup(StoreCapaRequest $request, RecurrenceGroup $group): JsonResponse
    {
        $this->assertTenantOwnership($group);

        return $this->success($this->capa->create($group, $request->validated(), $this->sireUser()), 201);
    }

    public function start(Request $request, CorrectiveAction $action): JsonResponse
    {
        $this->assertTenantOwnership($action);

        return $this->success($this->capa->start($action, $this->sireUser()));
    }

    public function complete(Request $request, CorrectiveAction $action): JsonResponse
    {
        $this->assertTenantOwnership($action);
        $note = (string) $request->validate(['completion_note' => ['required', 'string', 'max:20000']])['completion_note'];

        return $this->success($this->capa->complete($action, $note, $this->sireUser()));
    }

    public function verify(Request $request, CorrectiveAction $action): JsonResponse
    {
        $this->assertTenantOwnership($action);

        $data = $request->validate([
            'effectiveness'     => ['required', Rule::in(CorrectiveAction::EFFECTIVENESS)],
            'verification_note' => ['nullable', 'string', 'max:20000'],
        ]);

        return $this->success($this->capa->verify($action, $data, $this->sireUser()));
    }

    public function cancel(Request $request, CorrectiveAction $action): JsonResponse
    {
        $this->assertTenantOwnership($action);
        $reason = (string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason'];

        return $this->success($this->capa->cancel($action, $reason, $this->sireUser()));
    }
}

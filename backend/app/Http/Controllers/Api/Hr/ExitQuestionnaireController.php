<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Services\Hr\ExitQuestionnaireService;
use Illuminate\Http\Request;

/**
 * #44 — the exit questionnaire template master.
 *
 * Authoring templates is HR-only. `resolve` is not: the exit interview form has
 * to know which questions to render, and the leaver filling it in is not an HR
 * user.
 */
class ExitQuestionnaireController extends Controller
{
    public function __construct(private ExitQuestionnaireService $service)
    {
    }

    public function index(Request $request)
    {
        return response()->json(['data' => $this->service->list(
            $this->tenant($request), $request->only(['is_active', 'exit_type_id'])
        )]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    /** The questionnaire that applies to an exit type — what the form renders. */
    public function resolve(Request $request)
    {
        return response()->json($this->service->resolveFor(
            $request->integer('exit_type_id') ?: null, $this->tenant($request)
        ));
    }

    public function store(Request $request)
    {
        $this->can($request);

        return response()->json($this->service->save($request->all(), $this->tenant($request), $request->user()), 201);
    }

    public function update(Request $request, int $id)
    {
        $this->can($request);

        return response()->json(
            $this->service->save($request->all() + ['id' => $id], $this->tenant($request), $request->user())
        );
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $this->service->destroy($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Exit questionnaire removed']);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage exit questionnaires');
    }
}

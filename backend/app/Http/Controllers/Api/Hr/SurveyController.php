<?php

namespace App\Http\Controllers\Api\Hr;

use App\Http\Controllers\Controller;
use App\Models\Hr\HrSurvey;
use App\Models\Hr\HrSurveyQuestion;
use App\Services\Hr\SurveyReportService;
use App\Services\Hr\SurveyService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * #26 — Employee Survey. Thin: validate, delegate, return JSON.
 *
 * Authoring, analytics and the response list require HR-queue management —
 * reading responses is reading what employees said. ANSWERING does not: an
 * employee must be able to take a survey addressed to them.
 */
class SurveyController extends Controller
{
    public function __construct(
        private SurveyService $service,
        private SurveyReportService $reports,
    ) {
    }

    public function meta()
    {
        return response()->json([
            'statuses'       => HrSurvey::STATUSES,
            'audiences'      => HrSurvey::AUDIENCES,
            'question_types' => HrSurveyQuestion::TYPES,
        ]);
    }

    /* ── Categories ───────────────────────────────────────────────────── */

    public function categories(Request $request)
    {
        return response()->json(['data' => $this->service->categories($this->tenant($request), $request->only(['is_active']))]);
    }

    public function saveCategory(Request $request, ?int $id = null)
    {
        $this->can($request);
        $data = $request->validate([
            'name'        => ($id ? 'sometimes|' : '').'required|string|max:120',
            'code'        => 'nullable|string|max:40',
            'colour'      => 'nullable|string|max:20',
            'description' => 'nullable|string|max:1000',
            'is_active'   => 'nullable|boolean',
        ]);

        return response()->json($this->service->saveCategory($id, $data, $this->tenant($request), $request->user()), $id ? 200 : 201);
    }

    public function destroyCategory(Request $request, int $id)
    {
        $this->can($request);
        $this->service->deleteCategory($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Surveys ──────────────────────────────────────────────────────── */

    public function index(Request $request)
    {
        return response()->json([
            'data' => $this->service->list($this->tenant($request),
                $request->only(['status', 'category_id', 'audience', 'search'])),
        ]);
    }

    public function show(Request $request, int $id)
    {
        return response()->json($this->service->show($id, $this->tenant($request)));
    }

    public function save(Request $request, ?int $id = null)
    {
        $this->can($request);
        $data = $request->validate([
            'title'          => ($id ? 'sometimes|' : '').'required|string|max:200',
            'category_id'    => 'nullable|integer',
            'description'    => 'nullable|string|max:2000',
            'instructions'   => 'nullable|string|max:2000',
            'is_anonymous'   => 'nullable|boolean',
            'allow_multiple_responses' => 'nullable|boolean',
            'starts_at'      => 'nullable|date',
            'ends_at'        => 'nullable|date|after_or_equal:starts_at',
            'audience'       => ['nullable', Rule::in(HrSurvey::AUDIENCES)],
            'department_id'  => 'nullable|integer|required_if:audience,Department',
            'designation_id' => 'nullable|integer|required_if:audience,Designation',
            'questions'                 => 'nullable|array',
            'questions.*.question_text' => 'required|string|max:1000',
            'questions.*.question_type' => ['required', Rule::in(HrSurveyQuestion::TYPES)],
            'questions.*.options'       => 'nullable|array',
            'questions.*.options.*'     => 'string|max:200',
            'questions.*.rating_max'    => 'nullable|integer|min:2|max:10',
            'questions.*.is_required'   => 'nullable|boolean',
        ]);

        return response()->json($this->service->save($id, $data, $this->tenant($request), $request->user()), $id ? 200 : 201);
    }

    public function publish(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->publish($id, $this->tenant($request), $request->user()));
    }

    public function close(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->service->close($id, $this->tenant($request), $request->user()));
    }

    public function destroy(Request $request, int $id)
    {
        $this->can($request);
        $this->service->delete($id, $this->tenant($request), $request->user());

        return response()->json(['message' => 'Deleted']);
    }

    /* ── Responding ───────────────────────────────────────────────────── */

    /** Open surveys addressed to this employee. No HR permission — it is theirs. */
    public function availableFor(Request $request, int $employeeId)
    {
        return response()->json(['data' => $this->service->availableFor($employeeId, $this->tenant($request))]);
    }

    public function respond(Request $request, int $id)
    {
        $data = $request->validate([
            'employee_id'                    => 'required|integer',
            'answers'                        => 'present|array',
            'answers.*.question_id'          => 'required|integer',
            'answers.*.answer_text'          => 'nullable|string|max:5000',
            'answers.*.answer_number'        => 'nullable|numeric',
            'answers.*.answer_boolean'       => 'nullable|boolean',
            'answers.*.selected_options'     => 'nullable|array',
            'answers.*.selected_options.*'   => 'string|max:200',
        ]);

        return response()->json($this->service->submitResponse(
            $id, (int) $data['employee_id'], $data['answers'], $this->tenant($request)
        ), 201);
    }

    /* ── Dashboard, analytics, reports, export ────────────────────────── */

    public function dashboard(Request $request)
    {
        $this->can($request);

        return response()->json($this->reports->dashboard($this->tenant($request)));
    }

    public function analytics(Request $request, int $id)
    {
        $this->can($request);

        return response()->json($this->reports->analytics($id, $this->tenant($request)));
    }

    public function responses(Request $request, int $id)
    {
        $this->can($request);

        return response()->json([
            'data' => $this->reports->responses($id, $this->tenant($request), $request->only(['department'])),
        ]);
    }

    public function export(Request $request, int $id): StreamedResponse
    {
        $this->can($request);
        $export = $this->reports->exportRows($id, $this->tenant($request));

        return response()->streamDownload(function () use ($export) {
            $out = fopen('php://output', 'w');
            foreach ($export['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $export['filename'], ['Content-Type' => 'text/csv']);
    }

    private function tenant(Request $request): int
    {
        return (int) $request->user()->tenant_id;
    }

    private function can(Request $request): void
    {
        abort_unless($request->user()->canManageHrQueue(), 403, 'You are not authorised to manage surveys');
    }
}

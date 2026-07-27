<?php

namespace App\Http\Controllers\Api\Compliance;

use App\Http\Controllers\Controller;
use App\Http\Requests\Compliance\StoreComplianceTemplateRequest;
use App\Http\Requests\Compliance\UpdateComplianceTemplateRequest;
use App\Models\Compliance\ComplianceTemplate;
use App\Services\Compliance\ComplianceTemplateService;
use App\Support\Compliance\QuestionType;
use App\Support\Compliance\RiskBand;
use Illuminate\Http\Request;

class ComplianceTemplateController extends Controller
{
    public function __construct(private ComplianceTemplateService $templateService)
    {
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->templateService->list(
                $request->user()->tenant_id,
                $request->only(['status', 'category', 'search'])
            )
        );
    }

    /** What the template builder needs to render its type/threshold pickers. */
    public function meta()
    {
        return response()->json([
            'question_types'     => collect(QuestionType::ALL)
                                      ->map(fn ($t) => ['value' => $t, 'label' => QuestionType::label($t), 'scorable' => QuestionType::isScorable($t)])
                                      ->all(),
            'bands'              => collect(RiskBand::ALL)->map(fn ($b) => ['value' => $b, 'label' => RiskBand::label($b)])->all(),
            'default_thresholds' => RiskBand::DEFAULT_THRESHOLDS,
        ]);
    }

    public function store(StoreComplianceTemplateRequest $request)
    {
        return response()->json(
            $this->templateService->create($request->validated(), $request->user()),
            201
        );
    }

    public function show(Request $request, ComplianceTemplate $template)
    {
        $this->assertTenant($request, $template);
        $template->load(['creator:id,name', 'auditLogs'])->loadCount('checklists');

        return response()->json($template);
    }

    public function update(UpdateComplianceTemplateRequest $request, ComplianceTemplate $template)
    {
        $this->assertTenant($request, $template);

        return response()->json(
            $this->templateService->update($template, $request->validated(), $request->user())
        );
    }

    public function activate(Request $request, ComplianceTemplate $template)
    {
        $this->assertTenant($request, $template);

        return response()->json($this->templateService->activate($template, $request->user()));
    }

    public function archive(Request $request, ComplianceTemplate $template)
    {
        $this->assertTenant($request, $template);

        return response()->json($this->templateService->archive($template, $request->user()));
    }

    public function clone(Request $request, ComplianceTemplate $template)
    {
        $this->assertTenant($request, $template);

        return response()->json($this->templateService->clone($template, $request->user()), 201);
    }

    public function destroy(Request $request, ComplianceTemplate $template)
    {
        $this->assertTenant($request, $template);
        $this->templateService->delete($template, $request->user());

        return response()->json(['message' => 'Template deleted']);
    }

    /** Route-model binding does not know about tenants — reads must be guarded. */
    private function assertTenant(Request $request, ComplianceTemplate $template): void
    {
        abort_unless(
            (int) $template->tenant_id === (int) $request->user()->tenant_id,
            404,
            'Template not found'
        );
    }
}

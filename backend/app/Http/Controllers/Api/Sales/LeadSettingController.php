<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\StoreLeadGoalRequest;
use App\Http\Requests\Sales\StoreLeadQuestionnaireRequest;
use App\Http\Requests\Sales\StoreLeadSourceRequest;
use App\Http\Requests\Sales\StoreLeadStatusSettingRequest;
use App\Http\Requests\Sales\UpdateLeadGoalRequest;
use App\Http\Requests\Sales\UpdateLeadQuestionnaireRequest;
use App\Http\Requests\Sales\UpdateLeadSourceRequest;
use App\Http\Requests\Sales\UpdateLeadStatusSettingRequest;
use App\Models\Sales\LeadGoal;
use App\Models\Sales\LeadQuestionnaire;
use App\Models\Sales\LeadSource;
use App\Models\Sales\LeadStatus;
use App\Services\Sales\LeadSettingService;
use Illuminate\Http\Request;

class LeadSettingController extends Controller
{
    public function __construct(private LeadSettingService $leadSettingService)
    {
    }

    /* ── Statuses ─────────────────────────────── */

    public function statuses(Request $request)
    {
        return response()->json($this->leadSettingService->statuses($request->user()->tenant_id));
    }

    public function createStatus(StoreLeadStatusSettingRequest $request)
    {
        $status = $this->leadSettingService->createStatus($request->validated(), $request->user()->tenant_id);
        return response()->json($status, 201);
    }

    public function updateStatus(UpdateLeadStatusSettingRequest $request, LeadStatus $status)
    {
        $updated = $this->leadSettingService->updateStatus($status, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    public function deleteStatus(LeadStatus $status, Request $request)
    {
        $this->leadSettingService->deleteStatus($status, $request->user()->tenant_id);
        return response()->json(['message' => 'Status deleted']);
    }

    /* ── Sources ──────────────────────────────── */

    public function sources(Request $request)
    {
        return response()->json($this->leadSettingService->sources($request->user()->tenant_id));
    }

    public function createSource(StoreLeadSourceRequest $request)
    {
        $source = $this->leadSettingService->createSource($request->validated(), $request->user()->tenant_id);
        return response()->json($source, 201);
    }

    public function updateSource(UpdateLeadSourceRequest $request, LeadSource $source)
    {
        $updated = $this->leadSettingService->updateSource($source, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    public function deleteSource(LeadSource $source, Request $request)
    {
        $this->leadSettingService->deleteSource($source, $request->user()->tenant_id);
        return response()->json(['message' => 'Source deleted']);
    }

    /* ── Goals ────────────────────────────────── */

    public function goals(Request $request)
    {
        $goals = $this->leadSettingService->goals(
            $request->user()->tenant_id,
            $request->filled('user_id') ? (int) $request->user_id : null,
            $request->boolean('active_only')
        );

        return response()->json($goals);
    }

    public function storeGoal(StoreLeadGoalRequest $request)
    {
        $goal = $this->leadSettingService->storeGoal($request->validated(), $request->user()->tenant_id);
        return response()->json($goal, 201);
    }

    public function updateGoal(UpdateLeadGoalRequest $request, LeadGoal $goal)
    {
        $updated = $this->leadSettingService->updateGoal($goal, $request->validated(), $request->user()->tenant_id);
        return response()->json($updated);
    }

    public function deleteGoal(LeadGoal $goal, Request $request)
    {
        $this->leadSettingService->deleteGoal($goal, $request->user()->tenant_id);
        return response()->json(['message' => 'Goal deleted']);
    }

    /* ── Questionnaires ───────────────────────── */

    public function questionnaires(Request $request)
    {
        return response()->json($this->leadSettingService->questionnaires($request->user()->tenant_id));
    }

    public function storeQuestionnaire(StoreLeadQuestionnaireRequest $request)
    {
        $questionnaire = $this->leadSettingService->storeQuestionnaire($request->validated(), $request->user()->tenant_id);
        return response()->json($questionnaire, 201);
    }

    public function updateQuestionnaire(UpdateLeadQuestionnaireRequest $request, LeadQuestionnaire $questionnaire)
    {
        $updated = $this->leadSettingService->updateQuestionnaire(
            $questionnaire, $request->validated(), $request->has('fields'), $request->user()->tenant_id
        );

        return response()->json($updated);
    }

    public function deleteQuestionnaire(LeadQuestionnaire $questionnaire, Request $request)
    {
        $this->leadSettingService->deleteQuestionnaire($questionnaire, $request->user()->tenant_id);
        return response()->json(['message' => 'Questionnaire deleted']);
    }
}

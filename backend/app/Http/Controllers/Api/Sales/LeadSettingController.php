<?php

namespace App\Http\Controllers\Api\Sales;

use App\Http\Controllers\Controller;
use App\Models\LeadStatus;
use App\Models\LeadSource;
use App\Models\LeadGoal;
use App\Models\LeadQuestionnaire;
use App\Models\LeadQuestionnaireField;
use Illuminate\Http\Request;

class LeadSettingController extends Controller
{
    private function tid(): int
    {
        return auth()->user()->tenant_id;
    }

    /* ══════════════════════════════════════════════════════════════
     *  LEAD STATUSES
     * ══════════════════════════════════════════════════════════════ */

    public function statuses()
    {
        return response()->json(
            LeadStatus::forTenant($this->tid())->ordered()->get()
        );
    }

    public function createStatus(Request $request)
    {
        $validated = $request->validate([
            'name'           => 'required|string|max:100',
            'color'          => 'nullable|string|max:9',
            'sort_order'     => 'nullable|integer',
            'is_default'     => 'nullable|boolean',
            'is_won_status'  => 'nullable|boolean',
        ]);

        // If setting as default, unset other defaults
        if (!empty($validated['is_default'])) {
            LeadStatus::forTenant($this->tid())->update(['is_default' => false]);
        }
        if (!empty($validated['is_won_status'])) {
            LeadStatus::forTenant($this->tid())->update(['is_won_status' => false]);
        }

        $status = LeadStatus::create([
            ...$validated,
            'tenant_id' => $this->tid(),
        ]);

        return response()->json($status, 201);
    }

    public function updateStatus(Request $request, LeadStatus $status)
    {
        if ($status->tenant_id !== $this->tid()) abort(403);

        $validated = $request->validate([
            'name'          => 'sometimes|string|max:100',
            'color'         => 'nullable|string|max:9',
            'sort_order'    => 'nullable|integer',
            'is_default'    => 'nullable|boolean',
            'is_won_status' => 'nullable|boolean',
        ]);

        if (!empty($validated['is_default'])) {
            LeadStatus::forTenant($this->tid())->where('id', '!=', $status->id)->update(['is_default' => false]);
        }
        if (!empty($validated['is_won_status'])) {
            LeadStatus::forTenant($this->tid())->where('id', '!=', $status->id)->update(['is_won_status' => false]);
        }

        $status->update($validated);

        return response()->json($status->fresh());
    }

    public function deleteStatus(LeadStatus $status)
    {
        if ($status->tenant_id !== $this->tid()) abort(403);

        if ($status->leads()->exists()) {
            return response()->json(['error' => 'Cannot delete status with existing leads. Reassign leads first.'], 422);
        }

        $status->delete();
        return response()->json(['message' => 'Status deleted']);
    }

    /* ══════════════════════════════════════════════════════════════
     *  LEAD SOURCES
     * ══════════════════════════════════════════════════════════════ */

    public function sources()
    {
        return response()->json(
            LeadSource::forTenant($this->tid())->ordered()->get()
        );
    }

    public function createSource(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:100',
            'sort_order' => 'nullable|integer',
        ]);

        $source = LeadSource::create([
            ...$validated,
            'tenant_id' => $this->tid(),
        ]);

        return response()->json($source, 201);
    }

    public function updateSource(Request $request, LeadSource $source)
    {
        if ($source->tenant_id !== $this->tid()) abort(403);

        $validated = $request->validate([
            'name'       => 'sometimes|string|max:100',
            'sort_order' => 'nullable|integer',
        ]);

        $source->update($validated);
        return response()->json($source->fresh());
    }

    public function deleteSource(LeadSource $source)
    {
        if ($source->tenant_id !== $this->tid()) abort(403);

        if ($source->leads()->exists()) {
            return response()->json(['error' => 'Cannot delete source with existing leads. Reassign leads first.'], 422);
        }

        $source->delete();
        return response()->json(['message' => 'Source deleted']);
    }

    /* ══════════════════════════════════════════════════════════════
     *  LEAD GOALS / TARGETS
     * ══════════════════════════════════════════════════════════════ */

    public function goals(Request $request)
    {
        $query = LeadGoal::forTenant($this->tid())->with('user:id,name');

        if ($request->filled('user_id')) {
            $query->forUser($request->user_id);
        }
        if ($request->boolean('active_only')) {
            $query->active();
        }

        return response()->json($query->latest()->get());
    }

    public function storeGoal(Request $request)
    {
        $validated = $request->validate([
            'user_id'         => 'nullable|exists:users,id',
            'type'            => 'required|in:monthly,quarterly,yearly',
            'period_start'    => 'required|date',
            'period_end'      => 'required|date|after:period_start',
            'target_count'    => 'nullable|integer|min:0',
            'target_value'    => 'nullable|numeric|min:0',
            'incentive_type'  => 'nullable|in:none,fixed,percentage',
            'incentive_value' => 'nullable|numeric|min:0',
        ]);

        $goal = LeadGoal::create([
            ...$validated,
            'tenant_id' => $this->tid(),
        ]);

        return response()->json($goal->load('user:id,name'), 201);
    }

    public function updateGoal(Request $request, LeadGoal $goal)
    {
        if ($goal->tenant_id !== $this->tid()) abort(403);

        $validated = $request->validate([
            'target_count'    => 'nullable|integer|min:0',
            'target_value'    => 'nullable|numeric|min:0',
            'incentive_type'  => 'nullable|in:none,fixed,percentage',
            'incentive_value' => 'nullable|numeric|min:0',
        ]);

        $goal->update($validated);
        return response()->json($goal->fresh()->load('user:id,name'));
    }

    public function deleteGoal(LeadGoal $goal)
    {
        if ($goal->tenant_id !== $this->tid()) abort(403);
        $goal->delete();
        return response()->json(['message' => 'Goal deleted']);
    }

    /* ══════════════════════════════════════════════════════════════
     *  QUESTIONNAIRES
     * ══════════════════════════════════════════════════════════════ */

    public function questionnaires()
    {
        return response()->json(
            LeadQuestionnaire::forTenant($this->tid())
                ->with('fields')
                ->latest()
                ->get()
        );
    }

    public function storeQuestionnaire(Request $request)
    {
        $validated = $request->validate([
            'title'       => 'required|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'fields'      => 'required|array|min:1',
            'fields.*.label'       => 'required|string|max:255',
            'fields.*.field_type'  => 'required|in:text,textarea,number,email,phone,date,select,multi_select,checkbox,radio,file',
            'fields.*.options'     => 'nullable|array',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.is_required' => 'nullable|boolean',
        ]);

        $questionnaire = LeadQuestionnaire::create([
            'tenant_id'   => $this->tid(),
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'is_active'   => $validated['is_active'] ?? true,
            'created_by'  => auth()->id(),
        ]);

        foreach ($validated['fields'] as $idx => $field) {
            LeadQuestionnaireField::create([
                'questionnaire_id' => $questionnaire->id,
                'label'            => $field['label'],
                'field_type'       => $field['field_type'],
                'options'          => $field['options'] ?? null,
                'placeholder'      => $field['placeholder'] ?? null,
                'is_required'      => $field['is_required'] ?? false,
                'sort_order'       => $idx,
            ]);
        }

        return response()->json($questionnaire->load('fields'), 201);
    }

    public function updateQuestionnaire(Request $request, LeadQuestionnaire $questionnaire)
    {
        if ($questionnaire->tenant_id !== $this->tid()) abort(403);

        $validated = $request->validate([
            'title'       => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'is_active'   => 'nullable|boolean',
            'fields'      => 'nullable|array',
            'fields.*.label'       => 'required_with:fields|string|max:255',
            'fields.*.field_type'  => 'required_with:fields|in:text,textarea,number,email,phone,date,select,multi_select,checkbox,radio,file',
            'fields.*.options'     => 'nullable|array',
            'fields.*.placeholder' => 'nullable|string|max:255',
            'fields.*.is_required' => 'nullable|boolean',
        ]);

        $questionnaire->update(collect($validated)->only(['title', 'description', 'is_active'])->toArray());

        // Rebuild fields if provided
        if ($request->has('fields')) {
            $questionnaire->fields()->delete();
            foreach ($validated['fields'] as $idx => $field) {
                LeadQuestionnaireField::create([
                    'questionnaire_id' => $questionnaire->id,
                    'label'            => $field['label'],
                    'field_type'       => $field['field_type'],
                    'options'          => $field['options'] ?? null,
                    'placeholder'      => $field['placeholder'] ?? null,
                    'is_required'      => $field['is_required'] ?? false,
                    'sort_order'       => $idx,
                ]);
            }
        }

        return response()->json($questionnaire->fresh()->load('fields'));
    }

    public function deleteQuestionnaire(LeadQuestionnaire $questionnaire)
    {
        if ($questionnaire->tenant_id !== $this->tid()) abort(403);

        if ($questionnaire->responses()->exists()) {
            return response()->json(['error' => 'Cannot delete questionnaire with existing responses'], 422);
        }

        $questionnaire->fields()->delete();
        $questionnaire->delete();
        return response()->json(['message' => 'Questionnaire deleted']);
    }
}

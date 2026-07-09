<?php

namespace App\Services\Sales;

use App\Exceptions\BusinessException;
use App\Exceptions\UnauthorizedTenantException;
use App\Models\LeadGoal;
use App\Models\LeadQuestionnaire;
use App\Models\LeadQuestionnaireField;
use App\Models\LeadSource;
use App\Models\LeadStatus;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class LeadSettingService
{
    /* ── Statuses ─────────────────────────────── */

    public function statuses(int $tenantId)
    {
        return LeadStatus::forTenant($tenantId)->ordered()->get();
    }

    public function createStatus(array $data, int $tenantId): LeadStatus
    {
        if (!empty($data['is_default'])) {
            LeadStatus::forTenant($tenantId)->update(['is_default' => false]);
        }
        if (!empty($data['is_won_status'])) {
            LeadStatus::forTenant($tenantId)->update(['is_won_status' => false]);
        }

        $status = LeadStatus::create([...$data, 'tenant_id' => $tenantId]);
        Log::channel('sales')->info('Lead status created', ['status_id' => $status->id, 'tenant_id' => $tenantId]);

        return $status;
    }

    public function updateStatus(LeadStatus $status, array $data, int $tenantId): LeadStatus
    {
        $this->assertTenant($status->tenant_id, $tenantId);

        if (!empty($data['is_default'])) {
            LeadStatus::forTenant($tenantId)->where('id', '!=', $status->id)->update(['is_default' => false]);
        }
        if (!empty($data['is_won_status'])) {
            LeadStatus::forTenant($tenantId)->where('id', '!=', $status->id)->update(['is_won_status' => false]);
        }

        $status->update($data);
        Log::channel('sales')->info('Lead status updated', ['status_id' => $status->id, 'tenant_id' => $tenantId]);

        return $status->fresh();
    }

    public function deleteStatus(LeadStatus $status, int $tenantId): void
    {
        $this->assertTenant($status->tenant_id, $tenantId);

        if ($status->leads()->exists()) {
            throw new BusinessException('Cannot delete status with existing leads. Reassign leads first.', 422);
        }

        $status->delete();
        Log::channel('sales')->info('Lead status deleted', ['status_id' => $status->id, 'tenant_id' => $tenantId]);
    }

    /* ── Sources ──────────────────────────────── */

    public function sources(int $tenantId)
    {
        return LeadSource::forTenant($tenantId)->ordered()->get();
    }

    public function createSource(array $data, int $tenantId): LeadSource
    {
        $source = LeadSource::create([...$data, 'tenant_id' => $tenantId]);
        Log::channel('sales')->info('Lead source created', ['source_id' => $source->id, 'tenant_id' => $tenantId]);

        return $source;
    }

    public function updateSource(LeadSource $source, array $data, int $tenantId): LeadSource
    {
        $this->assertTenant($source->tenant_id, $tenantId);
        $source->update($data);
        Log::channel('sales')->info('Lead source updated', ['source_id' => $source->id, 'tenant_id' => $tenantId]);

        return $source->fresh();
    }

    public function deleteSource(LeadSource $source, int $tenantId): void
    {
        $this->assertTenant($source->tenant_id, $tenantId);

        if ($source->leads()->exists()) {
            throw new BusinessException('Cannot delete source with existing leads. Reassign leads first.', 422);
        }

        $source->delete();
        Log::channel('sales')->info('Lead source deleted', ['source_id' => $source->id, 'tenant_id' => $tenantId]);
    }

    /* ── Goals ────────────────────────────────── */

    public function goals(int $tenantId, ?int $userId, bool $activeOnly)
    {
        $query = LeadGoal::forTenant($tenantId)->with('user:id,name');

        if ($userId) {
            $query->forUser($userId);
        }
        if ($activeOnly) {
            $query->active();
        }

        return $query->latest()->get();
    }

    public function storeGoal(array $data, int $tenantId): LeadGoal
    {
        $goal = LeadGoal::create([...$data, 'tenant_id' => $tenantId]);
        Log::channel('sales')->info('Lead goal created', ['goal_id' => $goal->id, 'tenant_id' => $tenantId]);

        return $goal->load('user:id,name');
    }

    public function updateGoal(LeadGoal $goal, array $data, int $tenantId): LeadGoal
    {
        $this->assertTenant($goal->tenant_id, $tenantId);
        $goal->update($data);
        Log::channel('sales')->info('Lead goal updated', ['goal_id' => $goal->id, 'tenant_id' => $tenantId]);

        return $goal->fresh()->load('user:id,name');
    }

    public function deleteGoal(LeadGoal $goal, int $tenantId): void
    {
        $this->assertTenant($goal->tenant_id, $tenantId);
        $goal->delete();
        Log::channel('sales')->info('Lead goal deleted', ['goal_id' => $goal->id, 'tenant_id' => $tenantId]);
    }

    /* ── Questionnaires ───────────────────────── */

    public function questionnaires(int $tenantId)
    {
        return LeadQuestionnaire::forTenant($tenantId)->with('fields')->latest()->get();
    }

    public function storeQuestionnaire(array $data, int $tenantId): LeadQuestionnaire
    {
        $questionnaire = LeadQuestionnaire::create([
            'tenant_id'   => $tenantId,
            'title'       => $data['title'],
            'description' => $data['description'] ?? null,
            'is_active'   => $data['is_active'] ?? true,
            'created_by'  => Auth::id(),
        ]);

        $this->syncFields($questionnaire, $data['fields']);

        Log::channel('sales')->info('Lead questionnaire created', ['questionnaire_id' => $questionnaire->id, 'tenant_id' => $tenantId]);

        return $questionnaire->load('fields');
    }

    public function updateQuestionnaire(LeadQuestionnaire $questionnaire, array $data, bool $hasFields, int $tenantId): LeadQuestionnaire
    {
        $this->assertTenant($questionnaire->tenant_id, $tenantId);

        $questionnaire->update(collect($data)->only(['title', 'description', 'is_active'])->toArray());

        if ($hasFields) {
            $questionnaire->fields()->delete();
            $this->syncFields($questionnaire, $data['fields'] ?? []);
        }

        Log::channel('sales')->info('Lead questionnaire updated', ['questionnaire_id' => $questionnaire->id, 'tenant_id' => $tenantId]);

        return $questionnaire->fresh()->load('fields');
    }

    public function deleteQuestionnaire(LeadQuestionnaire $questionnaire, int $tenantId): void
    {
        $this->assertTenant($questionnaire->tenant_id, $tenantId);

        if ($questionnaire->responses()->exists()) {
            throw new BusinessException('Cannot delete questionnaire with existing responses', 422);
        }

        $questionnaire->fields()->delete();
        $questionnaire->delete();

        Log::channel('sales')->info('Lead questionnaire deleted', ['questionnaire_id' => $questionnaire->id, 'tenant_id' => $tenantId]);
    }

    private function syncFields(LeadQuestionnaire $questionnaire, array $fields): void
    {
        foreach ($fields as $idx => $field) {
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

    private function assertTenant(int $ownerTenantId, int $tenantId): void
    {
        if ($ownerTenantId !== $tenantId) {
            Log::channel('sales')->warning('Unauthorized lead-setting access attempt', ['tenant_id' => $tenantId]);
            throw new UnauthorizedTenantException();
        }
    }
}

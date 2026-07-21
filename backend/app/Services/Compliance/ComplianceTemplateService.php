<?php

namespace App\Services\Compliance;

use App\Exceptions\BusinessException;
use App\Models\Compliance\ComplianceTemplate;
use App\Models\User;
use App\Support\Compliance\TemplateStatus as Status;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class ComplianceTemplateService
{
    public function __construct(private ChecklistEvaluator $evaluator)
    {
    }

    public function list(int $tenantId, array $filters): Collection
    {
        $query = ComplianceTemplate::forTenant($tenantId)->withCount('checklists');

        if (! empty($filters['status']) && $filters['status'] !== 'All') {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['category'])) {
            $query->where('category', $filters['category']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $query->where(fn ($q) => $q->where('name', 'like', "%{$s}%")->orWhere('code', 'like', "%{$s}%"));
        }

        return $query->latest()->get();
    }

    public function create(array $data, User $actor): ComplianceTemplate
    {
        $this->guardDefinition($data['definition'] ?? null);

        $template = ComplianceTemplate::create([
            'tenant_id'   => $actor->tenant_id,
            'created_by'  => $actor->id,
            'code'        => $data['code'] ?? null,
            'name'        => $data['name'],
            'category'    => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'definition'  => $data['definition'] ?? null,
            'thresholds'  => $data['thresholds'] ?? null,
            'status'      => Status::DRAFT,
        ]);

        $template->recordAudit('created', $actor, "Template '{$template->name}' created");
        Log::channel('compliance')->info('Compliance template created', [
            'template_id' => $template->id, 'tenant_id' => $actor->tenant_id, 'actor_id' => $actor->id,
        ]);

        return $template->fresh();
    }

    /**
     * Metadata may be corrected at any time; the DEFINITION may not change once
     * the template leaves Draft. Checklists store answers keyed by question, not
     * a copy of the questions — editing a live template would silently rewrite
     * what every historic instance was answered against.
     */
    public function update(ComplianceTemplate $template, array $data, User $actor): ComplianceTemplate
    {
        if (array_key_exists('definition', $data) || array_key_exists('thresholds', $data)) {
            if (! Status::isEditable($template->status)) {
                throw new BusinessException(
                    'The questions and thresholds of a '.Status::label($template->status).
                    ' template cannot be changed. Clone it into a new draft instead.'
                );
            }
            $this->guardDefinition($data['definition'] ?? $template->definition);
        }

        $template->update(array_filter([
            'name'        => $data['name'] ?? null,
            'category'    => $data['category'] ?? null,
            'description' => $data['description'] ?? null,
            'definition'  => $data['definition'] ?? null,
            'thresholds'  => $data['thresholds'] ?? null,
        ], fn ($v) => $v !== null));

        $template->recordAudit('updated', $actor, "Template '{$template->name}' updated");

        return $template->fresh();
    }

    /** Publish — from here the definition is frozen and it can be issued. */
    public function activate(ComplianceTemplate $template, User $actor): ComplianceTemplate
    {
        if ($template->status === Status::ACTIVE) {
            throw new BusinessException('This template is already active.');
        }
        if ($template->status === Status::ARCHIVED) {
            throw new BusinessException('An archived template cannot be reactivated. Clone it into a new draft instead.');
        }

        $this->guardDefinition($template->definition);

        $template->update(['status' => Status::ACTIVE]);
        $template->recordAudit('activated', $actor, "Template '{$template->name}' activated");
        Log::channel('compliance')->info('Compliance template activated', [
            'template_id' => $template->id, 'actor_id' => $actor->id,
        ]);

        return $template->fresh();
    }

    /** Retire — stops new issues, leaves historic checklists readable. */
    public function archive(ComplianceTemplate $template, User $actor): ComplianceTemplate
    {
        $template->update(['status' => Status::ARCHIVED]);
        $template->recordAudit('archived', $actor, "Template '{$template->name}' archived");

        return $template->fresh();
    }

    /**
     * Clone into a fresh draft — the sanctioned way to revise a live template
     * without disturbing what has already been answered.
     */
    public function clone(ComplianceTemplate $template, User $actor): ComplianceTemplate
    {
        $copy = ComplianceTemplate::create([
            'tenant_id'   => $template->tenant_id,
            'created_by'  => $actor->id,
            'name'        => $template->name.' (v'.($template->version + 1).')',
            'category'    => $template->category,
            'description' => $template->description,
            'definition'  => $template->definition,
            'thresholds'  => $template->thresholds,
            'version'     => $template->version + 1,
            'status'      => Status::DRAFT,
        ]);

        $copy->recordAudit('created', $actor, "Cloned from {$template->code}");

        return $copy->fresh();
    }

    public function delete(ComplianceTemplate $template, User $actor): void
    {
        if ($template->checklists()->exists()) {
            throw new BusinessException(
                'This template has checklists issued against it and cannot be deleted. Archive it instead.'
            );
        }

        $template->recordAudit('deleted', $actor, "Template '{$template->name}' deleted");
        $template->delete();
    }

    /** Reject a malformed definition at authoring time, not in the field. */
    private function guardDefinition(?array $definition): void
    {
        $errors = $this->evaluator->validateDefinition($definition);

        if ($errors !== []) {
            throw new BusinessException('This template has problems: '.implode(' ', $errors));
        }
    }
}

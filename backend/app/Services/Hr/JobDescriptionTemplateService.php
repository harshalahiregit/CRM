<?php

namespace App\Services\Hr;

use App\Exceptions\BusinessException;
use App\Models\Hr\HrManpowerRequest;
use App\Models\User;
use App\Support\Hr\JobDescriptionTemplateLibrary;
use Illuminate\Support\Facades\Log;

/**
 * "Generate from Template" — deterministic, role-based JD generation. Sits in the
 * service layer alongside JobDescriptionAIService, reuses the same audit trail,
 * and produces the same plain-text format the AI generator does, so the Convert
 * to JD flow treats both identically.
 */
class JobDescriptionTemplateService
{
    /** Available role templates for the picker. */
    public function catalog(): array
    {
        return JobDescriptionTemplateLibrary::catalog();
    }

    /**
     * Render a role template for a requisition and record the audit.
     * @return array{content:string, template:string, jd_source:string}
     */
    public function generate(HrManpowerRequest $mr, User $user, string $template): array
    {
        if (! JobDescriptionTemplateLibrary::has($template)) {
            throw new BusinessException("Unknown JD template: {$template}", 422);
        }

        $content = JobDescriptionTemplateLibrary::render($mr, $template, $user->tenant?->name);

        $mr->recordAudit('Template JD Generated', $user, null, ['template' => $template]);
        Log::channel('hr')->info('Template JD generated', ['mr_id' => $mr->id, 'template' => $template, 'tenant_id' => $mr->tenant_id]);

        return ['content' => $content, 'template' => $template, 'jd_source' => 'template'];
    }
}

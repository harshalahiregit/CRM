<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateEmailTemplateRequest;
use App\Services\Email\EmailTemplateService;
use App\Support\Email\EmailTemplateRegistry;
use App\Support\Email\MergeFields\MergeFieldRegistry;
use Illuminate\Http\Request;

/**
 * Email Templates settings — list, edit, preview, restore and validate.
 * Admin-only, tenant-scoped. All routes are new; no existing endpoint changes.
 * Nothing here sends email.
 */
class EmailTemplateController extends Controller
{
    public function __construct(
        private EmailTemplateService $templates,
        private MergeFieldRegistry $mergeFields,
    ) {
    }

    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;
        // Validate the filters: an array (?search[]=x) would otherwise reach a
        // string cast and 500.
        $filters = $request->validate([
            'category' => 'nullable|string|max:60',
            'search'   => 'nullable|string|max:200',
        ]);

        return response()->json([
            'templates'    => $this->templates->all($tenantId, $filters),
            'categories'   => EmailTemplateRegistry::categories(),
            'merge_fields' => $this->mergeFields->catalogue(),
        ]);
    }

    public function show(Request $request, string $key)
    {
        return response()->json($this->templates->find($request->user()->tenant_id, $key));
    }

    public function update(UpdateEmailTemplateRequest $request, string $key)
    {
        $tenantId = $request->user()->tenant_id;

        // The service is the authority on whether the content is sane.
        $draft = array_merge($this->templates->find($tenantId, $key), $request->validated());
        $check = $this->templates->validate($draft);

        if (! $check['valid']) {
            return response()->json(['message' => 'Invalid email template.', 'errors' => $check['errors']], 422);
        }

        return response()->json(
            $this->templates->update($tenantId, $key, $request->validated(), $request->user())
        );
    }

    /** Preview with sample data. Never sends; never consumes a document number. */
    public function preview(Request $request, string $key)
    {
        $data = $request->validate([
            'draft'             => 'nullable|array',
            // Typed, or an array member reaches a ?string parameter and 500s.
            'draft.name'        => 'nullable|string|max:150',
            'draft.subject'     => 'nullable|string|max:255',
            'draft.body_html'   => 'nullable|string|max:120000',
            'draft.body_text'   => 'nullable|string|max:60000',
            'draft.description' => 'nullable|string|max:500',
            'draft.enabled'     => 'nullable|boolean',
            'context'           => 'nullable|array',
        ]);

        // A field the user CLEARED arrives as null (ConvertEmptyStringsToNull).
        // Without this it would be dropped and the preview would show stale content.
        $draft = $data['draft'] ?? [];
        foreach (['subject', 'body_html', 'body_text', 'description'] as $field) {
            if (array_key_exists($field, $draft) && $draft[$field] === null) {
                $draft[$field] = '';
            }
        }

        return response()->json($this->templates->preview(
            $request->user()->tenant_id,
            $key,
            $draft,
            $data['context'] ?? [],
        ));
    }

    /** Drop this tenant's override so the shipped template applies again. */
    public function restore(Request $request, string $key)
    {
        return response()->json(
            $this->templates->restoreDefault($request->user()->tenant_id, $key, $request->user())
        );
    }

    /** Validate arbitrary content (including unsaved drafts) without storing it. */
    public function validateTemplate(Request $request)
    {
        $data = $request->validate([
            'subject'   => 'nullable|string|max:255',
            'body_html' => 'nullable|string|max:120000',
            'body_text' => 'nullable|string|max:60000',
        ]);

        return response()->json($this->templates->validate($data));
    }
}

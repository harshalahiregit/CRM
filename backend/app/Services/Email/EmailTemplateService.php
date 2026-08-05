<?php

namespace App\Services\Email;

use App\Exceptions\BusinessException;
use App\Models\Email\EmailTemplate;
use App\Models\User;
use App\Support\Email\EmailTemplateRegistry;
use App\Support\Email\MergeContext;
use App\Support\Email\MergeFields\MergeFieldRegistry;
use App\Support\HtmlSanitizer;

/**
 * The single source of truth for email content.
 *
 * RESOLUTION: a tenant's stored row (if any) overlays the shipped registry
 * template. Nothing is seeded into the database, so improvements to a shipped
 * template reach every tenant that has not customised it, and "restore default"
 * is a delete rather than a rewrite.
 *
 * SAFETY: HTML bodies are sanitised on save (App\Support\HtmlSanitizer), and merge
 * VALUES are escaped at render time by MergeFieldRegistry. Rendering never sends
 * anything and never consumes a document number.
 */
class EmailTemplateService
{
    public function __construct(private MergeFieldRegistry $mergeFields)
    {
    }

    /* ── Reading ─────────────────────────────────────────────────────────── */

    /** Every template, shipped defaults overlaid with this tenant's overrides. */
    public function all(int $tenantId, array $filters = []): array
    {
        $overrides = EmailTemplate::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('key');

        $out = [];
        foreach (EmailTemplateRegistry::all() as $key => $default) {
            $out[] = $this->merge($default, $overrides->get($key));
        }

        // Tenant-authored templates that have no shipped counterpart.
        foreach ($overrides as $key => $row) {
            if (! EmailTemplateRegistry::exists($key)) {
                $out[] = $this->merge(null, $row);
            }
        }

        if (! empty($filters['category']) && $filters['category'] !== 'all') {
            $out = array_values(array_filter($out, fn ($t) => $t['category'] === $filters['category']));
        }
        if (! empty($filters['search'])) {
            $q = mb_strtolower((string) $filters['search']);
            $out = array_values(array_filter($out, fn ($t) => str_contains(mb_strtolower(
                $t['key'].' '.$t['name'].' '.$t['subject'].' '.$t['description']
            ), $q)));
        }

        return $out;
    }

    /** One template's effective content. */
    public function find(int $tenantId, string $key): array
    {
        $default = EmailTemplateRegistry::get($key);
        $override = EmailTemplate::query()->where('tenant_id', $tenantId)->where('key', $key)->first();

        if (! $default && ! $override) {
            throw new BusinessException("Unknown email template '{$key}'.", 404);
        }

        return $this->merge($default, $override);
    }

    /* ── Writing ─────────────────────────────────────────────────────────── */

    public function update(int $tenantId, string $key, array $data, ?User $actor = null): array
    {
        $current = $this->find($tenantId, $key);   // 404s an unknown key

        $row = EmailTemplate::query()->where('tenant_id', $tenantId)->where('key', $key)->first();

        $payload = [
            'tenant_id'   => $tenantId,
            'key'         => $key,
            'category'    => $current['category'],
            'name'        => $data['name'] ?? $current['name'],
            'subject'     => $data['subject'] ?? $current['subject'],
            // The body is authored HTML: trusted after sanitising, never escaped.
            'body_html'   => $this->sanitizeBody($data['body_html'] ?? $current['body_html']),
            'description' => $data['description'] ?? $current['description'],
            // `enabled` is nullable in the request, and a null must not read as
            // false — that would silently disable the template.
            'enabled'     => ($data['enabled'] ?? null) !== null ? (bool) $data['enabled'] : $current['enabled'],
            'is_system'   => EmailTemplateRegistry::exists($key),
            'updated_by'  => $actor?->id,
            'version'     => ($row->version ?? 0) + 1,
        ];

        // A blank plain-text body is regenerated from the HTML so multipart mail
        // always has a usable alternative part. A hand-authored text part is kept
        // when the caller simply omits the field (partial update).
        $text = $data['body_text'] ?? $current['body_text'] ?? '';
        $payload['body_text'] = trim((string) $text) !== ''
            ? $text
            : EmailTemplateRegistry::toPlainText($payload['body_html']);

        // Validate what will ACTUALLY be stored (post-sanitise, post-derive), not
        // the raw payload — otherwise markup the sanitiser deletes wholesale (e.g.
        // a pasted <style> block) passes validation and persists an empty body.
        $check = $this->validate($payload);
        if (! $check['valid']) {
            throw new BusinessException(
                'Invalid email template: '.implode(' ', $check['errors']), 422
            );
        }

        $template = EmailTemplate::updateOrCreate(
            ['tenant_id' => $tenantId, 'key' => $key],
            $payload,
        );

        $template->recordAudit('Email Template Updated', $actor, "Template '{$key}' updated", [
            'key' => $key, 'version' => $template->version,
        ]);

        return $this->find($tenantId, $key);
    }

    /**
     * Restore the shipped template by dropping the tenant's override. Other
     * templates' customisations are untouched — that is the whole point of storing
     * only overrides.
     */
    public function restoreDefault(int $tenantId, string $key, ?User $actor = null): array
    {
        if (! EmailTemplateRegistry::exists($key)) {
            throw new BusinessException("'{$key}' has no shipped default to restore.", 422);
        }

        $row = EmailTemplate::query()->where('tenant_id', $tenantId)->where('key', $key)->first();
        if ($row) {
            $row->recordAudit('Email Template Restored', $actor, "Template '{$key}' restored to default", ['key' => $key]);
            $row->delete();
        }

        return $this->find($tenantId, $key);
    }

    /* ── Rendering ───────────────────────────────────────────────────────── */

    /**
     * Render a template for sending. Returns the subject plus both body parts.
     * Does NOT send — delivery stays with TenantMailer.
     *
     * @return array{subject:string, html:string, text:string, enabled:bool}
     */
    public function render(int $tenantId, string $key, array $data = []): array
    {
        $template = $this->find($tenantId, $key);

        return $this->renderTemplate($tenantId, $template, $data);
    }

    /** Render arbitrary (possibly unsaved) template content — used by preview. */
    public function renderTemplate(int $tenantId, array $template, array $data = []): array
    {
        $html = new MergeContext($tenantId, $data, MergeContext::MODE_HTML);
        $text = new MergeContext($tenantId, $data, MergeContext::MODE_TEXT);

        return [
            'subject' => $this->mergeFields->expand($template['subject'] ?? '', $text),
            'html'    => $this->mergeFields->expand($template['body_html'] ?? '', $html),
            'text'    => $this->mergeFields->expand($template['body_text'] ?? '', $text),
            'enabled' => (bool) ($template['enabled'] ?? true),
        ];
    }

    /**
     * Preview with sample data. NEVER sends and never consumes a number. Accepts an
     * unsaved draft so the editor can preview before saving.
     */
    public function preview(int $tenantId, string $key, array $draft = [], array $data = []): array
    {
        $template = array_merge($this->find($tenantId, $key), array_filter(
            $draft,
            fn ($v) => $v !== null,
        ));

        $sample = array_replace_recursive($this->mergeFields->sampleData(), $data);
        $rendered = $this->renderTemplate($tenantId, $template, $sample);

        return $rendered + ['validation' => $this->validate($template)];
    }

    /* ── Validation ──────────────────────────────────────────────────────── */

    /**
     * @return array{valid:bool, errors:array<string,string>, unknown_tokens:array}
     */
    public function validate(array $template): array
    {
        $errors = [];

        if (trim((string) ($template['subject'] ?? '')) === '') {
            $errors['subject'] = 'Subject is required.';
        }
        if (trim(strip_tags((string) ($template['body_html'] ?? ''))) === ''
            && trim((string) ($template['body_text'] ?? '')) === '') {
            $errors['body_html'] = 'The template needs a body.';
        }

        $unknown = array_values(array_unique(array_merge(
            $this->mergeFields->unknownTokensIn($template['subject'] ?? ''),
            $this->mergeFields->unknownTokensIn($template['body_html'] ?? ''),
            $this->mergeFields->unknownTokensIn($template['body_text'] ?? ''),
        )));

        // Unknown merge fields are a WARNING, not an error: they render as empty
        // text, which is a content mistake rather than a broken template. Treating
        // them as fatal would make a template with a typo permanently unsaveable.
        $warnings = [];
        if ($unknown) {
            $warnings['merge_fields'] = 'Unknown merge field(s): '
                .implode(', ', array_map(fn ($t) => '{{'.$t.'}}', $unknown))
                .'. They will render as empty text.';
        }

        return [
            'valid'          => $errors === [],
            'errors'         => $errors,
            'warnings'       => $warnings,
            'unknown_tokens' => $unknown,
        ];
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    /** Sentinel host used to smuggle merge tokens past the URL scheme check. */
    private const TOKEN_URL = 'https://merge-field.invalid/';

    /**
     * Sanitise an authored body WITHOUT destroying merge fields.
     *
     * HtmlSanitizer drops any href/src that is not literally http(s) — which would
     * silently delete every `<a href="{{url.reset}}">`, i.e. the only link in most
     * shipped templates. So tokens are swapped for a sentinel https URL, sanitised,
     * then swapped back. The sanitiser's javascript:/data: guarantee is untouched
     * for every other caller; the expanded VALUE is scheme-checked separately by
     * the url.* resolvers at render time.
     */
    private function sanitizeBody(?string $html): string
    {
        $tokens = [];

        $masked = (string) preg_replace_callback(
            '/\{\{\s*([A-Za-z0-9_.]+)\s*\}\}/',
            function (array $m) use (&$tokens) {
                $slot = self::TOKEN_URL.count($tokens);
                $tokens[$slot] = '{{'.strtolower(trim($m[1])).'}}';

                return $slot;
            },
            (string) $html,
        );

        $clean = HtmlSanitizer::clean($masked);

        return $tokens ? str_replace(array_keys($tokens), array_values($tokens), $clean) : $clean;
    }

    /** Shipped default overlaid with the tenant's row (either may be absent). */
    private function merge(?array $default, ?EmailTemplate $override): array
    {
        $base = $default ?? [
            'key' => $override?->key, 'category' => $override?->category ?? 'system',
            'name' => $override?->name, 'subject' => '', 'body_html' => '', 'body_text' => '',
            'description' => '', 'enabled' => true, 'is_system' => false,
        ];

        if (! $override) {
            return $base + ['customised' => false, 'version' => 0, 'updated_at' => null];
        }

        return [
            'key'         => $base['key'],
            'category'    => $override->category ?: $base['category'],
            'name'        => $override->name ?: $base['name'],
            'subject'     => $override->subject,
            'body_html'   => (string) $override->body_html,
            'body_text'   => (string) $override->body_text,
            'description' => $override->description ?? $base['description'],
            'enabled'     => (bool) $override->enabled,
            'is_system'   => (bool) $override->is_system,
            'customised'  => true,
            'version'     => (int) $override->version,
            'updated_at'  => optional($override->updated_at)->toIso8601String(),
        ];
    }
}

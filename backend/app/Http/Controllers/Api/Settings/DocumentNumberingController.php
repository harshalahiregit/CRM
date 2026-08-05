<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdateDocumentNumberConfigRequest;
use App\Models\Numbering\DocumentNumberConfig;
use App\Models\Numbering\DocumentNumberSequence;
use App\Services\Numbering\DatabaseDocumentNumberService;
use App\Support\Numbering\DocumentTypeRegistry;
use App\Support\Numbering\Placeholders\PlaceholderRegistry;
use App\Support\Numbering\Reset\ResetStrategyRegistry;
use Illuminate\Http\Request;

/**
 * Document numbering settings — configuration, preview, reset and validation.
 * Admin-only, tenant-scoped. All routes are new; no existing endpoint changes.
 */
class DocumentNumberingController extends Controller
{
    public function __construct(
        private DatabaseDocumentNumberService $numbers,
        private PlaceholderRegistry $placeholders,
        private ResetStrategyRegistry $resets,
    ) {
    }

    /** Catalogue + every type's effective configuration. */
    public function index(Request $request)
    {
        $tenantId = $request->user()->tenant_id;

        // Preload every stored config in one query — otherwise rendering 30 types
        // re-reads the config table for each of them.
        $stored = DocumentNumberConfig::query()
            ->where('tenant_id', $tenantId)
            ->get()
            ->keyBy('document_type');

        $types = [];
        foreach (DocumentTypeRegistry::all() as $key => $definition) {
            $types[] = [
                'key'    => $key,
                'label'  => $definition['label'],
                'module' => $definition['module'],
                // Never null: an unconfigured type gets its registry default
                // in-memory, so no per-type config query is issued.
                'config' => $this->configPayload($tenantId, $key, $stored->get($key) ?? $this->numbers->defaultConfig($tenantId, $key)),
            ];
        }

        return response()->json([
            'types'        => $types,
            'grouped'      => DocumentTypeRegistry::grouped(),
            'reset_rules'  => $this->resets->options(),
            'placeholders' => $this->placeholderMeta(),
        ]);
    }

    public function show(Request $request, string $type)
    {
        abort_unless(DocumentTypeRegistry::exists($type), 404, 'Unknown document type');

        return response()->json($this->configPayload($request->user()->tenant_id, $type));
    }

    public function update(UpdateDocumentNumberConfigRequest $request, string $type)
    {
        abort_unless(DocumentTypeRegistry::exists($type), 404, 'Unknown document type');

        $tenantId = $request->user()->tenant_id;
        $existing = $this->numbers->config($tenantId, $type);
        $payload = $request->validated();

        // A locked configuration may ONLY be unlocked. Sending locked:false together
        // with other edits would otherwise unlock and edit atomically, defeating the
        // lock, so the unlock request must carry nothing else.
        if ($existing->exists && $existing->locked) {
            $unlockOnly = array_key_exists('locked', $payload)
                && ! $payload['locked']
                && $this->changedKeys($existing, $payload) === ['locked'];

            if (! $unlockOnly) {
                return response()->json([
                    'message' => 'This numbering configuration is locked. Unlock it first (on its own), then make changes.',
                ], 422);
            }
        }

        // Changing the reset rule moves allocation to a different period key, so the
        // counter would restart and re-issue numbers that are already in use. Force
        // the admin through an explicit reset (which sets a safe starting number).
        if ($existing->exists
            && array_key_exists('reset_rule', $payload)
            && $payload['reset_rule'] !== $existing->reset_rule
            && $this->hasIssuedNumbers($tenantId, $type)) {
            return response()->json([
                'message' => 'Numbers have already been issued under the current reset rule. Reset the sequence (choosing a safe starting number) before changing the rule, or numbers would repeat.',
                'errors'  => ['reset_rule' => 'Cannot change the reset rule once numbers have been issued.'],
            ], 422);
        }

        // Validate the FULL effective row (stored/registry values + this payload) —
        // and persist exactly what was validated, so an omitted field can never fall
        // back to a column default that was never checked or previewed.
        $data = array_merge($existing->attributesToArray(), $payload);
        $data['document_type'] = $type;
        $data['tenant_id'] = $tenantId;

        // The engine is the single authority on whether a configuration is sane.
        $check = $this->numbers->validate($data);
        if (! $check['valid']) {
            return response()->json(['message' => 'Invalid numbering configuration.', 'errors' => $check['errors']], 422);
        }

        $persist = collect($data)
            ->only(['format', 'prefix', 'suffix', 'minimum_digits', 'padding', 'starting_number',
                'reset_rule', 'enabled', 'locked', 'manual_override', 'decrement_on_delete'])
            ->put('updated_by', $request->user()->id)
            ->all();

        $config = DocumentNumberConfig::updateOrCreate(
            ['tenant_id' => $tenantId, 'document_type' => $type],
            $persist,
        );

        $config->recordAudit('Numbering Configuration Updated', $request->user(), "Numbering updated for {$type}", [
            'document_type' => $type,
        ] + $request->validated());

        return response()->json($this->configPayload($tenantId, $type));
    }

    /** Preview never consumes a number. */
    public function preview(Request $request, string $type)
    {
        abort_unless(DocumentTypeRegistry::exists($type), 404, 'Unknown document type');

        $tenantId = $request->user()->tenant_id;
        $context = (array) $request->input('context', []);

        // An unsaved draft can be previewed by validating it in-memory.
        if ($request->filled('config')) {
            $draft = array_merge(
                $this->numbers->config($tenantId, $type)->attributesToArray(),
                (array) $request->input('config'),
                ['document_type' => $type, 'tenant_id' => $tenantId],
            );
            $check = $this->numbers->validate($draft);

            return response()->json([
                'preview' => $check['preview'],
                'valid'   => $check['valid'],
                'errors'  => $check['errors'],
            ], $check['valid'] ? 200 : 422);
        }

        return response()->json([
            'preview'        => $this->numbers->preview($tenantId, $type, $context),
            'current_number' => $this->numbers->currentNumber($tenantId, $type),
            'period_key'     => $this->numbers->periodKey($tenantId, $type),
            'valid'          => true,
            'errors'         => [],
        ]);
    }

    /** Non-destructive: opens a new sequence period. */
    public function reset(Request $request, string $type)
    {
        abort_unless(DocumentTypeRegistry::exists($type), 404, 'Unknown document type');

        $data = $request->validate(['starting_number' => 'nullable|integer|min:1']);

        $this->numbers->reset($request->user()->tenant_id, $type, $data['starting_number'] ?? null);

        return response()->json($this->configPayload($request->user()->tenant_id, $type));
    }

    /** Validate a candidate configuration without saving it. */
    public function validateConfig(Request $request)
    {
        $data = $request->validate([
            'document_type' => 'required|string',
            'format'        => 'required|string|max:191',
        ]);

        abort_unless(DocumentTypeRegistry::exists($data['document_type']), 404, 'Unknown document type');

        $draft = array_merge(
            DocumentTypeRegistry::defaults($data['document_type']),
            $request->all(),
            ['tenant_id' => $request->user()->tenant_id],
        );

        return response()->json($this->numbers->validate($draft));
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    /** Keys in the payload whose value actually differs from what is stored. */
    private function changedKeys(DocumentNumberConfig $existing, array $payload): array
    {
        $changed = [];
        foreach ($payload as $key => $value) {
            // Loose-compare through strings so 1/true and 0/false do not read as edits.
            $before = $existing->getAttribute($key);
            if ((is_bool($before) ? (int) $before : (string) $before) !== (is_bool($value) ? (int) $value : (string) $value)) {
                $changed[] = $key;
            }
        }

        return $changed;
    }

    /** Has this document type ever issued a number for this tenant? */
    private function hasIssuedNumbers(int $tenantId, string $type): bool
    {
        return DocumentNumberSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', $type)
            ->where('current_sequence', '>', 0)
            ->exists();
    }

    /**
     * The effective configuration plus derived runtime state. `current_number`
     * lives on the sequence table, never on the config row — it is reported here
     * read-only so the UI can show it without the config owning mutable state.
     */
    private function configPayload(int $tenantId, string $type, ?DocumentNumberConfig $preloaded = null): array
    {
        $config = $preloaded ?? $this->numbers->config($tenantId, $type);
        $definition = DocumentTypeRegistry::get($type);
        // Pass the resolved model (saved or registry-default) so snapshot() does not
        // re-read the config table.
        $snapshot = $this->numbers->snapshot($tenantId, $type, $config);

        return [
            'document_type'       => $type,
            'label'               => $definition['label'] ?? $type,
            'module'              => $definition['module'] ?? 'Other',
            'configured'          => $config->exists,
            'format'              => $config->format,
            'prefix'              => $config->prefix,
            'suffix'              => $config->suffix,
            'minimum_digits'      => (int) $config->minimum_digits,
            'padding'             => $config->padding,
            'starting_number'     => (int) $config->starting_number,
            'reset_rule'          => $config->reset_rule,
            'epoch'               => (int) $config->epoch,
            'enabled'             => (bool) $config->enabled,
            'locked'              => (bool) $config->locked,
            'manual_override'     => (bool) $config->manual_override,
            'decrement_on_delete' => (bool) $config->decrement_on_delete,
            // Derived runtime state (read-only), resolved in a single pass.
            'current_number'      => $snapshot['current_number'],
            'period_key'          => $snapshot['period_key'],
            'preview'             => $snapshot['preview'],
        ];
    }

    private function placeholderMeta(): array
    {
        $out = [];
        foreach ($this->placeholders->all() as $token => $resolver) {
            $out[] = ['token' => '{'.$token.'}', 'description' => $resolver->description()];
        }

        return $out;
    }
}

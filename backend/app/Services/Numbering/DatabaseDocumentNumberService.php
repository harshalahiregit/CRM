<?php

namespace App\Services\Numbering;

use App\Events\Numbering\AfterGenerate;
use App\Events\Numbering\AfterReset;
use App\Events\Numbering\BeforeGenerate;
use App\Events\Numbering\BeforeReset;
use App\Exceptions\BusinessException;
use App\Models\Numbering\DocumentNumberConfig;
use App\Models\Numbering\DocumentNumberSequence;
use App\Services\Settings\SettingsFormatter;
use App\Support\Numbering\DocumentTypeRegistry;
use App\Support\Numbering\NumberingContext;
use App\Support\Numbering\Placeholders\PlaceholderRegistry;
use App\Support\Numbering\Reset\ResetStrategyRegistry;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Document Numbering Engine.
 *
 * ── SEQUENCE POLICY ──────────────────────────────────────────────────────────
 *  1. Within a period, numbers are allocated atomically and are never reused.
 *     ACROSS periods, uniqueness of the rendered STRING is the format's job: the
 *     period key (year/month/day/epoch) is the uniqueness domain, so a format that
 *     omits the matching date token — or any explicit reset — deliberately
 *     restarts the series and CAN re-issue an earlier string. That is what an
 *     administrator asks for when they reset; the engine does not silently prevent
 *     it, but it does refuse to change the reset RULE once numbers exist (see
 *     DocumentNumberingController::update), because that would restart the series
 *     without anyone asking.
 *  2. GAPS ARE ALLOWED. If a transaction rolls back, or a document is cancelled or
 *     deleted after its number was issued, that number stays burnt. Note that when
 *     a caller wraps generate() in its own transaction, allocation participates in
 *     that transaction and a rollback DOES return the number to the pool.
 *     Rationale: reuse requires knowing that nothing else ever referenced the
 *     number (PDFs, e-mails, exports, tax filings already contain it), and a
 *     "find the lowest free number" allocator cannot be made race-free without
 *     serialising every insert. Most tax regimes also require monotonic,
 *     non-reused document numbers. A gap is auditable; a duplicate is a defect.
 *  3. `decrement_on_delete` therefore exists as an OPT-IN FLAG only. The engine
 *     never rolls a counter back implicitly — a caller must ask, and it only
 *     succeeds when the number being released is the most recent one in its
 *     period (so it can never create a duplicate).
 *
 * ── CONCURRENCY ──────────────────────────────────────────────────────────────
 *  Allocation copies the pattern already proven in this codebase
 *  (Tpv\RegistrationNumberService): DB::transaction → insertOrIgnore to
 *  materialise the row without racing → lockForUpdate()->first() → update.
 *  The unique index (tenant_id, document_type, period_key) is the portable
 *  backstop. NOTE: on SQLite lockForUpdate() compiles to an empty string, so the
 *  lock is a no-op there; correctness under real concurrency is a MySQL/Postgres
 *  guarantee, while the unique index holds everywhere.
 *
 * ── OPT-IN ───────────────────────────────────────────────────────────────────
 *  A document type is only served once a config row exists AND is enabled. Until
 *  then isEnabled() returns false and the owning module keeps its existing
 *  numbering untouched — this is what makes the engine backward compatible.
 */
class DatabaseDocumentNumberService implements DocumentNumberServiceInterface
{
    /** Prefix/suffix must stay filename- and URL-safe. */
    private const SAFE_AFFIX = '/^[A-Za-z0-9\-\/_.# ]*$/';

    private const MAX_DIGITS = 12;

    public function __construct(
        private PlaceholderRegistry $placeholders,
        private ResetStrategyRegistry $resets,
        private SettingsFormatter $formatter,
        private \App\Services\Settings\SettingsService $settings,
    ) {
    }

    /* ── Public contract ─────────────────────────────────────────────────── */

    public function preview(int $tenantId, string $documentType, array $context = [], ?CarbonInterface $at = null): string
    {
        $config = $this->configArray($tenantId, $documentType);
        $ctx = $this->context($tenantId, $documentType, $config, $at, $context);
        $periodKey = $this->resets->periodKeyFor((string) $config['reset_rule'], $ctx);

        // READ ONLY — preview must never consume a number.
        $current = DocumentNumberSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey)
            ->value('current_sequence');

        $next = $current === null
            ? max(1, (int) $config['starting_number'])
            : ((int) $current) + 1;

        return $this->render($ctx->withSequence($next));
    }

    public function generate(int $tenantId, string $documentType, array $context = [], ?CarbonInterface $at = null): string
    {
        return $this->reserve($tenantId, $documentType, $context, $at)['number'];
    }

    public function reserve(int $tenantId, string $documentType, array $context = [], ?CarbonInterface $at = null): array
    {
        $this->assertKnownType($documentType);

        $config = $this->configArray($tenantId, $documentType);

        if (! $config['enabled']) {
            throw new BusinessException("Numbering for '{$documentType}' is not enabled for this workspace.", 422);
        }

        $ctx = $this->context($tenantId, $documentType, $config, $at, $context);
        $periodKey = $this->resets->periodKeyFor((string) $config['reset_rule'], $ctx);

        BeforeGenerate::dispatch($tenantId, $documentType, ['period_key' => $periodKey, 'config' => $config]);

        $sequence = $this->nextSequence($tenantId, $documentType, $periodKey, (int) $config['starting_number']);
        $number = $this->render($ctx->withSequence($sequence));

        $result = [
            'number'        => $number,
            'sequence'      => $sequence,
            'period_key'    => $periodKey,
            'document_type' => $documentType,
        ];

        AfterGenerate::dispatch($tenantId, $documentType, $result);

        return $result;
    }

    /**
     * Atomic allocation. Safe to call inside an outer transaction — Laravel nests
     * this as a savepoint, and the row lock is held to the outermost commit.
     */
    public function nextSequence(int $tenantId, string $documentType, string $periodKey, int $startingNumber = 1): int
    {
        $start = max(1, $startingNumber);

        // 3 attempts: on MySQL two concurrent allocators can deadlock upgrading the
        // INSERT IGNORE's shared lock to the SELECT ... FOR UPDATE exclusive lock
        // (error 1213). Laravel retries the whole closure on a concurrency error.
        return DB::transaction(function () use ($tenantId, $documentType, $periodKey, $start) {
            // Materialise the counter row without racing a concurrent insert. The
            // unique index makes the loser of that race a silent no-op.
            DB::table('document_number_sequences')->insertOrIgnore([
                'tenant_id'        => $tenantId,
                'document_type'    => $documentType,
                'period_key'       => $periodKey,
                // First allocation in a period must yield exactly starting_number.
                'current_sequence' => $start - 1,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $row = DB::table('document_number_sequences')
                ->where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            // Defensive: an aborted concurrent insert could in theory leave nothing
            // to read. Fail loudly and retryably rather than fatal on null.
            if (! $row) {
                throw new BusinessException('Could not allocate a document number, please retry.', 409);
            }

            $next = ((int) $row->current_sequence) + 1;

            DB::table('document_number_sequences')
                ->where('id', $row->id)
                ->update(['current_sequence' => $next, 'updated_at' => now()]);

            return $next;
        }, 3);
    }

    public function reset(int $tenantId, string $documentType, ?int $startingNumber = null): DocumentNumberConfig
    {
        $this->assertKnownType($documentType);

        $config = $this->config($tenantId, $documentType);

        if ($config->locked) {
            throw new BusinessException("Numbering for '{$documentType}' is locked and cannot be reset.", 422);
        }

        BeforeReset::dispatch($tenantId, $documentType, [
            'epoch' => $config->epoch, 'starting_number' => $config->starting_number,
        ]);

        // Non-destructive: bump the epoch so the next allocation opens a NEW
        // sequence row. Previously issued ranges remain on their own rows.
        $config->epoch = (int) $config->epoch + 1;
        if ($startingNumber !== null) {
            $config->starting_number = max(1, $startingNumber);
        }
        $config->save();

        // recordAudit() is typed ?User, but this app also authenticates
        // PurchaseVendor on the portal guard — pass an actor only when it is a User.
        $actor = auth()->user();
        $actor = $actor instanceof \App\Models\User ? $actor : null;

        $config->recordAudit('Numbering Reset', $actor, "Sequence reset for {$documentType}", [
            'document_type'   => $documentType,
            'epoch'           => $config->epoch,
            'starting_number' => $config->starting_number,
        ]);
        Log::info('Document numbering reset', [
            'tenant_id' => $tenantId, 'document_type' => $documentType, 'epoch' => $config->epoch,
        ]);

        AfterReset::dispatch($tenantId, $documentType, ['epoch' => $config->epoch]);

        return $config->fresh();
    }

    public function validate(array $config): array
    {
        $errors = [];

        $format = trim((string) ($config['format'] ?? ''));
        $prefix = (string) ($config['prefix'] ?? '');
        $suffix = (string) ($config['suffix'] ?? '');
        $digits = (int) ($config['minimum_digits'] ?? 4);
        $start = (int) ($config['starting_number'] ?? 1);
        $rule = (string) ($config['reset_rule'] ?? 'never');

        if ($format === '') {
            $errors['format'] = 'Format is required.';
        } else {
            // Accumulate — a format can be wrong in more than one way at once, and
            // reporting only the last problem sends the user round in circles.
            $formatErrors = [];
            $tokens = $this->placeholders->tokensIn($format);

            if (! in_array('NEXT', $tokens, true)) {
                $formatErrors[] = 'Format must contain the {NEXT} placeholder, or numbers would repeat.';
            }

            $dupes = array_keys(array_filter(array_count_values($tokens), fn ($n) => $n > 1));
            if ($dupes) {
                $formatErrors[] = 'Duplicate placeholder(s): '.implode(', ', array_map(fn ($t) => '{'.$t.'}', $dupes)).'.';
            }

            $unknown = $this->placeholders->unknownTokensIn($format);
            if ($unknown) {
                $formatErrors[] = 'Unknown placeholder(s): '.implode(', ', array_map(fn ($t) => '{'.$t.'}', $unknown)).'.';
            }

            if ($formatErrors) {
                $errors['format'] = implode(' ', $formatErrors);
            }
        }

        if (! preg_match(self::SAFE_AFFIX, $prefix)) {
            $errors['prefix'] = 'Prefix may only contain letters, numbers and - / _ . # and spaces.';
        }
        if (! preg_match(self::SAFE_AFFIX, $suffix)) {
            $errors['suffix'] = 'Suffix may only contain letters, numbers and - / _ . # and spaces.';
        }
        if ($digits < 1 || $digits > self::MAX_DIGITS) {
            $errors['minimum_digits'] = 'Minimum digits must be between 1 and '.self::MAX_DIGITS.'.';
        }
        if ($start < 1) {
            $errors['starting_number'] = 'Starting number must be at least 1.';
        }
        if (! $this->resets->has($rule)) {
            $errors['reset_rule'] = "Unknown reset rule '{$rule}'.";
        }

        // A format that cannot render is invalid even if every field looked fine.
        $preview = null;
        if (! $errors) {
            try {
                $merged = array_merge(DocumentTypeRegistry::defaults($config['document_type'] ?? 'invoice'), $config);
                $ctx = new NumberingContext(
                    (int) ($config['tenant_id'] ?? 0),
                    (string) ($config['document_type'] ?? 'invoice'),
                    $merged,
                    Carbon::now(),
                    max(1, $start),
                    ['branch' => 'BR1', 'department' => 'DEP', 'company' => 'Company'],
                );
                $preview = $this->render($ctx);
            } catch (\Throwable $e) {
                $errors['format'] = 'Format could not be rendered: '.$e->getMessage();
            }
        }

        return ['valid' => $errors === [], 'errors' => $errors, 'preview' => $preview];
    }

    /* ── Configuration helpers (also used by the API layer) ──────────────── */

    /** The stored config, or a registry-default row (unsaved) when none exists. */
    public function config(int $tenantId, string $documentType): DocumentNumberConfig
    {
        $this->assertKnownType($documentType);

        $config = DocumentNumberConfig::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->first();

        if ($config) {
            return $config;
        }

        return $this->defaultConfig($tenantId, $documentType);
    }

    /** The registry-default (unsaved) config for a type — no query. */
    public function defaultConfig(int $tenantId, string $documentType): DocumentNumberConfig
    {
        return new DocumentNumberConfig(
            array_merge(DocumentTypeRegistry::defaults($documentType), ['tenant_id' => $tenantId])
        );
    }

    /** Whether the engine (rather than the owning module) numbers this type. */
    public function isEnabled(int $tenantId, string $documentType): bool
    {
        return (bool) $this->configArray($tenantId, $documentType)['enabled'];
    }

    /** The number most recently issued in the current period (0 when none yet). */
    public function currentNumber(int $tenantId, string $documentType, ?CarbonInterface $at = null): int
    {
        $config = $this->configArray($tenantId, $documentType);
        $ctx = $this->context($tenantId, $documentType, $config, $at);
        $periodKey = $this->resets->periodKeyFor((string) $config['reset_rule'], $ctx);

        return (int) DocumentNumberSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey)
            ->value('current_sequence');
    }

    /**
     * Derived runtime state for one type, resolved in ONE pass. The listing screen
     * renders 30 types; calling preview()/currentNumber()/periodKey() separately
     * would re-read the config for each of them.
     *
     * @return array{current_number:int, period_key:string, preview:string}
     */
    public function snapshot(int $tenantId, string $documentType, ?DocumentNumberConfig $config = null): array
    {
        // A caller that already holds the config (the listing screen) passes it in,
        // so no further read happens per type.
        $configArray = $config
            ? array_merge(DocumentTypeRegistry::defaults($documentType),
                array_filter($config->attributesToArray(), fn ($v) => $v !== null))
            : $this->configArray($tenantId, $documentType);

        $ctx = $this->context($tenantId, $documentType, $configArray);
        $periodKey = $this->resets->periodKeyFor((string) $configArray['reset_rule'], $ctx);

        $current = DocumentNumberSequence::query()
            ->where('tenant_id', $tenantId)
            ->where('document_type', $documentType)
            ->where('period_key', $periodKey)
            ->value('current_sequence');

        $next = $current === null ? max(1, (int) $configArray['starting_number']) : ((int) $current) + 1;

        return [
            'current_number' => (int) $current,
            'period_key'     => $periodKey,
            'preview'        => $this->render($ctx->withSequence($next)),
        ];
    }

    public function periodKey(int $tenantId, string $documentType, ?CarbonInterface $at = null): string
    {
        $config = $this->configArray($tenantId, $documentType);

        return $this->resets->periodKeyFor(
            (string) $config['reset_rule'],
            $this->context($tenantId, $documentType, $config, $at),
        );
    }

    /**
     * Release the most recent number in a period (opt-in `decrement_on_delete`).
     * Refuses unless the caller holds the newest number, so it can never mint a
     * duplicate. Returns true when the counter actually moved.
     */
    public function releaseLast(int $tenantId, string $documentType, int $sequence, ?CarbonInterface $at = null): bool
    {
        // Both the workspace-wide kill-switch AND the per-type flag must allow it.
        if (! $this->settings->get($tenantId, 'numbering', 'allow_decrement_on_delete')) {
            return false;
        }

        $config = $this->configArray($tenantId, $documentType);
        if (! $config['decrement_on_delete']) {
            return false;
        }

        $ctx = $this->context($tenantId, $documentType, $config, $at);
        $periodKey = $this->resets->periodKeyFor((string) $config['reset_rule'], $ctx);

        return (bool) DB::transaction(function () use ($tenantId, $documentType, $periodKey, $sequence) {
            $row = DB::table('document_number_sequences')
                ->where('tenant_id', $tenantId)
                ->where('document_type', $documentType)
                ->where('period_key', $periodKey)
                ->lockForUpdate()
                ->first();

            if (! $row || (int) $row->current_sequence !== $sequence) {
                return false;   // not the newest number — releasing would duplicate
            }

            DB::table('document_number_sequences')
                ->where('id', $row->id)
                ->update(['current_sequence' => max(0, $sequence - 1), 'updated_at' => now()]);

            return true;
        });
    }

    /* ── internals ───────────────────────────────────────────────────────── */

    private function render(NumberingContext $ctx): string
    {
        $body = $this->placeholders->expand((string) $ctx->config('format', '{PREFIX}{NEXT}'), $ctx);

        // A {SUFFIX} token in the format wins; otherwise the suffix is appended.
        $tokens = $this->placeholders->tokensIn((string) $ctx->config('format', ''));
        if (! in_array('SUFFIX', $tokens, true)) {
            $body .= (string) $ctx->config('suffix', '');
        }

        return $body;
    }

    private function configArray(int $tenantId, string $documentType): array
    {
        $config = $this->config($tenantId, $documentType);

        return array_merge(
            DocumentTypeRegistry::defaults($documentType),
            array_filter($config->attributesToArray(), fn ($v) => $v !== null),
        );
    }

    /** Timestamps resolve in the tenant's timezone — reuses the C formatter. */
    private function context(int $tenantId, string $documentType, array $config, ?CarbonInterface $at = null, array $extra = []): NumberingContext
    {
        $moment = $at ? Carbon::parse($at) : Carbon::now();
        $moment = $moment->copy()->setTimezone($this->formatter->timezone($tenantId));

        return new NumberingContext($tenantId, $documentType, $config, $moment, null, $extra);
    }

    private function assertKnownType(string $documentType): void
    {
        if (! DocumentTypeRegistry::exists($documentType)) {
            throw new BusinessException("Unknown document type '{$documentType}'.", 404);
        }
    }
}

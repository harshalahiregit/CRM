<?php

namespace Sire\AI;

use Sire\Support\Ai\AiContextSchema;

/**
 * SIRE AI — the boundary between a tenant's data and a third party.
 *
 * Direct port of tests/reference/aiRedaction.mjs; both run
 * fixtures/ai-redaction-cases.json. "We redact secrets" is a claim, and a claim
 * about a security boundary should be executable.
 *
 * FOUR LAYERS, in order:
 *   1. ALLOWLIST per capability — a field not named for this capability is never
 *      sent, including fields nobody has thought of yet.
 *   2. KEY REFUSAL — identity, credential-shaped and person-identifying keys are
 *      dropped even if an allowlist names them by mistake.
 *   3. VALUE SCRUBBING — emails, bearer tokens, URL query secrets and opaque
 *      high-entropy runs are removed from the text that survives.
 *   4. BOUNDS — value length and field count are capped.
 *
 * Every field is either kept or explained in the report. Silent disappearance is
 * how nobody notices a capability has been sending less than it should.
 */
class SireAiRedactor
{
    private const REDACTED = '[redacted]';
    private const MAX_DEPTH = 3;

    /** Identity never leaves. No capability is improved by knowing who. */
    private const IDENTITY_KEY_PATTERN =
        '/^(tenant_id|reporter_id|assignee_id|user_id|owner_id|created_by|updated_by|authorized_by|qa_assignee_id)$/i';

    /** Fields permitted to be arrays. Everything else must be a scalar. */
    private const STRUCTURED_FIELDS = ['sections', 'top_modules', 'trend', 'contributing_factors', 'five_whys'];

    /**
     * @param  array<string, mixed>  $input
     * @return array{context: array, report: array{kept: array, dropped: array, truncated: array}}
     */
    public function redact(string $capability, array $input, ?array $allowlist = null): array
    {
        // An unknown capability has no allowlist, so nothing is sent. Failing
        // closed matters most exactly where someone has made a mistake.
        $allowed = $allowlist ?? AiContextSchema::fieldsFor($capability);

        $context = [];
        $kept = [];
        $dropped = [];
        $truncated = [];

        foreach ($allowed as $field) {
            if (count($kept) >= AiContextSchema::MAX_FIELDS) {
                $dropped[$field] = 'field_cap';

                continue;
            }
            if (! array_key_exists($field, $input)) {
                continue;
            }

            if (preg_match(self::IDENTITY_KEY_PATTERN, $field)) { $dropped[$field] = 'identity_key'; continue; }
            if (preg_match(AiContextSchema::FORBIDDEN_KEY_PATTERN, $field)) { $dropped[$field] = 'forbidden_key'; continue; }
            if (preg_match(AiContextSchema::PII_KEY_PATTERN, $field)) { $dropped[$field] = 'pii_key'; continue; }

            $value = $input[$field];

            if ($value === null) { $dropped[$field] = 'empty'; continue; }

            if (is_array($value) || is_object($value)) {
                if (! in_array($field, self::STRUCTURED_FIELDS, true)) {
                    $dropped[$field] = 'not_scalar';

                    continue;
                }
                $context[$field] = $this->scrubStructured((array) $value);
                $kept[] = $field;

                continue;
            }

            if (is_string($value)) {
                $scrubbed = $this->scrubValue($value);
                if (trim($scrubbed) === '') { $dropped[$field] = 'empty'; continue; }
                if (mb_strlen($scrubbed) > AiContextSchema::MAX_VALUE_LENGTH) {
                    $truncated[] = $field;
                }
                $context[$field] = mb_substr($scrubbed, 0, AiContextSchema::MAX_VALUE_LENGTH);
                $kept[] = $field;

                continue;
            }

            $context[$field] = $value; // numbers and booleans survive as themselves
            $kept[] = $field;
        }

        // Anything passed that was never on the allowlist. Recorded, not silent.
        foreach (array_keys($input) as $field) {
            if (! in_array($field, $allowed, true) && ! array_key_exists($field, $dropped)) {
                $dropped[$field] = 'not_allowlisted';
            }
        }

        return ['context' => $context, 'report' => compact('kept', 'dropped', 'truncated')];
    }

    /**
     * Remove credentials from prose. Named patterns first so a recognised token
     * becomes something readable rather than an anonymous blob.
     */
    public function scrubValue(string $text): string
    {
        $out = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', self::REDACTED, $text);

        $out = preg_replace_callback(
            '/\b(bearer|token|secret|apikey|api_key)\b[\s:=]+[A-Za-z0-9._-]{12,}/i',
            fn (array $m) => $m[1].' '.self::REDACTED,
            $out,
        );

        $out = preg_replace(
            '/([?&](?:[^=&]*(?:token|secret|key|auth|sig|session|code|jwt)[^=&]*)=)[^&\s]+/i',
            '$1'.self::REDACTED,
            $out,
        );

        // Anything left resembling an opaque credential. Digit density is not used
        // here: inside prose a 24-character unbroken alphanumeric run is almost
        // never a word, and over-redacting a description costs far less than
        // leaking a key.
        return preg_replace_callback(
            '/\b[A-Za-z0-9_-]{24,}\b/',
            fn (array $m) => preg_match('/^[A-Za-z]+$/', $m[0]) ? $m[0] : self::REDACTED,
            $out,
        );
    }

    private function scrubStructured(array $value, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return [];
        }

        $out = [];

        foreach ($value as $key => $item) {
            if (is_string($key) && (
                preg_match(AiContextSchema::FORBIDDEN_KEY_PATTERN, $key)
                || preg_match(AiContextSchema::PII_KEY_PATTERN, $key)
                || preg_match(self::IDENTITY_KEY_PATTERN, $key)
            )) {
                continue;
            }

            if (is_array($item) || is_object($item)) {
                $out[$key] = $this->scrubStructured((array) $item, $depth + 1);
            } elseif (is_string($item)) {
                $out[$key] = mb_substr($this->scrubValue($item), 0, AiContextSchema::MAX_VALUE_LENGTH);
            } else {
                $out[$key] = $item;
            }
        }

        return $out;
    }
}

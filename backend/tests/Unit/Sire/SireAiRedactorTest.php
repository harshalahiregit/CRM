<?php

namespace Tests\Unit\Sire;

use Sire\AI\SireAiRedactor;
use Sire\Support\Ai\AiCapability;
use Sire\Support\Ai\AiContextSchema;
use Tests\TestCase;

/**
 * What SIRE sends to an AI provider, against the SAME fixture the JS reference
 * runs: implementation/fixtures/ai-redaction-cases.json
 *
 * "We redact secrets" is a claim. A claim about a security boundary should be
 * executable, and this is the file to point at when someone asks what leaves the
 * building.
 */
class SireAiRedactorTest extends TestCase
{
    private static function spec(): array
    {
        foreach ([
            __DIR__.'/../../../packages/sire/tests/fixtures/ai-redaction-cases.json',
            __DIR__.'/../../../packages/sire/tests/fixtures/ai-redaction-cases.json',
        ] as $path) {
            if (is_file($path)) {
                return json_decode(file_get_contents($path), true);
            }
        }

        self::fail('ai-redaction-cases.json not found. Vendor it per INTEGRATION.md.');
    }

    public static function caseProvider(): array
    {
        return collect(self::spec()['cases'])->mapWithKeys(fn (array $c) => [$c['name'] => [$c]])->all();
    }

    /** @dataProvider caseProvider */
    public function test_redaction_matches_the_shared_specification(array $case): void
    {
        $input = $this->expand($case['input']);
        $allowlist = array_key_exists('allowlist_override', $case)
            ? $this->expand($case['allowlist_override'])
            : null;

        ['context' => $context, 'report' => $report] =
            (new SireAiRedactor())->redact($case['capability'], $input, $allowlist);

        $e = $case['expect'];

        if (isset($e['kept'])) {
            $kept = $report['kept'];
            sort($kept);
            $expected = $e['kept'];
            sort($expected);
            $this->assertSame($expected, $kept, 'kept');
        }
        foreach ($e['dropped'] ?? [] as $field) {
            $this->assertArrayHasKey($field, $report['dropped'], "{$field} should be dropped");
        }
        foreach ($e['drop_reason'] ?? [] as $field => $reason) {
            $this->assertSame($reason, $report['dropped'][$field] ?? null, "{$field} reason");
        }
        if ($e['empty'] ?? false) {
            $this->assertSame([], $context);
        }
        if (isset($e['max_fields'])) {
            $this->assertLessThanOrEqual($e['max_fields'], count($report['kept']));
        }
        foreach ($e['truncated'] ?? [] as $field) {
            $this->assertContains($field, $report['truncated']);
        }
        // The fixture is shared with the JS reference, so it names types the way
        // JavaScript does. Translate to what get_debug_type() actually returns --
        // the old inline map handled 'number' only, and even that mapped to
        // 'integer', which get_debug_type has never returned.
        $phpType = ['number' => ['int', 'float'], 'boolean' => ['bool'], 'string' => ['string']];

        foreach ($e['types'] ?? [] as $field => $type) {
            $this->assertContains(
                get_debug_type($context[$field]),
                $phpType[$type] ?? [$type],
                "{$field} type",
            );
        }

        $serialised = json_encode($context);
        foreach ($e['value_contains_not'] ?? [] as $needle) {
            $this->assertStringNotContainsString($needle, $serialised, "leaked: {$needle}");
        }
        foreach ($e['value_contains'] ?? [] as $needle) {
            $this->assertStringContainsString($needle, $serialised, "missing: {$needle}");
        }
    }

    public function test_no_allowlist_anywhere_admits_a_secret_or_an_identity(): void
    {
        foreach (AiContextSchema::FIELDS as $capability => $fields) {
            $this->assertNotEmpty($fields, "{$capability} has an empty allowlist");

            foreach ($fields as $field) {
                $this->assertDoesNotMatchRegularExpression(
                    AiContextSchema::FORBIDDEN_KEY_PATTERN, $field,
                    "{$capability} allowlists a credential-shaped field: {$field}",
                );
                $this->assertDoesNotMatchRegularExpression(
                    AiContextSchema::PII_KEY_PATTERN, $field,
                    "{$capability} allowlists a person-identifying field: {$field}",
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/^(tenant_id|reporter_id|assignee_id|user_id|owner_id|created_by)$/', $field,
                    "{$capability} allowlists identity: {$field}",
                );
            }
        }
    }

    public function test_every_declared_capability_has_an_allowlist(): void
    {
        // A capability with no allowlist sends nothing — which is safe, but is
        // almost certainly an oversight rather than a decision.
        foreach (AiCapability::ALL as $capability) {
            $this->assertNotEmpty(
                AiContextSchema::fieldsFor($capability),
                "{$capability} is declared but has no context allowlist",
            );
        }
    }

    public function test_ordinary_prose_survives_but_credentials_do_not(): void
    {
        $redactor = new SireAiRedactor();

        $prose = 'Saving a lead returns a 500 after changing the owner on the details tab.';
        $this->assertSame($prose, $redactor->scrubValue($prose), 'over-redaction makes the context useless');

        $dirty = $redactor->scrubValue('Login as ops@example.com with token Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq3Ee5Rr then retry');
        $this->assertStringNotContainsString('ops@example.com', $dirty);
        $this->assertStringNotContainsString('Ab9xQ2Lm7Nb4Vc8Rt1Yu6Wq3Ee5Rr', $dirty);
        $this->assertStringContainsString('Login as', $dirty, 'the sentence should still read');
    }

    private function expand(mixed $value): mixed
    {
        if ($value === 'MANY:60') {
            return array_map(fn (int $i) => "f{$i}", range(0, 59));
        }
        if (is_array($value)) {
            if (array_is_list($value)) {
                return $value;
            }

            return collect($value)->map(fn ($v) => is_string($v) && str_starts_with($v, 'LONG:')
                ? str_repeat('x', (int) explode(':', $v)[1])
                : $v)->all();
        }

        return $value;
    }
}

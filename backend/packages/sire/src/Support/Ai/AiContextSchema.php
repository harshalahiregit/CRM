<?php

namespace Sire\Support\Ai;

/**
 * SIRE AI — what may leave the building.
 *
 * An ALLOWLIST PER CAPABILITY, not a blocklist. A blocklist asks "did we remember
 * to exclude this?" and is wrong the first time a new column appears; an allowlist
 * asks "is this one of the fields this capability needs?" and drops everything
 * else, including fields nobody has thought of yet.
 *
 * This is the file to read when someone asks what SIRE sends to an AI provider.
 * If a field is not named here, no provider ever sees it.
 */
final class AiContextSchema
{
    /**
     * capability => allowed field names on the subject.
     *
     * Fields are chosen by what the capability actually needs, not by what might
     * be handy. Severity recommendation gets the symptom and where it happened; it
     * does not get the assignee, the reporter, the fix, or anything about money.
     */
    public const FIELDS = [
        AiCapability::CLASSIFICATION => [
            'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result',
            'module', 'section', 'screen',
        ],
        AiCapability::SEVERITY_RECOMMENDATION => [
            'title', 'description', 'expected_result', 'actual_result',
            'module', 'section', 'screen', 'entity_type',
        ],
        AiCapability::PRIORITY_RECOMMENDATION => [
            'title', 'description', 'module', 'section', 'severity_code', 'is_regression',
            'reopen_count', 'occurrence_count',
        ],
        AiCapability::DUPLICATE_DETECTION => [
            'title', 'description', 'steps_to_reproduce', 'module', 'section', 'screen',
            'category_code',
        ],
        AiCapability::ROOT_CAUSE_SUGGESTION => [
            'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result',
            'module', 'section', 'screen', 'fix_summary', 'investigation_notes', 'category_code',
        ],
        AiCapability::DEVELOPER_TEST_CASES => [
            'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result',
            'fix_summary', 'module', 'section', 'screen',
        ],
        AiCapability::QA_TEST_CASES => [
            'title', 'description', 'steps_to_reproduce', 'expected_result', 'actual_result',
            'fix_summary', 'module', 'section', 'screen',
        ],
        AiCapability::REGRESSION_RISK => [
            'title', 'module', 'section', 'severity_code', 'is_regression', 'reopen_count',
            'fix_summary', 'root_cause_category',
        ],
        AiCapability::RECURRENCE_RISK => [
            'title', 'occurrence_count', 'average_interval_days', 'permanent_fix_status',
            'root_cause_category', 'module',
        ],
        AiCapability::KNOWLEDGE_RECOMMENDATION => [
            'title', 'description', 'module', 'section', 'screen', 'category_code',
        ],
        AiCapability::RELEASE_RISK => [
            'version', 'release_type', 'total_issues', 'open_critical', 'qa_failed',
            'regression_count', 'recurring_count',
        ],
        AiCapability::RELEASE_NOTE_REFINEMENT => [
            'version', 'release_type', 'sections',
        ],
        AiCapability::ENGINEERING_INSIGHTS => [
            'reopen_rate', 'qa_rejection_rate', 'regression_rate', 'recurrence_rate',
            'top_modules', 'trend',
        ],
    ];

    /**
     * Refused whatever the allowlist says. Defence in depth: if someone adds
     * `auth_token` to an allowlist by mistake, this still catches it.
     */
    public const FORBIDDEN_KEY_PATTERN =
        '/(password|passwd|pwd|token|secret|auth|cookie|session|credential|apikey|api_key|signature|bearer|jwt|private_key|access_key)/i';

    /**
     * Person-identifying keys, refused outright.
     *
     * No capability needs to know WHO. A severity recommendation does not improve
     * because it learns the reporter's name, and sending it to a third party is a
     * disclosure nobody consented to. Identity stays inside the tenant.
     */
    public const PII_KEY_PATTERN =
        '/(email|e_mail|phone|mobile|address|dob|birth|passport|national_id|full_name|first_name|last_name|reporter_name|assignee_name|user_name|username)/i';

    /** Longest single value sent. Long enough for a description, short enough to bound a payload. */
    public const MAX_VALUE_LENGTH = 4000;

    /** Most fields in one context, after filtering. */
    public const MAX_FIELDS = 40;

    public static function fieldsFor(string $capability): array
    {
        return self::FIELDS[$capability] ?? [];
    }
}

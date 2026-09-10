<?php

namespace Sire\Support;

/**
 * SIRE — the allowlist for client-supplied context.
 *
 * This is an ALLOWLIST, not a blocklist, and that is the whole point. A blocklist
 * asks "did the client send something forbidden?" and is wrong the first time a
 * new key appears. An allowlist asks "is this key one of the twenty we accept?"
 * and drops everything else, including keys nobody has thought of yet.
 *
 * Anything the client sends that is not named here never reaches the database:
 * cookies, Authorization headers, tokens, secrets, environment values, request
 * bodies, storage contents. There is no code path that persists an unlisted key.
 */
final class SireContextSchema
{
    /** Scalar context keys => max stored length. */
    public const SCALARS = [
        'module'             => 64,
        'section'            => 64,
        'screen'             => 96,
        'route'              => 255,
        'url'                => 1024,
        'entity_type'        => 64,
        'entity_id'          => 64,
        'browser'            => 64,
        'os'                 => 64,
        'viewport'           => 24,
        'locale'             => 16,
        'timezone'           => 64,
        'app_version'        => 64,
        'session_ref'        => 64,
        'context_source'     => 24,
        'context_confidence' => 16,
        'entity_source'      => 16,
        'captured_at'        => 40,
    ];

    /** Keys accepted inside each failed_requests entry. Nothing else is stored. */
    public const FAILED_REQUEST_KEYS = ['method', 'path', 'status', 'occurred_at', 'correlation_ref'];

    public const MAX_FAILED_REQUESTS   = 3;
    public const MAX_PAGE_CONTEXT_KEYS = 20;
    public const MAX_PAGE_VALUE_LENGTH = 200;

    /**
     * Refused as page-context keys whatever the client calls them. Belt and
     * braces: page_context is the only free-form surface, so it gets a blocklist
     * on top of the scalar-only rule.
     */
    public const FORBIDDEN_KEY_PATTERN =
        '/(password|passwd|pwd|token|secret|auth|cookie|session|credential|apikey|api_key|signature|bearer|jwt)/i';

    /** Query parameters redacted by name, case-insensitive substring match. */
    public const SENSITIVE_PARAM_HINTS = [
        'token', 'secret', 'password', 'passwd', 'pwd', 'auth', 'key', 'apikey',
        'api_key', 'signature', 'sig', 'session', 'code', 'otp', 'credential',
        'jwt', 'bearer', 'access', 'refresh',
    ];

    public const CONFIDENCE = ['high', 'medium', 'low'];
    public const SOURCES    = ['route', 'module-prefix', 'none', 'provider', 'user', 'dom', 'override'];
}

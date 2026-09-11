/**
 * SIRE AI redaction — the executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireAiRedactor.php; both run
 * fixtures/ai-redaction-cases.json.
 *
 * This is the boundary between a tenant's data and a third party. It gets a
 * decision table because "we redact secrets" is a claim, and a claim about a
 * security boundary should be executable.
 */

export const MAX_VALUE_LENGTH = 4000;
export const MAX_FIELDS = 40;

const FORBIDDEN_KEY = /(password|passwd|pwd|token|secret|auth|cookie|session|credential|apikey|api_key|signature|bearer|jwt|private_key|access_key)/i;
const PII_KEY = /(email|e_mail|phone|mobile|address|dob|birth|passport|national_id|full_name|first_name|last_name|reporter_name|assignee_name|user_name|username)/i;

/** Identity never leaves. No capability is improved by knowing who. */
const IDENTITY_KEY = /^(tenant_id|reporter_id|assignee_id|user_id|owner_id|created_by|updated_by|authorized_by|qa_assignee_id)$/i;

/** Fields allowed to be arrays. Everything else must be a scalar. */
export const STRUCTURED_FIELDS = new Set(['sections', 'top_modules', 'trend', 'contributing_factors', 'five_whys']);

const REDACTED = '[redacted]';
const MAX_DEPTH = 3;

// ---------------------------------------------------------------- value scrub

const EMAIL = /[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/g;
const BEARER = /\b(bearer|token|secret|apikey|api_key)\b[\s:=]+[A-Za-z0-9._-]{12,}/gi;
const URL_QUERY_SECRET = /([?&](?:[^=&]*(?:token|secret|key|auth|sig|session|code|jwt)[^=&]*)=)[^&\s]+/gi;
const LONE_SECRET = /\b[A-Za-z0-9_-]{24,}\b/g;

/**
 * Scrub a single string. Order matters: named patterns first, then the blunt
 * high-entropy sweep, so a recognised token is replaced with something readable
 * rather than an anonymous blob.
 */
export function scrubValue(text) {
  if (typeof text !== 'string') return text;

  let out = text
    .replace(EMAIL, REDACTED)
    .replace(BEARER, (m) => m.split(/[\s:=]+/)[0] + ' ' + REDACTED)
    .replace(URL_QUERY_SECRET, `$1${REDACTED}`);

  // Anything left that looks like an opaque credential. Digit-density is not used
  // here: inside prose, a 24-character unbroken alphanumeric run is almost never
  // a word, and over-redacting a description costs far less than leaking a key.
  out = out.replace(LONE_SECRET, (m) => (/^[A-Za-z]+$/.test(m) ? m : REDACTED));

  return out;
}

function scrubStructured(value, depth = 0) {
  if (depth > MAX_DEPTH) return null;

  if (Array.isArray(value)) {
    return value.map((v) => scrubStructured(v, depth + 1)).filter((v) => v !== null);
  }
  if (value && typeof value === 'object') {
    const out = {};
    for (const [k, v] of Object.entries(value)) {
      if (FORBIDDEN_KEY.test(k) || PII_KEY.test(k) || IDENTITY_KEY.test(k)) continue;
      const scrubbed = scrubStructured(v, depth + 1);
      if (scrubbed !== null && scrubbed !== undefined) out[k] = scrubbed;
    }
    return out;
  }
  if (typeof value === 'string') return scrubValue(value).slice(0, MAX_VALUE_LENGTH);

  return value;
}

// -------------------------------------------------------------------- redact

/**
 * @returns {{context: object, report: {kept: string[], dropped: object, truncated: string[]}}}
 */
export function redact(capability, input, allowlist) {
  const allowed = allowlist ?? [];
  const context = {};
  const kept = [];
  const dropped = {};
  const truncated = [];

  // An unknown capability has no allowlist, so nothing is sent. Failing closed
  // matters most exactly where someone has made a mistake.
  for (const field of allowed) {
    if (kept.length >= MAX_FIELDS) {
      dropped[field] = 'field_cap';
      continue;
    }
    if (!(field in (input ?? {}))) continue;

    if (IDENTITY_KEY.test(field)) { dropped[field] = 'identity_key'; continue; }
    if (FORBIDDEN_KEY.test(field)) { dropped[field] = 'forbidden_key'; continue; }
    if (PII_KEY.test(field)) { dropped[field] = 'pii_key'; continue; }

    const value = input[field];

    if (value === null || value === undefined) { dropped[field] = 'empty'; continue; }

    if (Array.isArray(value) || (value && typeof value === 'object')) {
      if (!STRUCTURED_FIELDS.has(field)) { dropped[field] = 'not_scalar'; continue; }
      context[field] = scrubStructured(value);
      kept.push(field);
      continue;
    }

    if (typeof value === 'string') {
      const scrubbed = scrubValue(value);
      if (scrubbed.trim() === '') { dropped[field] = 'empty'; continue; }
      if (scrubbed.length > MAX_VALUE_LENGTH) truncated.push(field);
      context[field] = scrubbed.slice(0, MAX_VALUE_LENGTH);
      kept.push(field);
      continue;
    }

    context[field] = value; // numbers and booleans survive as themselves
    kept.push(field);
  }

  // Anything the caller passed that was never on the allowlist is dropped without
  // ceremony — that is the allowlist doing its job.
  for (const field of Object.keys(input ?? {})) {
    if (!allowed.includes(field) && !(field in dropped)) dropped[field] = 'not_allowlisted';
  }

  return { context, report: { kept, dropped, truncated } };
}

/**
 * SIRE — client-side redaction.
 *
 * The page URL is captured verbatim by nobody: this CRM has token-bearing URLs
 * (public ticket views '{id}-{email_token}', offer / onboarding / checklist /
 * proposal portal tokens, per-tenant Helpdesk widget keys). A URL is not a safe
 * string in this codebase.
 *
 * The server redacts again on receipt — this is defence in depth, not the only
 * line. Never treat client-side redaction as sufficient.
 */

/** Query params dropped by name, case-insensitive, substring match. */
const SENSITIVE_PARAM_HINTS = [
  'token', 'secret', 'password', 'passwd', 'pwd', 'auth', 'key', 'apikey',
  'api_key', 'signature', 'sig', 'session', 'code', 'otp', 'credential', 'jwt',
  'bearer', 'access', 'refresh',
];

const REDACTED = '[redacted]';

/**
 * Shortest credential we defend against. Real keys in this CRM sit in the 16-32
 * range: a 20-hex widget key is 23 characters with the 'wk_' prefix, which an
 * earlier 24-character threshold let through. Caught by redact.test.mjs.
 */
const MIN_SECRET_LENGTH = 16;
const LONG_SECRET_LENGTH = 32;

/**
 * A value is treated as a credential when it is long, opaque-charactered, not a
 * plain number, and either very long or digit-dense. The digit-density test is
 * what separates 'wk_9f2b71c4a8e34d15b0c7' (52% digits -> secret) from a human
 * slug like 'annual-safety-review-2026' (16% digits -> kept).
 *
 * Over-redacting costs a little diagnostic context. Under-redacting puts a live
 * token in a database row that support staff can read. Bias accordingly.
 */
function looksLikeSecret(value) {
  if (typeof value !== 'string') return false;
  if (value.length < MIN_SECRET_LENGTH) return false;
  if (!/^[A-Za-z0-9_\-.]+$/.test(value)) return false;
  if (/^\d+$/.test(value)) return false; // a long numeric id is not a secret
  if (value.length >= LONG_SECRET_LENGTH) return true;
  const digits = (value.match(/\d/g) || []).length;
  return digits / value.length >= 0.25;
}

export function redactQueryString(search) {
  if (!search) return '';
  const raw = search.startsWith('?') ? search.slice(1) : search;
  if (!raw) return '';

  const out = [];
  for (const pair of raw.split('&')) {
    if (!pair) continue;
    const idx = pair.indexOf('=');
    const key = idx === -1 ? pair : pair.slice(0, idx);
    const value = idx === -1 ? '' : pair.slice(idx + 1);
    const lower = key.toLowerCase();

    if (SENSITIVE_PARAM_HINTS.some((hint) => lower.includes(hint)) || looksLikeSecret(value)) {
      out.push(`${key}=${REDACTED}`);
    } else {
      out.push(idx === -1 ? key : `${key}=${value}`);
    }
  }
  return out.length ? `?${out.join('&')}` : '';
}

export function redactPath(pathname) {
  if (!pathname) return '';
  return String(pathname)
    .split('/')
    .map((seg) => {
      if (!seg) return seg;
      // '12345-Ab9xQ...' — the public ticket '{id}-{email_token}' shape.
      const composite = seg.match(/^(\d+)-([A-Za-z0-9_-]{16,})$/);
      if (composite) return `${composite[1]}-:token`;
      return looksLikeSecret(seg) ? ':token' : seg;
    })
    .join('/');
}

/** Full location -> safe display URL. Fragments are dropped entirely. */
export function redactUrl(href) {
  if (!href) return null;
  try {
    const url = new URL(href, 'http://local.invalid');
    const path = redactPath(url.pathname);
    const query = redactQueryString(url.search);
    const origin = url.origin === 'http://local.invalid' ? '' : url.origin;
    return `${origin}${path}${query}`;
  } catch {
    return redactPath(String(href).split('?')[0]);
  }
}

/**
 * SIRE — environment snapshot. Read on demand only; nothing here runs at import
 * time, nothing polls, nothing subscribes to resize.
 *
 * Coarse on purpose: browser family + major version, OS family, viewport size.
 * Enough to reproduce a layout bug, not enough to fingerprint a user.
 */

const BROWSERS = [
  [/Edg\/(\d+)/, 'Edge'],
  [/OPR\/(\d+)/, 'Opera'],
  [/Chrome\/(\d+)/, 'Chrome'],
  [/Firefox\/(\d+)/, 'Firefox'],
  [/Version\/(\d+).*Safari/, 'Safari'],
];

const OSES = [
  [/Windows NT 10/, 'Windows 10/11'],
  [/Windows NT/, 'Windows'],
  [/Mac OS X/, 'macOS'],
  [/Android/, 'Android'],
  [/(iPhone|iPad|iPod)/, 'iOS'],
  [/Linux/, 'Linux'],
];

function parseBrowser(ua) {
  for (const [re, name] of BROWSERS) {
    const m = ua.match(re);
    if (m) return `${name} ${m[1]}`;
  }
  return 'Unknown';
}

function parseOs(ua) {
  // Prefer the low-entropy Client Hints value when the browser offers it; it is
  // the sanctioned surface and needs no permission. High-entropy hints are async
  // and deliberately not requested.
  const hinted = typeof navigator !== 'undefined' && navigator.userAgentData?.platform;
  if (hinted) return hinted;
  for (const [re, name] of OSES) if (re.test(ua)) return name;
  return 'Unknown';
}

/** Resolved once per page load, not per collect(). */
let sessionRef = null;
export function getSessionRef() {
  if (sessionRef) return sessionRef;
  try {
    sessionRef = globalThis.crypto?.randomUUID
      ? globalThis.crypto.randomUUID()
      : `s-${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
  } catch {
    sessionRef = null;
  }
  return sessionRef;
}

export function appVersion() {
  try {
    // Vite: define VITE_APP_VERSION at build time. Falls back rather than throwing.
    return import.meta.env?.VITE_APP_VERSION || import.meta.env?.MODE || 'unknown';
  } catch {
    return 'unknown';
  }
}

export function collectEnvironment() {
  if (typeof window === 'undefined') return {};
  const ua = navigator.userAgent || '';
  return {
    browser: parseBrowser(ua),
    os: parseOs(ua),
    viewport: `${window.innerWidth}x${window.innerHeight}`,
    device_pixel_ratio: window.devicePixelRatio || 1,
    locale: navigator.language || null,
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || null,
    online: navigator.onLine !== false,
    app_version: appVersion(),
    session_ref: getSessionRef(),
  };
}

/**
 * SIRE — one visual vocabulary.
 *
 * Before this file, thirty-four components each defined their own colour map.
 * That is what makes a module feel bolted on rather than native: the same
 * "critical" is a slightly different red in the list, the detail header and the
 * release board, and nobody notices until they are side by side.
 *
 * Everything that carries urgency — status, severity, priority, SLA, risk, test
 * result — resolves through here. `tests/tokens.test.mjs` fails the build if a
 * SIRE component defines a colour map of its own.
 *
 * TWO RULES THIS FILE ENFORCES
 *
 *   1. URGENCY IS NEVER ENCODED IN COLOUR ALONE. Anything at urgency 2 or above
 *      carries a marker glyph and heavier weight, so it survives a colour-blind
 *      reader, a greyscale print, and a dense table skimmed at speed.
 *
 *   2. THE CRM'S PALETTE, NOT A NEW ONE. These are the Tailwind families the rest
 *      of the CRM already uses. SIRE introduces no new colour, no gradient and no
 *      animation — a defect tracker that draws the eye more than the work does is
 *      a defect tracker people close.
 */

/** Semantic tones. The only place a Tailwind colour family appears. */
export const TONE = {
  neutral:  'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-800 dark:text-gray-300 dark:ring-gray-700',
  muted:    'bg-gray-50 text-gray-500 ring-gray-200 dark:bg-gray-900 dark:text-gray-400 dark:ring-gray-800',
  info:     'bg-blue-50 text-blue-700 ring-blue-200 dark:bg-blue-950 dark:text-blue-200 dark:ring-blue-800',
  progress: 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-950 dark:text-violet-200 dark:ring-violet-800',
  caution:  'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800',
  danger:   'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-200 dark:ring-red-800',
  success:  'bg-green-50 text-green-700 ring-green-200 dark:bg-green-950 dark:text-green-200 dark:ring-green-800',
};

/**
 * Urgency 0–3. Two and above must be readable without colour.
 *
 *   0  nothing to see
 *   1  worth noticing
 *   2  needs attention          → marker + medium weight
 *   3  needs attention now      → marker + bold
 */
export const URGENCY = { NONE: 0, LOW: 1, HIGH: 2, CRITICAL: 3 };

/** The non-colour half of the encoding. Deliberately typographic, not iconography. */
const MARKER = { 2: '▲', 3: '●' };
const WEIGHT = { 0: 'font-normal', 1: 'font-normal', 2: 'font-medium', 3: 'font-semibold' };

const token = (label, tone, urgency = URGENCY.NONE) => ({
  label,
  tone,
  urgency,
  classes: TONE[tone],
  marker: MARKER[urgency] ?? null,
  weight: WEIGHT[urgency],
});

// --------------------------------------------------------------------- status

/**
 * Workflow status. Tones come from the generated workflow mirror so the machine
 * and the palette cannot disagree; urgency is assigned here because it is a
 * presentation judgement, not part of the state machine.
 */
const STATUS_URGENCY = {
  qa_failed: URGENCY.CRITICAL,
  reopened: URGENCY.HIGH,
  on_hold: URGENCY.HIGH,
  new: URGENCY.LOW,
  approval: URGENCY.LOW,
};

export function statusToken(status, workflowStates = {}) {
  const meta = workflowStates[status];

  if (!meta) return token(String(status ?? '—'), 'neutral');

  const tone = {
    slate: 'neutral', gray: 'muted', blue: 'info', violet: 'progress',
    amber: 'caution', orange: 'caution', red: 'danger', green: 'success',
  }[meta.tone] ?? 'neutral';

  return token(meta.label, tone, STATUS_URGENCY[status] ?? URGENCY.NONE);
}

// ------------------------------------------------------------------- severity

/**
 * Severity is TENANT-CONFIGURABLE — codes and counts vary — so urgency is derived
 * from a severity's position in that tenant's own scale rather than from a
 * hardcoded list of names.
 *
 * The top level is always critical-urgency and always carries a marker, whether a
 * workspace calls it "Critical", "Sev 1" or "Blocker".
 */
export function severityToken(severity, allLevels = []) {
  if (!severity) return token('—', 'muted');

  const level = Number(severity.level ?? 0);
  const max = Math.max(level, ...allLevels.map(Number).filter(Number.isFinite), 1);

  const position = max > 0 ? level / max : 0;

  if (position >= 1) return token(severity.name, 'danger', URGENCY.CRITICAL);
  if (position >= 0.75) return token(severity.name, 'caution', URGENCY.HIGH);
  if (position >= 0.4) return token(severity.name, 'info', URGENCY.LOW);

  return token(severity.name, 'muted', URGENCY.NONE);
}

// ------------------------------------------------------------------- priority

export const PRIORITY = {
  p1: token('P1 — Urgent', 'danger', URGENCY.CRITICAL),
  p2: token('P2 — High', 'caution', URGENCY.HIGH),
  p3: token('P3 — Medium', 'neutral', URGENCY.LOW),
  p4: token('P4 — Low', 'muted', URGENCY.NONE),
};

export const priorityToken = (priority) => PRIORITY[priority] ?? token('—', 'muted');

// ------------------------------------------------------------------------ sla

export const SLA = {
  on_track: token('On track', 'success', URGENCY.NONE),
  warning:  token('Warning', 'caution', URGENCY.HIGH),
  breached: token('Breached', 'danger', URGENCY.CRITICAL),
  paused:   token('Paused', 'muted', URGENCY.NONE),
};

export const slaToken = (state) => SLA[state] ?? token('—', 'muted');

// ----------------------------------------------------------------------- risk

export const RISK = {
  low:      token('Low', 'success', URGENCY.NONE),
  medium:   token('Medium', 'caution', URGENCY.LOW),
  high:     token('High', 'danger', URGENCY.HIGH),
  critical: token('Critical', 'danger', URGENCY.CRITICAL),
};

export const riskToken = (level) => RISK[level] ?? token('—', 'muted');

// ---------------------------------------------------------------- test result

export const TEST_RESULT = {
  passed:  token('Passed', 'success'),
  failed:  token('Failed', 'danger', URGENCY.CRITICAL),
  blocked: token('Blocked', 'caution', URGENCY.HIGH),
  skipped: token('Skipped', 'muted'),
};

export const testResultToken = (result) =>
  result ? (TEST_RESULT[result] ?? token(result, 'muted')) : token('Not run', 'neutral', URGENCY.LOW);

// ----------------------------------------------------------- duplicate match

export const SIMILARITY = {
  very_likely: token('Very likely', 'danger', URGENCY.HIGH),
  likely:      token('Likely', 'caution', URGENCY.LOW),
  possible:    token('Possible', 'neutral'),
};

export const similarityToken = (label) => SIMILARITY[label] ?? token('—', 'muted');

// ------------------------------------------------------------- release gates

/**
 * Five outcomes, and the distinctions matter enough to have their own tones:
 * `skipped` (nobody checked) must not look like `pass` (checked, satisfied), and
 * `unknown` (this build cannot evaluate it) blocks, so it reads as a failure.
 */
export const GATE = {
  pass:       token('Pass', 'success'),
  fail:       token('Fail', 'danger', URGENCY.HIGH),
  skipped:    token('Disabled', 'muted'),
  overridden: token('Overridden', 'caution', URGENCY.HIGH),
  unknown:    token('Unknown gate', 'danger', URGENCY.HIGH),
};

export const gateToken = (status) => GATE[status] ?? GATE.unknown;

/** Glyphs for the gate list, where a column of chips would be heavier than a column of marks. */
export const GATE_GLYPH = { pass: '✓', fail: '✕', skipped: '–', overridden: '!', unknown: '?' };

// -------------------------------------------------------------- release state

export const RELEASE_STATE = {
  blocked:     token('Blocked', 'danger', URGENCY.HIGH),
  ready:       token('Ready', 'success'),
  approved:    token('Approved', 'info'),
  released:    token('Released', 'muted'),
  cancelled:   token('Cancelled', 'muted'),
  rolled_back: token('Rolled back', 'caution', URGENCY.HIGH),
};

export const releaseStateToken = (status) => RELEASE_STATE[status] ?? token(String(status ?? '—'), 'muted');

// -------------------------------------------------------------------- helpers

/** Chip classes for any token. One shape everywhere SIRE shows a state. */
export function chipClasses(t, size = 'md') {
  const pad = size === 'sm' ? 'px-1.5 py-0.5 text-[10px]' : 'px-2 py-0.5 text-[11px]';

  return `inline-flex items-center gap-1 rounded-full ring-1 ring-inset ${pad} ${t.classes} ${t.weight}`;
}

/**
 * A row that needs to stand out in a dense list. Used as a left border rather
 * than a background wash: a table of forty rows with six of them tinted red is
 * harder to read than one with six marked.
 */
export function rowAccent(t) {
  if (t.urgency >= URGENCY.CRITICAL) return 'border-l-2 border-red-500';
  if (t.urgency >= URGENCY.HIGH) return 'border-l-2 border-amber-400';

  return 'border-l-2 border-transparent';
}

/** Every token this module can produce, for the completeness tests. */
export const ALL_TOKEN_SETS = { PRIORITY, SLA, RISK, TEST_RESULT, RELEASE_STATE, SIMILARITY, GATE };

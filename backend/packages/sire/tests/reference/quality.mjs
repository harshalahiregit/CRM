/**
 * SIRE quality metrics — the executable specification.
 *
 * NOT APPLICATION CODE. Lives under tests/ so nobody imports it into the SPA.
 * The real implementations are:
 *   src/Services/SireRecurrenceService.php  (risk)
 *   src/Services/SireQualityMetricsService.php (rates)
 *
 * Both sides run fixtures/recurrence-risk-cases.json and
 * fixtures/quality-metrics-cases.json. If they disagree, the fixture arbitrates.
 */

// ---------------------------------------------------------------- recurrence

export const RISK = { LOW: 'low', MEDIUM: 'medium', HIGH: 'high', CRITICAL: 'critical' };

const RECENT_DAYS = 30;

const daysBetween = (from, to) => (Date.parse(to) - Date.parse(from)) / 86400000;

function occurrenceScore(count) {
  if (count >= 10) return 4;
  if (count >= 5) return 3;
  if (count >= 3) return 2;
  if (count >= 2) return 1;
  return 0;
}

function intervalScore(days) {
  // Unknown interval scores 0 rather than worst-case. A group with one gap not
  // yet measured is not evidence of frequency, and guessing high here would page
  // someone about a pattern that may not exist.
  if (days === null || days === undefined) return 0;
  if (days < 7) return 3;
  if (days < 30) return 2;
  if (days < 90) return 1;
  return 0;
}

const FIX_SCORE = { none: 2, planned: 1, in_progress: 1, shipped: 0, verified: -1 };

/**
 * @returns {{score:number, risk:string, forced_low:boolean, not_recurring:boolean}}
 */
export function recurrenceRisk(group, now) {
  const count = group.occurrence_count ?? 0;

  // One occurrence is an issue, not a pattern.
  if (count < 2) {
    return { score: 0, risk: RISK.LOW, forced_low: false, not_recurring: true };
  }

  const recent = group.latest_occurrence_at !== null
    && group.latest_occurrence_at !== undefined
    && daysBetween(group.latest_occurrence_at, now) <= RECENT_DAYS;

  const score = occurrenceScore(count)
    + intervalScore(group.average_interval_days)
    + (FIX_SCORE[group.permanent_fix_status] ?? 0)
    + (recent ? 1 : 0);

  // A verified permanent fix with nothing since it landed is closed business.
  // But if it has happened AGAIN since, the fix did not work and the override
  // must not fire — that is precisely the case worth escalating.
  const forcedLow = group.permanent_fix_status === 'verified' && !recent;
  if (forcedLow) {
    return { score, risk: RISK.LOW, forced_low: true, not_recurring: false };
  }

  let risk = RISK.LOW;
  if (score >= 7) risk = RISK.CRITICAL;
  else if (score >= 5) risk = RISK.HIGH;
  else if (score >= 3) risk = RISK.MEDIUM;

  return { score, risk, forced_low: false, not_recurring: false };
}

/** Mean gap in days across a sorted list of occurrence timestamps. */
export function averageIntervalDays(timestamps) {
  if (!Array.isArray(timestamps) || timestamps.length < 2) return null;
  const sorted = [...timestamps].map(Date.parse).sort((a, b) => a - b);
  let total = 0;
  for (let i = 1; i < sorted.length; i += 1) total += sorted[i] - sorted[i - 1];
  return Number((total / (sorted.length - 1) / 86400000).toFixed(2));
}

// --------------------------------------------------------------------- rates

/** Below this, a percentage says more about the sample than the team. */
export const LOW_CONFIDENCE_SAMPLE = 5;

/**
 * One rate.
 *
 * A zero denominator is NO DATA, not zero percent. "0%" reads as perfect; "—"
 * reads as "we have not measured that yet", and only one of those is true.
 */
export function rate(numerator, denominator) {
  if (!denominator || denominator <= 0) {
    return { value: null, display: '—', sample: denominator ?? 0, suspect: false, low_confidence: false };
  }

  const raw = numerator / denominator;
  // A numerator above its denominator means the two were counted over different
  // sets. Clamp so the UI stays sane, but flag it rather than hide it.
  const suspect = numerator > denominator;
  const value = suspect ? 1 : raw;

  const percent = value * 100;
  const display = `${Number.isInteger(percent) ? percent : Number(percent.toFixed(1))}%`;

  return {
    value: Number(value.toFixed(4)),
    display,
    sample: denominator,
    suspect,
    low_confidence: denominator < LOW_CONFIDENCE_SAMPLE,
  };
}

export const METRICS = {
  reopen_rate:        (i) => rate(i.reopened, i.resolved),
  qa_rejection_rate:  (i) => rate(i.qa_failed_cycles, i.qa_cycles),
  regression_rate:    (i) => rate(i.regressions, i.created),
  recurrence_rate:    (i) => rate(i.recurring, i.created),
};

export function computeMetric(name, input) {
  const fn = METRICS[name];
  if (!fn) throw new Error(`unknown metric: ${name}`);
  return fn(input);
}

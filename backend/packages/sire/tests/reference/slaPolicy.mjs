/**
 * SIRE SLA — the executable specification.
 *
 * THIS IS NOT APPLICATION CODE. It lives under tests/ so nobody imports it into
 * the SPA by accident. The real implementation is
 * src/Services/SireSlaService.php; this exists so the rules can be
 * executed and asserted here, and so the PHP has an unambiguous thing to match.
 *
 * Both consume fixtures/sla-cases.json. If they disagree, the fixture says which
 * one is wrong.
 */

export const SLA_STATE = {
  ON_TRACK: 'on_track',
  WARNING: 'warning',
  BREACHED: 'breached',
  PAUSED: 'paused',
};

const TERMINAL = ['closed', 'duplicate', 'rejected', 'wont_fix', 'cannot_reproduce'];

const minutesBetween = (from, to) => Math.floor((Date.parse(to) - Date.parse(from)) / 60000);

/**
 * Pick the policy that governs this report.
 *
 * A policy matches when EVERY key in its `match` object equals the report's value.
 * Specificity is the number of keys matched; the most specific wins, and an exact
 * tie resolves to whichever appears first in the list. An empty `match` is the
 * catch-all — specificity 0, so anything else beats it.
 *
 * Order in the list is therefore only a tie-breaker, not the primary rule. That
 * matters: a tenant reordering their policies must not silently change which one
 * applies.
 */
export function resolvePolicy(policies, subject) {
  let best = null;
  let bestScore = -1;

  policies.forEach((policy, index) => {
    const match = policy.match ?? {};
    const keys = Object.keys(match);

    for (const key of keys) {
      if (subject[key] !== match[key]) return;
    }
    if (keys.length > bestScore) {
      bestScore = keys.length;
      best = { ...policy, index };
    }
  });

  return best;
}

/**
 * Resolve both targets. Order: an explicit policy, then the severity row's own
 * targets, then no SLA. A policy that matches with a null target disables that
 * clock deliberately — "enhancements have no resolution SLA" is a real answer,
 * and it must not silently fall through to the severity default.
 */
export function resolveTargets(policies, severity, report) {
  // The match subject is assembled here, not taken from the report: severity is a
  // separate row, and a matcher reading report.severity silently matches nothing.
  const subject = {
    type: report.type ?? null,
    priority: report.priority ?? null,
    severity: severity?.code ?? null,
  };
  const policy = resolvePolicy(policies, subject);

  if (policy) {
    return {
      source: 'policy',
      matched_index: policy.index,
      ack: policy.ack_minutes ?? null,
      resolve: policy.resolve_minutes ?? null,
    };
  }

  const ack = severity?.ack_target_minutes ?? null;
  const resolve = severity?.resolve_target_minutes ?? null;

  if (ack === null && resolve === null) {
    return { source: 'none', matched_index: null, ack: null, resolve: null };
  }

  return { source: 'severity', matched_index: null, ack, resolve };
}

/**
 * One clock.
 *
 * elapsed = (stoppedAt ?? now) - startedAt - pausedMinutes
 *
 * Paused time is read from two accumulator columns maintained by the workflow
 * service, not recomputed from the audit trail. Walking history on every list row
 * would put an audit query behind every dashboard tile.
 */
export function computeClock({ targetMinutes, startedAt, stoppedAt, now, pausedMinutes = 0, pausedSince = null, isPaused = false, warningThreshold = 0.8 }) {
  if (targetMinutes === null || targetMinutes === undefined) {
    return { state: null, target_minutes: null, elapsed_minutes: null, deadline: null, stopped: Boolean(stoppedAt), met: null };
  }

  const stopped = Boolean(stoppedAt);
  const end = stoppedAt ?? now;

  // An open pause is bounded by the moment the clock stopped. Discarding it
  // entirely (the first attempt) charged the team for time the issue was parked;
  // ignoring the bound would accrue pause forever after closure. Both are wrong.
  const pauseEnd = stopped ? stoppedAt : now;
  const openPause = pausedSince ? minutesBetween(pausedSince, pauseEnd) : 0;
  const totalPaused = (pausedMinutes ?? 0) + Math.max(0, openPause);

  const elapsed = Math.max(0, minutesBetween(startedAt, end) - totalPaused);
  const deadline = new Date(Date.parse(startedAt) + (targetMinutes + totalPaused) * 60000).toISOString();

  if (stopped) {
    const met = elapsed < targetMinutes;
    return {
      state: met ? SLA_STATE.ON_TRACK : SLA_STATE.BREACHED,
      target_minutes: targetMinutes, elapsed_minutes: elapsed, deadline, stopped: true, met,
    };
  }

  // A breach that has already happened is reported as breached even while paused —
  // pausing after the fact does not un-breach anything.
  if (elapsed >= targetMinutes) {
    return { state: SLA_STATE.BREACHED, target_minutes: targetMinutes, elapsed_minutes: elapsed, deadline, stopped: false, met: false };
  }
  if (isPaused) {
    return { state: SLA_STATE.PAUSED, target_minutes: targetMinutes, elapsed_minutes: elapsed, deadline, stopped: false, met: null };
  }
  if (elapsed >= targetMinutes * warningThreshold) {
    return { state: SLA_STATE.WARNING, target_minutes: targetMinutes, elapsed_minutes: elapsed, deadline, stopped: false, met: null };
  }

  return { state: SLA_STATE.ON_TRACK, target_minutes: targetMinutes, elapsed_minutes: elapsed, deadline, stopped: false, met: null };
}

/** Both clocks for one report. */
export function computeSla({ policies, severity, report, now, warningThreshold = 0.8, pauseStates = [] }) {
  const targets = resolveTargets(policies, severity, report);
  const isTerminal = TERMINAL.includes(report.status);
  const isPaused = !isTerminal && pauseStates.includes(report.status);

  const common = {
    now,
    pausedMinutes: report.sla_paused_minutes ?? 0,
    pausedSince: report.sla_paused_since ?? null,
    isPaused,
    warningThreshold,
  };

  return {
    source: targets.source,
    matched_index: targets.matched_index,
    // The acknowledgement clock stops the moment the team looked at it.
    ack: computeClock({ ...common, targetMinutes: targets.ack, startedAt: report.sla_started_at, stoppedAt: report.acknowledged_at ?? null }),
    // The resolution clock stops on any terminal status, not only 'closed'.
    resolve: computeClock({ ...common, targetMinutes: targets.resolve, startedAt: report.sla_started_at, stoppedAt: isTerminal ? (report.closed_at ?? now) : null }),
  };
}

/** The worst of the two clocks — what a badge or a dashboard tile shows. */
export function overallState(sla) {
  const rank = { breached: 3, warning: 2, paused: 1, on_track: 0 };
  const states = [sla.ack?.state, sla.resolve?.state].filter(Boolean);
  if (states.length === 0) return null;

  return states.reduce((worst, s) => (rank[s] > rank[worst] ? s : worst), states[0]);
}

/**
 * SIRE release gates — the executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireReleaseGateService.php; both run
 * fixtures/release-gates-cases.json.
 *
 * A gate engine earns this treatment because its failure mode is silent and
 * expensive. A gate that quietly passes lets a broken release ship, and nobody
 * finds out from the dashboard — it says READY.
 */

export const GATE_STATUS = {
  PASS: 'pass',
  FAIL: 'fail',
  SKIPPED: 'skipped',      // gate disabled by configuration
  OVERRIDDEN: 'overridden', // failed, but an authorised override covers it
  UNKNOWN: 'unknown',       // configured key this build does not implement
};

export const RELEASE_GATE_STATE = { READY: 'ready', BLOCKED: 'blocked' };

export const DEFAULT_GATES = [
  { key: 'no_open_critical',            enabled: true, blocking: true, config: { severities: ['critical'] } },
  { key: 'qa_failures_resolved',        enabled: true, blocking: true },
  { key: 'approvals_complete',          enabled: true, blocking: true },
  { key: 'regression_testing_complete', enabled: true, blocking: true },
];

/**
 * Each evaluator answers one question about a release snapshot and returns
 * { ok, detail, count }. They never read configuration they were not given and
 * never look outside the snapshot — that is what makes them testable.
 */
const EVALUATORS = {
  no_open_critical(snapshot, config) {
    const severities = config?.severities ?? ['critical'];

    // The snapshot may carry a per-severity breakdown; fall back to the plain
    // critical count when it does not, so an older caller still works.
    const count = snapshot.open_by_severity
      ? severities.reduce((sum, s) => sum + (snapshot.open_by_severity[s] ?? 0), 0)
      : (snapshot.open_critical ?? 0);

    return {
      ok: count === 0,
      count,
      detail: count === 0
        ? `No open ${severities.join('/')} issues`
        : `${count} open ${severities.join('/')} issue${count === 1 ? '' : 's'}`,
    };
  },

  qa_failures_resolved(snapshot) {
    const count = snapshot.qa_failed ?? 0;
    return {
      ok: count === 0,
      count,
      detail: count === 0 ? 'No unresolved QA failures' : `${count} issue${count === 1 ? '' : 's'} sitting in QA Failed`,
    };
  },

  approvals_complete(snapshot) {
    const count = snapshot.pending_approvals ?? 0;
    return {
      ok: count === 0,
      count,
      detail: count === 0 ? 'All approvals decided' : `${count} approval${count === 1 ? '' : 's'} still pending`,
    };
  },

  regression_testing_complete(snapshot) {
    const required = snapshot.regression_required ?? 0;
    const tested = snapshot.regression_tested ?? 0;
    const outstanding = Math.max(0, required - tested);

    // Nothing to test is a pass, not a division by zero and not a failure.
    return {
      ok: outstanding === 0,
      count: outstanding,
      detail: required === 0
        ? 'No regression testing required'
        : `${tested}/${required} regression tests complete`,
    };
  },
};

/**
 * @param {object[]|null} gates     configured gates; null = DEFAULT_GATES
 * @param {object} snapshot         counts for one release
 * @param {{gates: string[]}|null} override  an authorised emergency override
 */
export function evaluateGates(gates, snapshot, override = null) {
  const configured = gates ?? DEFAULT_GATES;
  const overridden = new Set(override?.gates ?? []);

  const results = configured.map((gate) => {
    const blocking = gate.blocking !== false;

    if (gate.enabled === false) {
      return { key: gate.key, status: GATE_STATUS.SKIPPED, blocking, detail: 'Disabled in settings', count: 0 };
    }

    const evaluator = EVALUATORS[gate.key];
    if (!evaluator) {
      // A configured gate this build cannot evaluate must BLOCK, not pass. The
      // alternative is a typo in settings silently disabling governance.
      return {
        key: gate.key,
        status: GATE_STATUS.UNKNOWN,
        blocking,
        detail: 'Unknown gate — this build cannot evaluate it',
        count: 0,
      };
    }

    const { ok, detail, count } = evaluator(snapshot, gate.config);

    if (ok) {
      return { key: gate.key, status: GATE_STATUS.PASS, blocking, detail, count };
    }
    if (overridden.has(gate.key)) {
      return { key: gate.key, status: GATE_STATUS.OVERRIDDEN, blocking, detail, count };
    }
    return { key: gate.key, status: GATE_STATUS.FAIL, blocking, detail, count };
  });

  const blockingFailures = results.filter(
    (r) => r.blocking && (r.status === GATE_STATUS.FAIL || r.status === GATE_STATUS.UNKNOWN),
  ).length;

  const advisoryFailures = results.filter(
    (r) => !r.blocking && r.status === GATE_STATUS.FAIL,
  ).length;

  return {
    gates: results,
    status: blockingFailures === 0 ? RELEASE_GATE_STATE.READY : RELEASE_GATE_STATE.BLOCKED,
    blocking_failures: blockingFailures,
    advisory_failures: advisoryFailures,
    // True only when the override is actually doing something. An override that
    // names a passing gate is still recorded, but it is not load-bearing and the
    // dashboard should not imply the release shipped on one.
    override_active: results.some((r) => r.status === GATE_STATUS.OVERRIDDEN),
    // Surfaced so the UI can say so out loud rather than showing a confident tick.
    ungoverned: configured.length === 0,
    empty: (snapshot.total_issues ?? 0) === 0,
  };
}

/** Which currently-failing gates an override would have to name. */
export function failingGateKeys(evaluation) {
  return evaluation.gates
    .filter((g) => g.blocking && (g.status === GATE_STATUS.FAIL || g.status === GATE_STATUS.UNKNOWN))
    .map((g) => g.key);
}

/**
 * SIRE — issue classification. The executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireClassifier.php; both run
 * fixtures/classification-cases.json.
 *
 * WHAT THIS ACTUALLY IS, STATED PLAINLY
 *
 * A weighted k-nearest-neighbour vote over the tenant's OWN historical issues. It
 * is not a language model and does not pretend to be. It is presented through the
 * AI foundation because it produces exactly what that foundation is for — a
 * suggestion with a confidence, an explanation, and a human decision recorded
 * against it — and because it satisfies the harder requirements for free:
 *
 *   - nothing leaves the tenant, so no data-processing question arises
 *   - the reason is a sentence a person can check: "7 of 9 similar issues in
 *     Sales / Leads were logged as Bug"
 *   - it costs nothing per call and cannot be unavailable
 *
 * A real model can be added later as another provider. This is the baseline it
 * would have to beat, and a baseline that explains itself is a high bar.
 */

/** Below this many neighbours, the honest answer is "I don't know". */
export const MIN_NEIGHBOURS = 2;

/** Coverage saturates here — five agreeing neighbours is as sure as this gets. */
export const FULL_COVERAGE = 5;

/** Below this, a recommendation is noise and is withheld. */
export const MIN_CONFIDENCE = 0.25;

/**
 * Weighted vote over one field.
 *
 * Confidence is (winning share) × (coverage), so two neighbours in perfect
 * agreement do NOT report 100%. Unanimity among a tiny sample is the most common
 * way a recommender lies, and the coverage term is what stops it.
 */
export function vote(neighbours, field) {
  const usable = neighbours.filter((n) => n[field] !== null && n[field] !== undefined && n[field] !== '');

  if (usable.length < MIN_NEIGHBOURS) {
    return {
      value: null,
      confidence: 0,
      abstained: true,
      reason: usable.length === 0
        ? 'No similar issues have been classified yet.'
        : `Only ${usable.length} similar issue was found — not enough to suggest anything.`,
      tally: [],
    };
  }

  const weights = new Map();
  let total = 0;

  for (const n of usable) {
    const w = Math.max(0, n.weight ?? 0);
    weights.set(n[field], (weights.get(n[field]) ?? 0) + w);
    total += w;
  }

  if (total === 0) {
    return { value: null, confidence: 0, abstained: true, reason: 'Similar issues carried no weight.', tally: [] };
  }

  const tally = [...weights.entries()]
    .map(([value, weight]) => ({ value, weight: Number(weight.toFixed(4)), share: Number((weight / total).toFixed(4)) }))
    .sort((a, b) => b.weight - a.weight);

  const winner = tally[0];
  const coverage = Math.min(1, usable.length / FULL_COVERAGE);
  const confidence = Number((winner.share * coverage).toFixed(4));

  if (confidence < MIN_CONFIDENCE) {
    return {
      value: null,
      confidence,
      abstained: true,
      reason: 'Similar issues disagree too much to suggest one confidently.',
      tally,
    };
  }

  const agreeing = usable.filter((n) => n[field] === winner.value).length;

  return {
    value: winner.value,
    confidence,
    abstained: false,
    // A reason a person can check against the list of neighbours they are shown.
    reason: `${agreeing} of ${usable.length} similar issues were ${field === 'category' ? 'logged as' : 'set to'} ${winner.value}.`,
    tally: tally.slice(0, 4),
  };
}

/**
 * Classify a new issue.
 *
 * `capturedModule` comes from the Report Issue context — the screen the person was
 * actually on when they filed. That is an observation, not an inference, and it
 * outranks any vote: guessing the module from wording when the browser already
 * told us would be worse in every case.
 */
export function classify(query, neighbours, opts = {}) {
  const captured = opts.capturedModule ?? query.module ?? null;

  const moduleResult = captured
    ? {
        value: captured,
        confidence: 0.95,
        abstained: false,
        reason: 'Taken from the screen you were on when you reported this.',
        tally: [],
        source: 'captured',
      }
    : { ...vote(neighbours, 'module'), source: 'neighbours' };

  return {
    neighbour_count: neighbours.length,
    module:   moduleResult,
    category: { ...vote(neighbours, 'category'), source: 'neighbours' },
    severity: { ...vote(neighbours, 'severity'), source: 'neighbours' },
    priority: { ...vote(neighbours, 'priority'), source: 'neighbours' },
  };
}

/** The overall confidence a suggestion record carries: the mean of what it offered. */
export function overallConfidence(result) {
  const offered = ['module', 'category', 'severity', 'priority']
    .map((f) => result[f])
    .filter((r) => !r.abstained);

  if (offered.length === 0) return null;

  return Number((offered.reduce((s, r) => s + r.confidence, 0) / offered.length).toFixed(4));
}

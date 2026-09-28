/**
 * SIRE — AI suggested root cause. The executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireRootCauseSuggester.php.
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE: nothing here invents a root cause.
 *
 * A root cause is a claim about why something broke. A generator that writes one
 * from templates would be producing plausible prose with no evidence behind it,
 * and plausible prose is exactly what gets copied into a field labelled
 * "Confirmed Root Cause" by someone in a hurry.
 *
 * So the suggestion is GROUNDED: the category is voted from historical issues
 * whose root cause a human already confirmed, and the description is QUOTED from
 * the nearest of them, attributed by issue number. The output is not "the cause is
 * X" — it is "SIR-00123, 88% similar, was caused by X".
 */
import { vote } from './classification.mjs';

/** A neighbour only counts if a human confirmed its analysis. */
export function confirmedNeighbours(neighbours) {
  return neighbours.filter((n) => n.root_cause && n.root_cause.confirmed_at);
}

/**
 * @param {object} issue
 * @param {Array} neighbours  [{ref, weight, root_cause: {category, description, detection_gap, contributing_factors, confirmed_at}}]
 */
export function suggest(issue, neighbours) {
  const usable = confirmedNeighbours(neighbours);

  if (usable.length < 2) {
    return {
      abstained: true,
      confidence: 0,
      // An abstention that explains itself is useful: it tells the investigator
      // this is new territory rather than leaving them wondering.
      reason: usable.length === 0
        ? 'No similar issue has a confirmed root cause yet.'
        : 'Only one similar issue has a confirmed root cause — not enough to suggest from.',
      category: null,
      quoted_from: null,
      description: null,
      contributing_factors: [],
      detection_gap: null,
      related: [],
    };
  }

  const forVote = usable.map((n) => ({ weight: n.weight, category: n.root_cause.category }));
  const categoryVote = vote(forVote, 'category');

  if (categoryVote.abstained) {
    return {
      abstained: true,
      confidence: categoryVote.confidence,
      reason: categoryVote.reason,
      category: null,
      quoted_from: null,
      description: null,
      contributing_factors: [],
      detection_gap: null,
      related: usable.map((n) => n.ref),
    };
  }

  // Quote the CLOSEST neighbour that agrees with the winning category. Quoting the
  // closest overall could attribute a description to a category it does not belong
  // to, which would be worse than saying nothing.
  const agreeing = usable
    .filter((n) => n.root_cause.category === categoryVote.value)
    .sort((a, b) => b.weight - a.weight);

  const source = agreeing[0];

  // Contributing factors that more than one confirmed analysis recorded. A factor
  // seen once is that issue's detail, not a pattern.
  const factorCounts = new Map();
  for (const n of usable) {
    for (const f of n.root_cause.contributing_factors ?? []) {
      factorCounts.set(f, (factorCounts.get(f) ?? 0) + 1);
    }
  }
  const recurringFactors = [...factorCounts.entries()]
    .filter(([, count]) => count >= 2)
    .sort((a, b) => b[1] - a[1])
    .map(([factor]) => factor);

  return {
    abstained: false,
    confidence: categoryVote.confidence,
    category: categoryVote.value,
    reason: categoryVote.reason,
    // Always attributed. The UI renders this as a quotation, never as a finding.
    quoted_from: source.ref,
    description: source.root_cause.description,
    detection_gap: source.root_cause.detection_gap ?? null,
    contributing_factors: recurringFactors,
    related: usable.map((n) => n.ref),
    tally: categoryVote.tally,
  };
}

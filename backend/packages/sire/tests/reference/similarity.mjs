/**
 * SIRE — text similarity and duplicate scoring. The executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireTextAnalyzer.php and
 * SireSimilarityScorer.php; both run fixtures/similarity-cases.json.
 *
 * WHY THIS IS NOT A VECTOR DATABASE
 *
 * The CRM has no Scout, no Meilisearch, no Elasticsearch and no full-text index —
 * every existing "search" is a SQL LIKE inside a module service. Adding a vector
 * store would mean a new service to run, a new thing to back up, embeddings to
 * generate, and a second copy of every issue living outside the tenant boundary.
 *
 * What is actually needed is a shortlist of candidate issues that a human then
 * judges. A relational inverted index plus token overlap and structural agreement
 * does that, is portable across MySQL and SQLite, and produces evidence a person
 * can read: "these seven terms match, same screen, same category". A cosine
 * distance of 0.91 explains nothing.
 */

/** Small and deliberate. Over-aggressive stopwords delete the signal. */
export const STOPWORDS = new Set([
  'the', 'and', 'for', 'with', 'that', 'this', 'from', 'was', 'were', 'are', 'but',
  'not', 'you', 'your', 'our', 'they', 'them', 'when', 'then', 'than', 'have', 'has',
  'had', 'its', 'it', 'is', 'in', 'on', 'at', 'to', 'of', 'a', 'an', 'be', 'been',
  'can', 'will', 'would', 'should', 'could', 'get', 'got', 'after', 'before', 'into',
  'issue', 'ticket', 'please', 'also', 'user', 'users',
]);

const MIN_TOKEN_LENGTH = 3;

/**
 * Normalise one token.
 *
 * The ONLY morphology applied is a trailing plural 's'. Real stemming was
 * considered and rejected: crude suffix stripping turns "saving" into "sav" and
 * matches it against nothing, while proper stemming is a dependency. Issue titles
 * repeat terms verbatim often enough that the trade is not worth it.
 */
function normalise(token) {
  if (token.length >= 4 && token.endsWith('s') && !token.endsWith('ss')) {
    return token.slice(0, -1);
  }
  return token;
}

/**
 * Text → a set of distinctive tokens.
 *
 * Punctuation becomes whitespace, so `LeadPolicy::view` yields `leadpolicy` and
 * `view` — identifiers from a stack trace are exactly the rare terms that make a
 * duplicate obvious.
 */
export function tokenize(text) {
  if (typeof text !== 'string' || text.trim() === '') return [];

  const raw = text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, ' ')
    .split(/\s+/)
    .filter(Boolean);

  const out = new Set();
  for (const token of raw) {
    if (token.length < MIN_TOKEN_LENGTH) continue;
    if (STOPWORDS.has(token)) continue;
    const n = normalise(token);
    if (n.length < MIN_TOKEN_LENGTH || STOPWORDS.has(n)) continue;
    out.add(n);
  }

  return [...out];
}

/** Overlap of two token sets. Two empty sets are 0, not 1 — no evidence is not agreement. */
export function jaccard(a, b) {
  const setA = new Set(a);
  const setB = new Set(b);
  if (setA.size === 0 || setB.size === 0) return 0;

  let intersection = 0;
  for (const t of setA) if (setB.has(t)) intersection += 1;

  return intersection / (setA.size + setB.size - intersection);
}

export function sharedTokens(a, b) {
  const setB = new Set(b);
  return [...new Set(a)].filter((t) => setB.has(t)).sort();
}

/**
 * Structural agreement: do these two issues describe the same place?
 *
 * A field missing on either side scores zero rather than being excluded from the
 * average. Missing context SHOULD lower confidence — an issue with no captured
 * screen genuinely is harder to match, and pretending otherwise inflates the
 * score exactly where the evidence is thinnest.
 */
export const STRUCTURAL_WEIGHTS = { module: 0.40, section: 0.25, screen: 0.25, category: 0.10 };

export function structuralScore(a, b) {
  let score = 0;
  const matched = [];

  for (const [field, weight] of Object.entries(STRUCTURAL_WEIGHTS)) {
    if (a[field] && b[field] && a[field] === b[field]) {
      score += weight;
      matched.push(field);
    }
  }

  return { score: Number(score.toFixed(4)), matched };
}

export const WEIGHTS = { title: 0.50, body: 0.20, structural: 0.30 };

export const LABEL_THRESHOLDS = { very_likely: 0.85, likely: 0.60, possible: 0.35 };

export function labelFor(score) {
  if (score >= LABEL_THRESHOLDS.very_likely) return 'very_likely';
  if (score >= LABEL_THRESHOLDS.likely) return 'likely';
  if (score >= LABEL_THRESHOLDS.possible) return 'possible';
  return 'below_threshold';
}

/**
 * Score one candidate against the query issue.
 *
 * @param {object} query      {title, description, module, section, screen, category}
 * @param {object} candidate  same shape, plus {is_duplicate, closed_at}
 * @param {object} opts       {rareTokens:Set, now:string}
 */
export function scoreCandidate(query, candidate, opts = {}) {
  const qTitle = tokenize(query.title);
  const cTitle = tokenize(candidate.title);
  const qBody = tokenize(query.description);
  const cBody = tokenize(candidate.description);

  const titleSim = jaccard(qTitle, cTitle);
  const bodySim = jaccard(qBody, cBody);
  const structural = structuralScore(query, candidate);

  let score = WEIGHTS.title * titleSim + WEIGHTS.body * bodySim + WEIGHTS.structural * structural.score;

  const shared = sharedTokens(qTitle.concat(qBody), cTitle.concat(cBody));

  // A term that appears in only a handful of issues carries far more evidence
  // than one that appears in hundreds. Bounded, because a single rare word is a
  // hint, not a verdict.
  const rare = opts.rareTokens ? shared.filter((t) => opts.rareTokens.has(t)) : [];
  if (rare.length > 0) score += 0.10;

  // A candidate that is itself a duplicate points somewhere else. Suggesting it
  // sends the reader down a chain instead of to the real issue.
  if (candidate.is_duplicate) score *= 0.80;

  // Age is weak evidence against, not proof. A year-old issue with the same
  // symptom is more often a recurrence than a duplicate.
  if (opts.now && candidate.closed_at) {
    const days = (Date.parse(opts.now) - Date.parse(candidate.closed_at)) / 86400000;
    if (days > 365) score *= 0.90;
  }

  score = Math.max(0, Math.min(1, score));

  return {
    score: Number(score.toFixed(4)),
    label: labelFor(score),
    signals: {
      title_similarity: Number(titleSim.toFixed(4)),
      body_similarity: Number(bodySim.toFixed(4)),
      structural_score: structural.score,
      structural_matched: structural.matched,
      shared_terms: shared.slice(0, 12),
      rare_terms: rare.slice(0, 6),
    },
  };
}

/** Rank, threshold and cap. Five candidates is a shortlist; twenty is a search result. */
export function rankCandidates(query, candidates, opts = {}) {
  const limit = opts.limit ?? 5;

  return candidates
    .map((c) => ({ candidate: c, ...scoreCandidate(query, c, opts) }))
    .filter((r) => r.score >= LABEL_THRESHOLDS.possible)
    .sort((a, b) => b.score - a.score)
    .slice(0, limit);
}

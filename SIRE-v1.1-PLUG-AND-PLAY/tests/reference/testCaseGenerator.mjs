/**
 * SIRE — test case generation. The executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireTestCaseGenerator.php; both run
 * fixtures/test-case-generation-cases.json.
 *
 * WHAT THIS IS: a checklist generator working from templates and the issue's own
 * words. It is not a language model and invents no prose — every clause it emits
 * is either a fixed template phrase or a quotation from the issue.
 *
 * That constraint is what makes it safe to ship without a vendor. Most test cases
 * for a defect are formulaic — reproduce it, prove the fix, check the edges, check
 * the neighbours — and a generated checklist that a human then edits is worth more
 * than a blank form. What it must never do is sound like it understood the bug.
 *
 * SIX CATEGORIES. Two are always emitted; four are emitted only when the issue
 * carries a signal for them. A boundary test on an issue with no boundaries is
 * noise, and noise in a QA checklist trains people to skim it.
 */

export const CATEGORY = {
  HAPPY_PATH: 'happy_path',
  FAILURE_PATH: 'failure_path',
  BOUNDARY: 'boundary',
  PERMISSION: 'permission',
  REGRESSION: 'regression',
  RELATED_WORKFLOW: 'related_workflow',
};

export const CATEGORY_LABEL = {
  happy_path: 'Happy path',
  failure_path: 'Failure path',
  boundary: 'Boundary case',
  permission: 'Permission case',
  regression: 'Regression case',
  related_workflow: 'Related workflow',
};

/** Words that mean an issue has edges worth probing. */
const BOUNDARY_HINTS = [
  'limit', 'max', 'maximum', 'min', 'minimum', 'empty', 'blank', 'zero', 'null',
  'length', 'size', 'count', 'page', 'pagination', 'date', 'expiry', 'expire',
  'timeout', 'duplicate', 'overflow', 'truncat', 'decimal', 'negative', 'large',
];

/** Words that mean access control is part of the story. */
const PERMISSION_HINTS = [
  'permission', 'access', 'denied', '403', '401', 'unauthorized', 'unauthorised',
  'role', 'admin', 'forbidden', 'owner', 'visibility', 'restricted', 'tenant',
  'login', 'auth',
];

const haystack = (issue) =>
  [issue.title, issue.description, issue.steps_to_reproduce, issue.expected_result, issue.actual_result]
    .filter(Boolean).join(' ').toLowerCase();

const hasAny = (text, hints) => hints.filter((h) => text.includes(h));

/** A number worth testing the edges of — not a version, not an error code. */
function numericSignals(text) {
  return [...new Set((text.match(/\b\d{1,6}\b/g) ?? []).filter((n) => !['200', '201', '404', '500', '403', '401', '422'].includes(n)))];
}

const where = (issue) =>
  [issue.screen, issue.section, issue.module].find(Boolean) ?? 'the affected screen';

/** Quote the issue rather than paraphrase it. Trimmed, never rewritten. */
const quote = (text, fallback) => {
  const t = (text ?? '').trim();
  if (t === '') return fallback;
  return t.length > 240 ? `${t.slice(0, 237)}…` : t;
};

/**
 * @param {object} issue
 * @param {object} opts  {relatedRefs: string[], regressionOf: string|null}
 * @returns {Array<{category, title, given, when, then, rationale, source, status, result}>}
 */
export function generate(issue, opts = {}) {
  const text = haystack(issue);
  const place = where(issue);
  const out = [];

  const push = (category, title, given, when, then, rationale) => {
    out.push({
      category,
      title,
      given,
      when,
      then,
      rationale,
      source: 'ai_suggested',
      // Draft until a human accepts it. A generated test is a proposal.
      status: 'draft',
      // NEVER set by generation. A test nobody ran has no result, and writing one
      // here would let a checklist mark itself passed.
      result: null,
    });
  };

  // ---- always: prove the intended behaviour works --------------------------
  push(
    CATEGORY.HAPPY_PATH,
    `${place}: the intended behaviour works`,
    `A user with normal access is on ${place}.`,
    quote(issue.steps_to_reproduce, 'They perform the action described in the issue.'),
    quote(issue.expected_result, 'The action completes and the expected result is shown.'),
    'Confirms the feature works at all once the fix is in — a fix that breaks the ordinary path is not a fix.',
  );

  // ---- always: prove the reported failure is gone --------------------------
  push(
    CATEGORY.FAILURE_PATH,
    `${place}: the reported failure no longer happens`,
    `A user is on ${place}, in the state described in the issue.`,
    quote(issue.steps_to_reproduce, 'They repeat the steps that produced the failure.'),
    `The reported behaviour no longer occurs: ${quote(issue.actual_result, 'the failure described in the issue')}.`,
    'This is the test the issue itself asks for. Without it, nothing proves the defect was addressed.',
  );

  // ---- boundary: only when the issue has edges -----------------------------
  const boundaryWords = hasAny(text, BOUNDARY_HINTS);
  const numbers = numericSignals(text);
  if (boundaryWords.length > 0 || numbers.length > 0) {
    const signal = numbers.length > 0
      ? `values around ${numbers.slice(0, 3).join(', ')}`
      : `the ${boundaryWords.slice(0, 3).join(', ')} conditions mentioned in the issue`;

    push(
      CATEGORY.BOUNDARY,
      `${place}: behaviour at the edges`,
      `A user is on ${place}.`,
      `They exercise ${signal} — and the empty, minimum and maximum cases either side.`,
      'Each edge is handled predictably: no crash, no silent truncation, and a clear message where the input is refused.',
      `Emitted because the issue mentions ${numbers.length > 0 ? 'specific values' : boundaryWords.slice(0, 3).join(', ')}.`,
    );
  }

  // ---- permission: only where relevant, per the brief ----------------------
  const permissionWords = hasAny(text, PERMISSION_HINTS);
  if (permissionWords.length > 0 || issue.entity_type) {
    const because = permissionWords.length > 0
      ? `the issue mentions ${permissionWords.slice(0, 3).join(', ')}`
      : `the issue concerns a specific ${issue.entity_type}, which has owners and viewers`;

    push(
      CATEGORY.PERMISSION,
      `${place}: access is enforced for every role`,
      `Users of each role — admin, staff, and any portal role that can reach ${place}.`,
      'Each attempts the action in the issue, on a record they own and on one they do not.',
      'Permitted users succeed. Others are refused, and the refusal does not reveal that the record exists.',
      `Emitted because ${because}.`,
    );
  }

  // ---- regression: only when this has happened before ----------------------
  const regressionRef = opts.regressionOf ?? null;
  if (issue.is_regression || (issue.reopen_count ?? 0) > 0 || regressionRef || issue.fixed_version) {
    const reason = issue.is_regression
      ? 'this issue is flagged as a regression'
      : (issue.reopen_count ?? 0) > 0
        ? `this issue has been reopened ${issue.reopen_count} time(s)`
        : 'a fix is being shipped for it';

    push(
      CATEGORY.REGRESSION,
      regressionRef
        ? `${place}: ${regressionRef} does not come back`
        : `${place}: this defect does not come back`,
      `The build containing the fix${issue.fixed_version ? ` (${issue.fixed_version})` : ''}.`,
      `Re-run the failing steps${regressionRef ? `, and those of ${regressionRef}` : ''}, then repeat after a full page reload and a fresh session.`,
      'The defect stays fixed across sessions and reloads, and the earlier behaviour does not reappear.',
      `Emitted because ${reason}.`,
    );
  }

  // ---- related workflow: only when there is something adjacent -------------
  const related = opts.relatedRefs ?? [];
  if (related.length > 0 || issue.section) {
    const scope = related.length > 0
      ? `the linked issues ${related.slice(0, 3).join(', ')}`
      : `the rest of the ${issue.section} workflow`;

    push(
      CATEGORY.RELATED_WORKFLOW,
      `${place}: the surrounding workflow still works end to end`,
      `The same build, starting from the step before ${place}.`,
      `Complete the whole ${issue.section ?? 'affected'} flow, including ${scope}.`,
      'The workflow completes end to end, and nothing downstream of the fix changed behaviour.',
      related.length > 0
        ? `Emitted because this issue is linked to ${related.length} other issue(s).`
        : `Emitted because the issue sits inside the ${issue.section} workflow.`,
    );
  }

  return out;
}

/** Which categories a given issue will produce, without building the text. */
export function categoriesFor(issue, opts = {}) {
  return generate(issue, opts).map((t) => t.category);
}

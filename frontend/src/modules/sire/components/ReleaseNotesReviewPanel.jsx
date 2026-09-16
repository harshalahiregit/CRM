/**
 * SIRE — a review of the release notes, not a rewrite.
 *
 * Without a language model SIRE cannot rewrite prose, and this panel does not
 * pretend otherwise. It lists what will read badly when the notes publish —
 * entries with no customer-facing summary that will be silently omitted, internal
 * jargon that would reach a customer — each naming the issue so it can be fixed
 * directly.
 *
 * Publication is unchanged: generate, submit, approve, publish. This informs the
 * person doing that; it cannot do any of it.
 */
const SEVERITY_TONE = {
  high:   'text-red-600 dark:text-red-400',
  medium: 'text-amber-600 dark:text-amber-400',
  low:    'text-gray-500',
};

export default function ReleaseNotesReviewPanel({ suggestion, onOpenIssue }) {
  if (!suggestion) return null;

  const review = suggestion.payload ?? {};
  const findings = review.findings ?? [];
  const counts = review.counts ?? {};

  return (
    <section className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
      <header className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">
          Release notes review · advisory
        </h3>
        {review.ready ? (
          <span className="rounded-full bg-green-50 px-2 py-0.5 text-[11px] font-medium text-green-700 dark:bg-green-950 dark:text-green-200">
            Nothing to fix
          </span>
        ) : (
          <span className="rounded-full bg-amber-50 px-2 py-0.5 text-[11px] font-medium text-amber-800 dark:bg-amber-950 dark:text-amber-200">
            {findings.length} to look at
          </span>
        )}
      </header>

      <p className="mb-2 text-[11px] text-gray-600 dark:text-gray-300">{review.summary}</p>

      {counts.security > 0 && (
        <p className="mb-2 rounded bg-gray-50 px-2 py-1 text-[10px] text-gray-600 dark:bg-gray-800 dark:text-gray-300">
          {counts.security} security fix(es) will be counted but not described — the detail stays internal.
        </p>
      )}

      {findings.length > 0 && (
        <ul className="divide-y divide-gray-100 dark:divide-gray-800">
          {findings.map((f, i) => (
            <li key={i} className="py-1.5">
              <div className="flex items-baseline gap-2">
                <button
                  type="button"
                  className="font-mono text-[11px] text-blue-600 hover:underline"
                  onClick={() => onOpenIssue?.(f.issue_id)}
                >
                  {f.issue}
                </button>
                <span className={`text-[11px] ${SEVERITY_TONE[f.severity] ?? ''}`}>{f.detail}</span>
              </div>
              <p className="ml-1 mt-0.5 text-[10px] text-gray-500">{f.suggestion}</p>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

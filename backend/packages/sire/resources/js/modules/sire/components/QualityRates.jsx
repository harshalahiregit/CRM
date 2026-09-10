/**
 * SIRE — the four headline quality rates.
 *
 * The important behaviour here is what happens when there is NO DATA. A zero
 * denominator renders "—", never "0%": zero percent reads as perfect, and telling
 * a team their reopen rate is perfect because nothing was resolved this week is
 * worse than telling them nothing.
 *
 * Small samples are labelled rather than hidden. One regression out of three
 * issues is 33%, and it means almost nothing — so it says so.
 */
const CARDS = [
  { key: 'reopen_rate',       label: 'Reopen rate',     hint: 'Reopened ÷ resolved in period' },
  { key: 'qa_rejection_rate', label: 'QA rejection',    hint: 'Failed QA runs ÷ QA runs' },
  { key: 'regression_rate',   label: 'Regression rate', hint: 'Regressions ÷ issues raised' },
  { key: 'recurrence_rate',   label: 'Recurrence rate', hint: 'Recurring ÷ issues raised' },
];

/** Higher is worse for all four, so the scale runs one way. */
function tone(value) {
  if (value === null || value === undefined) return 'text-gray-300 dark:text-gray-600';
  if (value >= 0.3) return 'text-red-600 dark:text-red-400';
  if (value >= 0.15) return 'text-amber-600 dark:text-amber-400';
  return 'text-green-600 dark:text-green-400';
}

export default function QualityRates({ rates = {}, loading }) {
  return (
    <div className="grid grid-cols-2 gap-2 lg:grid-cols-4">
      {CARDS.map((card) => {
        const metric = rates[card.key];
        const value = metric?.value ?? null;

        return (
          <div key={card.key} className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div className="flex items-baseline gap-2">
              <span className={`text-2xl font-semibold tabular-nums ${tone(value)}`}>
                {loading ? <span className="text-gray-300">—</span> : (metric?.display ?? '—')}
              </span>
              {metric?.low_confidence && metric?.value !== null && (
                <span
                  title={`Only ${metric.sample} in this period — read with care`}
                  className="rounded bg-gray-100 px-1 text-[9px] font-medium uppercase tracking-wide text-gray-500 dark:bg-gray-800"
                >
                  n={metric.sample}
                </span>
              )}
            </div>

            <div className="mt-0.5 text-[11px] font-medium text-gray-600 dark:text-gray-300">{card.label}</div>
            <div className="text-[10px] text-gray-400">
              {metric?.value === null && !loading ? 'No data in this period' : card.hint}
            </div>

            {metric?.suspect && (
              <div className="mt-1 text-[10px] text-amber-600">
                Counted over different sets — treat as approximate.
              </div>
            )}
          </div>
        );
      })}
    </div>
  );
}

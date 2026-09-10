/**
 * SIRE — engineering insights.
 *
 * Six questions a lead actually asks, answered from this tenant's own register.
 * Counts, medians and rankings — no forecasting, no scoring model, and nothing
 * that reads as a judgement about a person. Every row carries the sentence that
 * justifies its position.
 */
import { useQuery } from '@tanstack/react-query';
import { sireApi } from '../../../services/sireApi';
import { sireHostUi } from '../../../lib/sire/host';
const EmptyState = sireHostUi('EmptyState');

function Board({ title, subtitle, rows, columns, empty }) {
  return (
    <section className="rounded-lg border border-gray-200 p-4 dark:border-gray-700">
      <header className="mb-2">
        <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-50">{title}</h2>
        {subtitle && <p className="text-[11px] text-gray-500">{subtitle}</p>}
      </header>

      {(!rows || rows.length === 0) ? (
        <p className="text-xs text-gray-400">{empty}</p>
      ) : (
        <ul className="divide-y divide-gray-100 dark:divide-gray-800">
          {rows.map((row, i) => (
            <li key={i} className="flex items-start justify-between gap-3 py-2">
              <div className="min-w-0">
                <span className="text-sm font-medium">{row[columns.label]}</span>
                {/* The sentence that justifies the position, not just the number. */}
                <p className="mt-0.5 text-[11px] text-gray-500">{row.reason}</p>
              </div>
              <span className="shrink-0 tabular-nums text-sm font-medium text-gray-700 dark:text-gray-300">
                {row[columns.value]}
              </span>
            </li>
          ))}
        </ul>
      )}
    </section>
  );
}

export default function EngineeringInsightsPage() {
  const { data, isLoading } = useQuery({
    queryKey: ['sire', 'engineering-insights'],
    queryFn: () => sireApi.aiEngineeringInsights().then((r) => r.data?.data ?? r.data),
  });

  if (isLoading) return <div className="p-6 text-sm text-gray-400">Loading…</div>;

  if (!data?.enabled) {
    return (
      <div className="mx-auto max-w-4xl p-6">
        <EmptyState
          title="Engineering insights are switched off"
          description="An admin can enable them in the SIRE AI settings. Everything is computed from this workspace's own issues — nothing leaves it."
        />
      </div>
    );
  }

  const i = data.insights ?? {};

  return (
    <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
      <header>
        <h1 className="text-xl font-semibold text-gray-900 dark:text-gray-50">Engineering insights</h1>
        <p className="mt-0.5 text-xs text-gray-500">
          {i.window && `${i.window.from} to ${i.window.to}. `}
          Computed from this workspace only. Counts and medians — no predictions.
        </p>
      </header>

      <div className="grid gap-4 lg:grid-cols-2">
        <Board
          title="Most unstable modules"
          subtitle="Ranked on issues that came back, not on volume — a busy module is not an unstable one"
          rows={i.most_unstable_modules}
          columns={{ label: 'module', value: 'instability_index' }}
          empty="Not enough issues in any module yet."
        />
        <Board
          title="Highest regression modules"
          subtitle="Where changes break what already worked"
          rows={i.highest_regression_modules}
          columns={{ label: 'module', value: 'regressions' }}
          empty="No regressions recorded in this period."
        />
        <Board
          title="Recurring problem areas"
          subtitle="Patterns that keep coming back"
          rows={i.recurring_problem_areas}
          columns={{ label: 'title', value: 'occurrences' }}
          empty="No active recurring patterns."
        />
        <Board
          title="Biggest issue categories"
          subtitle="What the workspace mostly files"
          rows={i.biggest_issue_categories}
          columns={{ label: 'category', value: 'total' }}
          empty="No issues in this period."
        />
        <Board
          title="Longest resolution areas"
          subtitle="Median days to close — a median, so one stale issue cannot distort it"
          rows={i.longest_resolution_areas}
          columns={{ label: 'module', value: 'median_days' }}
          empty="Not enough closed issues to compare."
        />
        <Board
          title="Most reopened issues"
          subtitle="Fixes that did not hold"
          rows={i.most_reopened_issues}
          columns={{ label: 'title', value: 'reopens' }}
          empty="Nothing has been reopened in this period."
        />
      </div>
    </div>
  );
}

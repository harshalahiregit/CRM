/**
 * SIRE — quality and governance dashboard.
 *
 * Deliberately NOT analytics. Counts, ratios and buckets over data the register
 * already holds: no forecasting, no anomaly detection, no scoring model, no AI,
 * no external service. The brief defers advanced quality analytics and each of
 * those needs a decision about what it measures before it needs code.
 */
import { useMemo, useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { sireApi } from '../../../services/sireApi';
import QualityRates from '../components/QualityRates';
import TrendChart from '../components/TrendChart';
import RecurringList from '../components/RecurringList';
import { sireHostUi } from '../../../lib/sire/host';
const EmptyState = sireHostUi('EmptyState');

const RANGES = [
  { key: '30d', label: '30 days', days: 30, bucket: 'week' },
  { key: '90d', label: '90 days', days: 90, bucket: 'week' },
  { key: '12m', label: '12 months', days: 365, bucket: 'month' },
];

const iso = (d) => d.toISOString().slice(0, 10);

function Panel({ title, subtitle, children, className = '' }) {
  return (
    <section className={`rounded-lg border border-gray-200 p-4 dark:border-gray-700 ${className}`}>
      <header className="mb-3">
        <h2 className="text-sm font-semibold text-gray-900 dark:text-gray-50">{title}</h2>
        {subtitle && <p className="text-[11px] text-gray-500">{subtitle}</p>}
      </header>
      {children}
    </section>
  );
}

export default function QualityDashboardPage() {
  const [rangeKey, setRangeKey] = useState('90d');
  const range = RANGES.find((r) => r.key === rangeKey) ?? RANGES[1];

  const params = useMemo(() => {
    const to = new Date();
    const from = new Date(to.getTime() - range.days * 86400000);
    return { from: iso(from), to: iso(to), bucket: range.bucket };
  }, [range]);

  const { data, isLoading } = useQuery({
    queryKey: ['sire', 'quality', params],
    queryFn: () => sireApi.quality(params).then((r) => r.data?.data ?? r.data),
    placeholderData: (previous) => previous,
  });

  const topModules = data?.top_modules ?? [];
  const byRelease = data?.regressions_by_release ?? [];

  return (
    <div className="mx-auto max-w-7xl space-y-4 p-4 sm:p-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-900 dark:text-gray-50">Quality</h1>
          <p className="mt-0.5 text-xs text-gray-500">
            Rates are computed over the selected period. A rate with no denominator reads “—”, not 0%.
          </p>
        </div>

        <div className="flex rounded-lg border border-gray-300 p-0.5 dark:border-gray-600">
          {RANGES.map((r) => (
            <button
              key={r.key}
              type="button"
              onClick={() => setRangeKey(r.key)}
              className={`rounded px-2.5 py-1 text-xs font-medium transition ${
                r.key === rangeKey
                  ? 'bg-gray-900 text-white dark:bg-gray-100 dark:text-gray-900'
                  : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800'
              }`}
            >
              {r.label}
            </button>
          ))}
        </div>
      </header>

      <QualityRates rates={data?.rates ?? {}} loading={isLoading} />

      <Panel title="Defect trend" subtitle={`Created vs resolved, per ${range.bucket}`}>
        <TrendChart data={data?.trend ?? []} bucket={range.bucket} />
      </Panel>

      <div className="grid gap-4 lg:grid-cols-2">
        <Panel title="Recurring issues" subtitle="Active groups, highest risk first">
          <RecurringList groups={data?.recurring_issues ?? []} />
        </Panel>

        <Panel title="Regressions by release" subtitle="Which releases cost us the most">
          {byRelease.length === 0 ? (
            <EmptyState title="No regressions recorded" description="Nothing has been attributed to a release yet." />
          ) : (
            <ul className="divide-y divide-gray-200 dark:divide-gray-700">
              {byRelease.map((r) => (
                <li key={r.release_id} className="flex items-center justify-between py-2 text-sm">
                  <span>
                    <span className="font-mono text-xs text-gray-500">{r.version}</span>
                    {r.name && <span className="ml-2 text-gray-700 dark:text-gray-300">{r.name}</span>}
                  </span>
                  <span className="tabular-nums font-medium text-red-600 dark:text-red-400">{r.regressions}</span>
                </li>
              ))}
            </ul>
          )}
        </Panel>
      </div>

      <Panel title="Modules with the highest defect counts" subtitle="Where to look first — not a verdict on anyone">
        {topModules.length === 0 ? (
          <EmptyState title="No issues in this period" />
        ) : (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200 text-left text-[10px] uppercase tracking-wide text-gray-400 dark:border-gray-700">
                  <th className="py-1.5 font-medium">Module</th>
                  <th className="py-1.5 text-right font-medium">Issues</th>
                  <th className="py-1.5 text-right font-medium">Regressions</th>
                  <th className="py-1.5 text-right font-medium">Reopened</th>
                  <th className="py-1.5 pl-4 font-medium">Share</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {topModules.map((m) => (
                  <tr key={m.module}>
                    <td className="py-1.5">{m.module}</td>
                    <td className="py-1.5 text-right tabular-nums">{m.total}</td>
                    <td className="py-1.5 text-right tabular-nums text-red-600 dark:text-red-400">{m.regressions || '—'}</td>
                    <td className="py-1.5 text-right tabular-nums text-orange-600 dark:text-orange-400">{m.reopened || '—'}</td>
                    <td className="py-1.5 pl-4">
                      {/* A bar beats a percentage for "which of these is worst". */}
                      <div className="h-1.5 w-full max-w-[8rem] rounded-full bg-gray-100 dark:bg-gray-800">
                        <div
                          className="h-1.5 rounded-full bg-gray-400 dark:bg-gray-500"
                          style={{ width: `${Math.round((m.total / topModules[0].total) * 100)}%` }}
                        />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </Panel>
    </div>
  );
}

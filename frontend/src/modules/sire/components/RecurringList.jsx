/**
 * SIRE — active recurrence groups, worst first.
 *
 * Shows the count, the cadence and whether a permanent fix has landed, because
 * those three together are the argument for doing something about it.
 */
import { Link } from 'react-router-dom';

import { riskToken, chipClasses } from '../../../lib/sire/tokens';
import { sireHostUi } from '../../../lib/sire/host';
const EmptyState = sireHostUi('EmptyState');

const FIX_LABEL = {
  none: 'No permanent fix', planned: 'Fix planned', in_progress: 'Fix in progress',
  shipped: 'Fix shipped', verified: 'Fix verified',
};

const cadence = (days) => {
  if (days === null || days === undefined) return 'cadence unknown';
  if (days < 1) return 'multiple times a day';
  if (days < 14) return `about every ${Math.round(days)} days`;
  if (days < 60) return `about every ${Math.round(days / 7)} weeks`;
  return `about every ${Math.round(days / 30)} months`;
};

export default function RecurringList({ groups = [] }) {
  if (groups.length === 0) {
    return <EmptyState title="No active recurring issues" description="Nothing has been grouped as recurring yet." />;
  }

  return (
    <ul className="divide-y divide-gray-200 dark:divide-gray-700">
      {groups.map((g) => (
        <li key={g.id} className="flex items-start gap-3 py-2.5">
          {(() => {
            const t = riskToken(g.recurrence_risk);
            return (
              <span className={`mt-0.5 shrink-0 uppercase ${chipClasses(t, 'sm')}`}>
                {t.marker && <span aria-hidden>{t.marker}</span>}
                {t.label}
              </span>
            );
          })()}

          <div className="min-w-0 flex-1">
            <Link to={`/app/sire/recurring/${g.id}`} className="block truncate text-sm font-medium hover:underline">
              {g.title}
            </Link>
            <div className="mt-0.5 text-[11px] text-gray-500">
              <span className="font-mono">{g.reference}</span>
              {' · '}
              <span className="font-medium text-gray-700 dark:text-gray-300">{g.occurrence_count}×</span>
              {' · '}
              {cadence(g.average_interval_days)}
              {' · '}
              <span className={g.permanent_fix_status === 'none' ? 'text-red-600' : ''}>
                {FIX_LABEL[g.permanent_fix_status] ?? g.permanent_fix_status}
              </span>
            </div>
          </div>
        </li>
      ))}
    </ul>
  );
}

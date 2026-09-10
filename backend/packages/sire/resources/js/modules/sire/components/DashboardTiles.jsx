/**
 * SIRE — the ten tiles.
 *
 * Every tile is a button: its number and the list you get by clicking it come
 * from the SAME scope on the server, so they cannot disagree. A dashboard whose
 * tile says 12 and whose drill-down shows 9 is worse than no dashboard.
 */
import { TILES } from '../../../lib/sire/dashboardFilters';

const TONE = {
  slate:  'text-slate-700 dark:text-slate-200',
  red:    'text-red-600 dark:text-red-400',
  orange: 'text-orange-600 dark:text-orange-400',
  blue:   'text-blue-600 dark:text-blue-400',
  violet: 'text-violet-600 dark:text-violet-400',
  green:  'text-green-600 dark:text-green-400',
};

export default function DashboardTiles({ counts = {}, active, onSelect, loading }) {
  return (
    <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      {TILES.map((tile) => {
        const value = counts[tile.scope];
        const isActive = active === tile.scope;

        return (
          <button
            key={tile.key}
            type="button"
            onClick={() => onSelect(isActive ? null : tile.scope)}
            aria-pressed={isActive}
            className={[
              'rounded-lg border p-3 text-left transition',
              isActive
                ? 'border-gray-900 bg-gray-50 dark:border-gray-100 dark:bg-gray-800'
                : 'border-gray-200 hover:border-gray-300 dark:border-gray-700 dark:hover:border-gray-600',
            ].join(' ')}
          >
            <div className={`text-2xl font-semibold tabular-nums ${TONE[tile.tone] ?? TONE.slate}`}>
              {loading ? <span className="text-gray-300">—</span> : (value ?? 0)}
            </div>
            <div className="mt-0.5 text-[11px] leading-tight text-gray-500">{tile.label}</div>
          </button>
        );
      })}
    </div>
  );
}

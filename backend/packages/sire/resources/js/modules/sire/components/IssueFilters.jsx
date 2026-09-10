/**
 * SIRE — the filter bar.
 *
 * Driven entirely by FILTERS in lib/sire/dashboardFilters.js. Adding a filter is
 * a line of data there plus a line in the backend allowlist; no component changes.
 *
 * The tenant control is hidden while only one tenant is accessible, which in this
 * CRM is always — a user belongs to exactly one tenant and there is no
 * cross-tenant read path. The control exists because the brief asked for it and
 * because the backend validates it; rendering a select with one option would just
 * be furniture.
 */
import { FILTERS, activeFilterCount } from '../../../lib/sire/dashboardFilters';

const INPUT = 'rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800';

export default function IssueFilters({ filters, options = {}, onChange, onClear }) {
  const set = (key, value) => onChange({ ...filters, [key]: value });
  const count = activeFilterCount(filters);

  const visible = Object.entries(FILTERS).filter(([, spec]) => {
    if (!spec.hiddenWhenSingle) return true;
    return (options[spec.optionsKey] ?? []).length > 1;
  });

  return (
    <div className="flex flex-wrap items-end gap-2">
      {visible.map(([key, spec]) => {
        const list = options[spec.optionsKey] ?? [];

        if (spec.type === 'date') {
          return (
            <label key={key} className="flex flex-col gap-1">
              <span className="text-[10px] uppercase tracking-wide text-gray-400">{spec.label}</span>
              <input type="date" className={INPUT} value={filters[key] ?? ''} onChange={(e) => set(key, e.target.value)} />
            </label>
          );
        }

        if (spec.type === 'multi') {
          const selected = filters[key] ?? [];
          return (
            <label key={key} className="flex flex-col gap-1">
              <span className="text-[10px] uppercase tracking-wide text-gray-400">
                {spec.label}{selected.length > 0 && ` (${selected.length})`}
              </span>
              <select
                multiple
                size={1}
                className={`${INPUT} min-w-[9rem]`}
                value={selected}
                onChange={(e) => set(key, Array.from(e.target.selectedOptions, (o) => o.value))}
              >
                {list.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
            </label>
          );
        }

        return (
          <label key={key} className="flex flex-col gap-1">
            <span className="text-[10px] uppercase tracking-wide text-gray-400">{spec.label}</span>
            <select className={`${INPUT} min-w-[8rem]`} value={filters[key] ?? ''} onChange={(e) => set(key, e.target.value)}>
              <option value="">All</option>
              {list.map((o) => (
                <option key={o.id ?? o.value} value={o.id ?? o.value}>{o.name ?? o.label}</option>
              ))}
            </select>
          </label>
        );
      })}

      {count > 0 && (
        <button type="button" onClick={onClear} className="pb-1.5 text-xs text-gray-500 underline hover:text-gray-700">
          Clear {count} filter{count === 1 ? '' : 's'}
        </button>
      )}
    </div>
  );
}

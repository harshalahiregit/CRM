/**
 * SIRE — saved list views.
 *
 * Per browser, not per account. The CRM has no per-user preference store, so these
 * live in localStorage — and the UI says so rather than letting someone discover
 * it when they open the list on their laptop.
 *
 * Renders nothing at all when storage is unavailable (a private window, blocked
 * site data). A control that cannot work should not be offered.
 */
import { useCallback, useMemo, useState } from 'react';
import { listViews, saveView, deleteView, matchView, isAvailable } from '../../../lib/sire/savedViews';
import { activeFilterCount } from '../../../lib/sire/dashboardFilters';

export default function SavedViews({ listKey, filters, scope, onApply }) {
  const available = useMemo(() => isAvailable(), []);
  const [views, setViews] = useState(() => (available ? listViews(listKey) : []));
  const [naming, setNaming] = useState(false);
  const [name, setName] = useState('');

  const active = useMemo(() => matchView(views, filters, scope), [views, filters, scope]);
  const hasFilters = activeFilterCount(filters) > 0 || Boolean(scope);

  const save = useCallback(() => {
    if (saveView(listKey, { name, filters, scope })) {
      setViews(listViews(listKey));
      setName('');
      setNaming(false);
    }
  }, [listKey, name, filters, scope]);

  const remove = useCallback((id) => {
    deleteView(listKey, id);
    setViews(listViews(listKey));
  }, [listKey]);

  if (!available) return null;

  return (
    <div className="flex flex-wrap items-center gap-1.5">
      {views.map((view) => (
        <span
          key={view.id}
          className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] ring-1 ring-inset ${
            active?.id === view.id
              ? 'bg-gray-900 text-white ring-gray-900 dark:bg-gray-100 dark:text-gray-900 dark:ring-gray-100'
              : 'bg-white text-gray-600 ring-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-700'
          }`}
        >
          <button type="button" onClick={() => onApply(view)}>{view.name}</button>
          <button
            type="button"
            aria-label={`Delete view ${view.name}`}
            className="opacity-50 hover:opacity-100"
            onClick={() => remove(view.id)}
          >
            ×
          </button>
        </span>
      ))}

      {naming ? (
        <span className="inline-flex items-center gap-1">
          <input
            autoFocus
            className="w-36 rounded border border-gray-300 px-2 py-0.5 text-[11px] dark:border-gray-600 dark:bg-gray-800"
            placeholder="Name this view"
            value={name}
            onChange={(e) => setName(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && save()}
          />
          <button type="button" className="text-[11px] text-blue-600 hover:underline" onClick={save} disabled={!name.trim()}>
            Save
          </button>
          <button type="button" className="text-[11px] text-gray-400" onClick={() => setNaming(false)}>Cancel</button>
        </span>
      ) : hasFilters && !active ? (
        <button type="button" className="text-[11px] text-blue-600 hover:underline" onClick={() => setNaming(true)}>
          Save this view
        </button>
      ) : null}

      {views.length > 0 && (
        <span
          className="text-[10px] text-gray-400"
          title="Saved views are stored in this browser only. They are not shared or synced."
        >
          this browser only
        </span>
      )}
    </div>
  );
}

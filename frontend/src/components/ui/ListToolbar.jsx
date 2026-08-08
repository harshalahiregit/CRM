import { Search, Download, X } from 'lucide-react'
import ListControls from './ListControls'

/**
 * The controls every list page should have, in one place.
 *
 * Mirrors the old CRM's list toolbar, which got these for free from DataTables on
 * every table: a search box, a record count, a page-size selector, refresh, and
 * export. Our lists each grew their own subset — of 18 lists across Sales,
 * Customer and Accounts, 10 had no search, 16 no export and none the shared
 * page-size/refresh — so this exists to stop that drifting again.
 *
 * Composes ListControls (page-size + refresh) rather than duplicating it, so the
 * modules already using that keep identical behaviour.
 *
 * Everything is opt-in: pass only the handlers a page can honour, and the rest
 * doesn't render. A page with no export endpoint simply omits `onExport`.
 *
 * Props:
 *   search / onSearch      controlled search box; omit onSearch to hide it
 *   searchPlaceholder      defaults to a generic label
 *   count / total          "12 of 40 records" when both differ, else "40 records"
 *   pageSize / onPageSize  forwarded to ListControls
 *   onRefresh              forwarded to ListControls (spins while the promise runs)
 *   onExport               shows the Export button; may return a promise
 *   children               extra controls (status tabs, view toggles) rendered inline
 */
export default function ListToolbar({
  search = '', onSearch, searchPlaceholder = 'Search…',
  count = null, total = null, unit = 'record',
  pageSize, onPageSize, onRefresh, onExport,
  exportLabel = 'Export CSV',
  children, className = '',
}) {
  // The count reads as a filter result only when a filter is actually narrowing
  // things; otherwise "40 of 40" is noise.
  const showRange = count !== null && total !== null && count !== total
  const shown = showRange ? `${count} of ${total}` : (count ?? total)
  const plural = Number(shown === null ? 0 : (count ?? total)) === 1 ? unit : `${unit}s`

  return (
    <div className={`flex items-center gap-2 flex-wrap ${className}`}>
      {onSearch && (
        <div className="relative flex-1" style={{ minWidth: 200, maxWidth: 340 }}>
          <Search size={14} className="absolute left-3 top-1/2 -translate-y-1/2 pointer-events-none"
            style={{ color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => onSearch(e.target.value)}
            className="input-3d text-sm" style={{ paddingLeft: 34, paddingRight: search ? 30 : undefined }}
            placeholder={searchPlaceholder} aria-label={searchPlaceholder} />
          {/* Clearing a search shouldn't mean selecting-and-deleting the text. */}
          {search && (
            <button type="button" onClick={() => onSearch('')} title="Clear search" aria-label="Clear search"
              className="absolute right-2 top-1/2 -translate-y-1/2 w-5 h-5 rounded-md flex items-center justify-center">
              <X size={12} style={{ color: 'var(--text-muted)' }} />
            </button>
          )}
        </div>
      )}

      {children}

      <div className="flex items-center gap-2 ml-auto">
        {shown !== null && (
          <span className="text-[11px] font-bold uppercase tracking-wide whitespace-nowrap"
            style={{ color: 'var(--text-muted)' }}>
            {shown} {plural}
          </span>
        )}

        <ListControls pageSize={pageSize} onPageSize={onPageSize} onRefresh={onRefresh} />

        {onExport && (
          <button type="button" onClick={onExport} title={exportLabel}
            className="flex items-center gap-1.5 rounded-lg text-xs font-semibold whitespace-nowrap"
            style={{ padding: '7px 10px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--color-success-500, #10b981)' }}>
            <Download size={13} /> <span className="hidden sm:inline">Export</span>
          </button>
        )}
      </div>
    </div>
  )
}

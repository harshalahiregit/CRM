import { useMemo, useState } from 'react'
import { ChevronUp, ChevronDown } from 'lucide-react'
import EmptyState from './EmptyState'

/**
 * columns: [{ key, label, sortable, render(row), align }]
 *
 * `filtered` + `onClearFilters` separate the two empty results that were being
 * reported identically. "Nothing to show yet" is a statement about the account;
 * "nothing matched" is a statement about the filter, and only one of them is
 * the user's to fix. Without them a search miss read as an empty ledger.
 *
 * `emptyState` still overrides everything, for callers with their own copy.
 */
export default function DataTable({
  columns, rows, keyField = 'id', onRowClick, emptyState,
  filtered = false, onClearFilters,
  emptyTitle = 'No data', emptyDescription = 'Nothing to show yet.',
}) {
  const [sortKey, setSortKey] = useState(null)
  const [sortDir, setSortDir] = useState('asc')

  const sortedRows = useMemo(() => {
    if (!sortKey) return rows
    return [...rows].sort((a, b) => {
      const av = a[sortKey]
      const bv = b[sortKey]
      if (av === bv) return 0
      const cmp = av > bv ? 1 : -1
      return sortDir === 'asc' ? cmp : -cmp
    })
  }, [rows, sortKey, sortDir])

  const toggleSort = (col) => {
    if (!col.sortable) return
    if (sortKey === col.key) {
      setSortDir((d) => (d === 'asc' ? 'desc' : 'asc'))
    } else {
      setSortKey(col.key)
      setSortDir('asc')
    }
  }

  if (!rows || rows.length === 0) {
    if (emptyState) return emptyState

    // A filter that matched nothing is not an empty table. Say which it is, and
    // offer the only action that helps in each case.
    return filtered
      ? <EmptyState
          title="Nothing matched"
          description="No rows match the current search or filters."
          action={onClearFilters && (
            <button type="button" onClick={onClearFilters}
              className="text-xs font-bold rounded-lg"
              style={{ padding: '8px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', color: '#a78bfa' }}>
              Clear filters
            </button>
          )}
        />
      : <EmptyState title={emptyTitle} description={emptyDescription} />
  }

  return (
    <div className="table-wrapper">
      <table className="table">
        <thead>
          <tr>
            {columns.map((col) => (
              <th
                key={col.key}
                onClick={() => toggleSort(col)}
                style={{ textAlign: col.align || 'left', cursor: col.sortable ? 'pointer' : 'default' }}
              >
                <span className="inline-flex items-center gap-1">
                  {col.label}
                  {col.sortable && sortKey === col.key && (
                    sortDir === 'asc' ? <ChevronUp size={12} /> : <ChevronDown size={12} />
                  )}
                </span>
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {sortedRows.map((row) => (
            <tr
              key={row[keyField]}
              onClick={onRowClick ? () => onRowClick(row) : undefined}
              style={onRowClick ? { cursor: 'pointer' } : undefined}
            >
              {columns.map((col) => (
                <td key={col.key} style={{ textAlign: col.align || 'left' }}>
                  {col.render ? col.render(row) : row[col.key]}
                </td>
              ))}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

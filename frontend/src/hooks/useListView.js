import { useState, useMemo } from 'react'

/**
 * Search + page-size for a list that's already fully loaded.
 *
 * Every sales/customer/accounts list endpoint returns its whole result set
 * unpaginated, so filtering and limiting client-side costs one request instead of
 * one per keystroke — and it means a page gets search without the backend needing
 * a `search` parameter it currently doesn't have.
 *
 * Searching is a substring match, case- and diacritic-insensitive, across the
 * fields a page names. Nested paths work ('client.company'), because most lists
 * show a joined name rather than an own column.
 *
 * @param rows   the full array from the API
 * @param fields dotted paths to search, e.g. ['reference','client.company']
 * @returns { search, setSearch, pageSize, setPageSize, visible, matched, total }
 *          `visible` is search-filtered AND page-limited; `matched` is the count
 *          before limiting, so the toolbar can say "12 of 40".
 */
const norm = (v) =>
  String(v ?? '')
    .toLowerCase()
    // Strip accents so "Jose" finds "José".
    .normalize('NFD').replace(/[\u0300-\u036f]/g, '')

const at = (obj, path) => path.split('.').reduce((o, k) => (o == null ? o : o[k]), obj)

export function useListView(rows, fields = [], { initialPageSize = 25 } = {}) {
  const [search, setSearch] = useState('')
  const [pageSize, setPageSize] = useState(initialPageSize)

  const list = Array.isArray(rows) ? rows : []

  const matchedRows = useMemo(() => {
    const q = norm(search).trim()
    if (!q) return list
    // Every whitespace-separated term must appear somewhere, so "acme unpaid"
    // narrows instead of matching either word.
    const terms = q.split(/\s+/)
    return list.filter((row) => {
      const haystack = fields.map((f) => norm(at(row, f))).join(' ')
      return terms.every((t) => haystack.includes(t))
    })
  }, [list, search, fields.join('|')])

  // 0 means "All" in ListControls.
  const visible = useMemo(
    () => (pageSize > 0 ? matchedRows.slice(0, pageSize) : matchedRows),
    [matchedRows, pageSize],
  )

  return {
    search, setSearch,
    pageSize, setPageSize,
    visible,
    matched: matchedRows.length,
    total: list.length,
  }
}

export default useListView

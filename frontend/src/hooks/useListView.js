import { useState, useMemo, useCallback } from 'react'

/**
 * Search + pagination for a list that's already fully loaded.
 *
 * Every sales/customer/accounts list endpoint returns its whole result set
 * unpaginated, so filtering and paging client-side costs one request instead of
 * one per keystroke — and it means a page gets search without the backend needing
 * a `search` parameter it currently doesn't have.
 *
 * Searching is a substring match, case- and diacritic-insensitive, across the
 * fields a page names. Nested paths work ('client.company'), because most lists
 * show a joined name rather than an own column.
 *
 * PAGING: this used to return only the FIRST pageSize rows with no way to reach
 * the rest. The toolbar's "25 / 50 / 100 / All" selector looked like pagination,
 * so a 60-row list showed 25 rows, reported "60 records", and offered no next
 * page — rows 26-60 were unreachable unless the user happened to try "All". The
 * page number below is the fix, and `pager` carries everything ListToolbar needs
 * to draw the control.
 *
 * @param rows   the full array from the API
 * @param fields dotted paths to search, e.g. ['reference','client.company']
 * @returns { search, setSearch, pageSize, setPageSize, page, setPage,
 *            visible, matched, total, pageCount, from, to, pager }
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
  const [search, setSearchRaw] = useState('')
  const [pageSize, setPageSizeRaw] = useState(initialPageSize)
  const [page, setPage] = useState(1)

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
  const pageCount = pageSize > 0 ? Math.max(1, Math.ceil(matchedRows.length / pageSize)) : 1

  // Clamped rather than reset by an effect: when a filter narrows 4 pages down
  // to 1 while you are on page 3, deriving the page keeps you on the last real
  // one instead of rendering an empty table for a render, then correcting.
  const safePage = Math.min(Math.max(1, page), pageCount)

  const visible = useMemo(() => {
    if (pageSize <= 0) return matchedRows
    const start = (safePage - 1) * pageSize
    return matchedRows.slice(start, start + pageSize)
  }, [matchedRows, pageSize, safePage])

  // Typing a new search or changing the page size must return you to the first
  // page — otherwise you land on page 3 of a result that now has one page.
  const setSearch = useCallback((v) => { setSearchRaw(v); setPage(1) }, [])
  const setPageSize = useCallback((v) => { setPageSizeRaw(v); setPage(1) }, [])

  const from = matchedRows.length === 0 ? 0 : (pageSize > 0 ? (safePage - 1) * pageSize + 1 : 1)
  const to   = pageSize > 0 ? Math.min(safePage * pageSize, matchedRows.length) : matchedRows.length

  return {
    search, setSearch,
    pageSize, setPageSize,
    page: safePage, setPage,
    visible,
    matched: matchedRows.length,
    total: list.length,
    pageCount, from, to,
    // One object to hand ListToolbar, so a page cannot wire up half a pager.
    pager: { page: safePage, pageCount, from, to, onPage: setPage, count: matchedRows.length },
  }
}

export default useListView

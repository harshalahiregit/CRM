/**
 * Past decisions from SangoeTrack — the other half of every approval screen.
 *
 * The pending queue answers "what is waiting on me". This answers "what did we
 * decide, and why" — which is most of what an HR record is actually for, and
 * what the CRM could not show at all until these endpoints existed.
 *
 * Filters and the page live here rather than in each screen so that all four
 * behave identically: changing a filter always returns to page one, because
 * staying on page 7 of a result set that now has 2 pages shows an empty list
 * and looks like a failure.
 */

import { useState, useEffect, useCallback, useMemo } from 'react'
import { sangoeTrackApi } from '@/services/sangoeTrackApi'

/** YYYY-MM-DD, local — not toISOString(), which shifts the date near midnight. */
export function isoDate(d) {
  const p = n => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`
}

/** @param kind 'attendance' | 'corrections' | 'leaves' | 'reimbursements' | 'advances' */
export default function useTrackHistory(kind, initial = {}) {
  const [filters, setFilters] = useState({ status: '', employee: '', from: '', to: '', ...initial })
  const [page, setPage]       = useState(1)
  const [rows, setRows]       = useState([])
  const [meta, setMeta]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [error, setError]     = useState(null)

  const params = useMemo(() => {
    const p = { page, per_page: 25 }
    Object.entries(filters).forEach(([k, v]) => { if (v !== '' && v != null) p[k] = v })
    return p
  }, [filters, page])

  const load = useCallback(async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await sangoeTrackApi.history[kind](params)
      setRows(Array.isArray(res?.data?.rows) ? res.data.rows : [])
      setMeta(res?.data?.meta ?? null)
    } catch (err) {
      setError(err)
      setRows([])
    } finally {
      setLoading(false)
    }
  }, [kind, params])

  useEffect(() => { load() }, [load])

  /** Any filter change resets to page one — see the note above. */
  const setFilter = useCallback((key, value) => {
    setFilters(prev => ({ ...prev, [key]: value }))
    setPage(1)
  }, [])

  const clear = useCallback(() => {
    setFilters({ status: '', employee: '', from: '', to: '', ...initial })
    setPage(1)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const active = Object.entries(filters).filter(([, v]) => v !== '' && v != null).length

  return { rows, meta, loading, error, filters, setFilter, clear, active, page, setPage, reload: load }
}

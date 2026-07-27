import { useState, useEffect, useCallback } from 'react'
import { hrApi } from '@/services/hrApi'

/**
 * Shared HR master-data cache — the SINGLE source of truth for every Recruitment
 * dropdown. Loads GET /hr/master-data ONCE per session and reuses it across all HR
 * pages (departments, designations, grades, roles, shifts, business_units,
 * employee_levels, managers, locations, projects). Projects come from the Project
 * module's own service — never duplicated here. Call refreshMasterData() after an
 * Organization Setup OR Project CRUD so every mounted consumer re-reads the masters.
 */
const EMPTY = {
  departments: [], designations: [], grades: [], roles: [],
  shifts: [], business_units: [], employee_levels: [], managers: [], locations: [],
  projects: [], // sourced from the Project module (single source of truth), active-only
}

let cache = null            // resolved master-data payload
let inflight = null         // in-flight promise (dedupe concurrent first loads)
const listeners = new Set() // mounted consumers to notify on refresh

function load(force = false) {
  if (cache && !force) return Promise.resolve(cache)
  if (inflight && !force) return inflight
  inflight = hrApi.masterData()
    .then((d) => {
      cache = { ...EMPTY, ...(d || {}) }
      inflight = null
      listeners.forEach((l) => l(cache))
      return cache
    })
    .catch((e) => { inflight = null; throw e })
  return inflight
}

/**
 * Build `<select>` option pairs [{value,label}] from an active master list while
 * keeping a saved-but-now-inactive value visible and MARKED "· Inactive" (never
 * dropped) so existing records keep their data. `names` = active master names,
 * `saved` = the record's current value.
 */
export function withInactive(names, saved) {
  const list = (names || []).map((n) => ({ value: n, label: n }))
  if (saved && !list.some((o) => o.value === saved)) {
    list.unshift({ value: saved, label: `${saved} · Inactive` })
  }
  return list
}

/** Invalidate + reload the cache; notifies every mounted consumer. Call after Org Setup CRUD. */
export function refreshMasterData() {
  cache = null
  return load(true).catch(() => {})
}

/** Returns { masters, loading, refresh }. Names resolve live; inactive masters are already excluded server-side. */
export function useMasterData() {
  const [masters, setMasters] = useState(cache || EMPTY)
  const [loading, setLoading] = useState(!cache)

  useEffect(() => {
    let alive = true
    const onUpdate = (d) => { if (alive) setMasters(d) }
    listeners.add(onUpdate)

    if (cache) {
      setMasters(cache); setLoading(false)
    } else {
      setLoading(true)
      load()
        .then((d) => { if (alive) { setMasters(d); setLoading(false) } })
        .catch(() => { if (alive) setLoading(false) })
    }

    return () => { alive = false; listeners.delete(onUpdate) }
  }, [])

  const refresh = useCallback(() => refreshMasterData(), [])
  return { masters, loading, refresh }
}

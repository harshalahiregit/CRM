import { useCallback, useEffect, useState } from 'react'

/**
 * The people a doctor has ticked, kept while they move between pages.
 *
 * The selection used to live in the examination page's own state, which was
 * fine while everything was one long scrolling page and useless the moment it
 * became three: tick eleven workers, open the form, come back, and the ticks
 * are gone.
 *
 * sessionStorage rather than localStorage on purpose. A selection is a session
 * — "these are the people in front of me this morning" — and a tab reopened
 * tomorrow should not start with yesterday's queue already ticked.
 *
 * Scoped per audience: a set of TPV workers means nothing once the doctor has
 * switched to examining visitors, so each keeps its own.
 */

const KEY = (scope) => `doctor.selection.${scope}`

const read = (scope) => {
  try {
    const raw = sessionStorage.getItem(KEY(scope))
    const parsed = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

export function useExamSelection(scope) {
  const [selected, setSelected] = useState(() => read(scope))

  // Following the audience switch, rather than carrying one audience's ticks
  // into another where the ids mean different people.
  useEffect(() => { setSelected(read(scope)) }, [scope])

  useEffect(() => {
    try { sessionStorage.setItem(KEY(scope), JSON.stringify(selected)) } catch { /* private window */ }
  }, [scope, selected])

  const clear = useCallback(() => setSelected([]), [])

  const remove = useCallback(
    (id) => setSelected(prev => prev.filter(x => String(x) !== String(id))),
    [],
  )

  return { selected, setSelected, clear, remove }
}

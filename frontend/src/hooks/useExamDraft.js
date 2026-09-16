import { useCallback, useEffect, useRef, useState } from 'react'

/**
 * Keep what a doctor has typed, until it is saved.
 *
 * The examination form held everything in component state and nothing else.
 * That meant a half-filled form was destroyed by any of: opening a second
 * person, switching audience, tapping Dashboard to check something, a stray
 * back gesture, an iPad discarding the tab to reclaim memory, or a refresh.
 * Twenty fields of vitals, gone, with no warning and nothing to recover — and
 * the doctor is standing in front of the next person in the queue, so it gets
 * retyped from memory or not at all.
 *
 * A draft is per PERSON, not per form: a doctor working through a group leaves
 * one part-finished and comes back to it, and a single "last draft" slot would
 * hand them somebody else's numbers, which is worse than losing them.
 *
 * What is deliberately NOT kept: the signature, the camera photo and the
 * location. Those three are the evidence that this doctor was with this person
 * at this moment, so they are captured fresh every time. A restored signature
 * would be a signature nobody gave.
 */

const PREFIX = 'doctor.draft.'

/** Drafts older than this belong to a week that is over. */
const KEEP_DAYS = 7

const keyFor = (scope, personId) => `${PREFIX}${scope}.${personId}`

const read = (key) => {
  try {
    const raw = localStorage.getItem(key)
    if (!raw) return null
    const parsed = JSON.parse(raw)
    if (!parsed?.savedAt) return null
    if (Date.now() - parsed.savedAt > KEEP_DAYS * 864e5) {
      localStorage.removeItem(key)
      return null
    }
    return parsed
  } catch {
    // A corrupt or unreadable draft must never stop the form loading — a blank
    // sheet is a bad outcome, a page that will not render is a worse one.
    return null
  }
}

const write = (key, value) => {
  try {
    localStorage.setItem(key, JSON.stringify({ savedAt: Date.now(), value }))
  } catch {
    // Quota, or a private window with storage switched off. Losing the draft is
    // the old behaviour; breaking the form would be new and worse.
  }
}

const drop = (key) => {
  try { localStorage.removeItem(key) } catch { /* see write() */ }
}

/**
 * @param scope     what the draft belongs to — the audience/module being examined
 * @param personId  who is being examined; '' means no form is open
 * @param value     everything the form holds, as one serialisable object
 * @param onRestore called with the stored value when one is found
 * @param dirty     whether anything has actually been entered
 *
 * `dirty` is what makes the DRAFT marker mean something. An untouched form is
 * not unfinished work, and storing one would mark every person a doctor merely
 * opened as having something waiting — a badge that appears everywhere is a
 * badge nobody reads.
 */
export function useExamDraft(scope, personId, value, onRestore, dirty = true) {
  const [restoredAt, setRestoredAt] = useState(null)
  const timer = useRef(null)
  // Suppresses the save that the restore itself would otherwise trigger.
  const loading = useRef(false)
  // What this hook still owes to storage, so leaving can commit it.
  const owed = useRef({ key: null, value: null, dirty: false })
  // A person whose examination has just been FILED. Their draft is gone on
  // purpose, and without this the flush on the way out would write it straight
  // back on top of the record that replaced it — listing them as having
  // unfinished work they have in fact just completed.
  const filed = useRef(null)

  const key = personId ? keyFor(scope, personId) : null

  /* ── Restore on opening somebody's form ───────────────────────────────── */

  useEffect(() => {
    setRestoredAt(null)
    // A new person is a clean slate for all of this.
    filed.current = null
    if (!key) return

    const stored = read(key)
    if (!stored) return

    loading.current = true
    onRestore(stored.value)
    setRestoredAt(stored.savedAt)
    const t = setTimeout(() => { loading.current = false }, 0)
    return () => clearTimeout(t)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])

  /* ── Save as they type ────────────────────────────────────────────────── */

  // Debounced: writing on every keystroke would serialise the whole form thirty
  // times a field for no benefit.
  //
  // A form emptied back to blank REMOVES its draft rather than storing an empty
  // one — which is also what makes "Start blank" work without a special case.
  useEffect(() => {
    if (!key || loading.current || filed.current === key) return

    clearTimeout(timer.current)
    timer.current = setTimeout(() => (dirty ? write(key, value) : drop(key)), 400)

    return () => clearTimeout(timer.current)
  }, [key, value, dirty])

  /**
   * Commit what is still owed before moving to the next person.
   *
   * Without this, a doctor who typed and immediately pressed "Save & next" lost
   * the last few keystrokes — the debounce had not fired, and clearing its timer
   * on the way out threw them away. Losing exactly the field somebody typed last
   * is the worst thing a draft can do, because it is the field they will most
   * confidently assume was kept.
   *
   * `owed` still describes the OUTGOING person when this runs: the restore
   * above only queues state updates, so the value in this render is theirs.
   */
  useEffect(() => {
    const previous = owed.current

    if (previous.key && previous.key !== key && filed.current !== previous.key) {
      clearTimeout(timer.current)
      previous.dirty ? write(previous.key, previous.value) : drop(previous.key)
    }

    owed.current = { key, value, dirty }
  }, [key, value, dirty])

  // The tab being hidden, closed or discarded — the case where no React
  // lifecycle runs at all, which on a tablet is the common one.
  useEffect(() => {
    const flush = () => {
      const { key: k, value: v, dirty: d } = owed.current
      if (k && d && !loading.current && filed.current !== k) {
        clearTimeout(timer.current)
        write(k, v)
      }
    }

    window.addEventListener('pagehide', flush)
    document.addEventListener('visibilitychange', flush)

    return () => {
      window.removeEventListener('pagehide', flush)
      document.removeEventListener('visibilitychange', flush)
      flush()
    }
  }, [])

  /** The examination has been filed — the draft has served its purpose. */
  const clear = useCallback(() => {
    if (!key) return
    clearTimeout(timer.current)
    filed.current = key
    owed.current = { key: null, value: null, dirty: false }
    drop(key)
    setRestoredAt(null)
  }, [key])

  /**
   * Stop offering the restored draft, without discarding anything.
   *
   * For "Start blank": the form resets, `dirty` goes false, and the stored
   * draft is removed by the effect above. Nothing here needs to know that.
   */
  const dismiss = useCallback(() => setRestoredAt(null), [])

  return { restoredAt, clear, dismiss }
}

/** Which people in a group have something part-typed — shown on the picker. */
export function draftedIds(scope, ids) {
  return ids.filter(id => read(keyFor(scope, id)) !== null)
}

/** Every draft for one audience, so a doctor can be told where they left off. */
export function countDrafts(scope) {
  try {
    return Object.keys(localStorage)
      .filter(k => k.startsWith(`${PREFIX}${scope}.`))
      .filter(k => read(k) !== null)
      .length
  } catch {
    return 0
  }
}

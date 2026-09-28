import { useCallback } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'

/**
 * A back arrow that goes back.
 *
 * Detail pages across the CRM hard-wired their back button to the module's list
 * screen — "Back to tasks" always went to /app/tasks, "Back to projects" always
 * went to /app/projects. So opening a task from a project, a ticket, a search
 * result or a notification and pressing back put you somewhere you had never
 * been, with different filters, several clicks from where you actually were.
 *
 * Enumerating the possible origins was the wrong shape of fix: it only knows
 * the routes somebody remembered to teach it, and every new screen that links
 * to a detail page silently goes back to being wrong. The browser already knows
 * where you came from.
 *
 * `history.state.idx` is React Router's own counter for this tab's session.
 * Zero means this page IS the session — a pasted link, a new tab, a link from
 * an email — and there is nothing behind it. navigate(-1) there either does
 * nothing or throws the user out of the application, so that case needs a real
 * destination, which is what `fallback` is for.
 *
 * `location.state.backTo` / `backLabel` still win for the label when a linking
 * screen took the trouble to say where it was sending you from, because "Back
 * to project tasks" reads better than "Back" — but the navigation itself is
 * history either way, so the label can never promise a destination the click
 * does not deliver.
 *
 *   const { goBack, backLabel } = useGoBack('/app/tasks', 'Back to tasks')
 *   <button onClick={goBack}><ArrowLeft /> {backLabel}</button>
 *
 * @param {string} fallback      where to go when there is no history behind this page
 * @param {string} fallbackLabel what to call that destination
 */
export function useGoBack(fallback, fallbackLabel = 'Back') {
  const navigate = useNavigate()
  const location = useLocation()

  const stateBack = location.state?.backTo || null
  const stateBackLabel = location.state?.backLabel || null

  // Read at call time, not at render: a click is the only moment this matters,
  // and by then the entry is settled.
  const canGoBack = () => typeof window !== 'undefined' && (window.history.state?.idx ?? 0) > 0

  const goBack = useCallback(() => {
    if (canGoBack()) { navigate(-1); return }
    navigate(stateBack || fallback)
  }, [navigate, stateBack, fallback])

  const hasHistory = canGoBack()

  return {
    goBack,
    hasHistory,
    backLabel: stateBackLabel || (hasHistory ? 'Back' : fallbackLabel),
  }
}

export default useGoBack

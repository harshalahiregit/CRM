import { AlertOctagon, Flag, RotateCw } from 'lucide-react'
import { useLocation } from 'react-router-dom'
import { useReportIssue } from '@/context/SireContextProvider'

/**
 * What a crashed page shows — including the way to report it.
 *
 * WHY THIS EXISTS. The app had exactly one ErrorBoundary, at the top of
 * App.jsx, ABOVE the providers and above AppShell. So when any page threw, the
 * boundary replaced the entire application: no sidebar, no header, and no
 * Report Issue button, because that button lives inside AppShell. The rule is
 * that Report Issue is on every screen, and the one screen it was missing from
 * was the screen a user most wants it on — the one that just broke in front of
 * them. Their only option was to describe a white page from memory.
 *
 * So the boundary now also sits INSIDE the shell, around the page outlet, and
 * renders this. The shell survives, which means the user can navigate away,
 * and Report Issue is still in the corner where it always is.
 *
 * AND THE REPORT IS PRE-FILLED. A crash is the one defect where the reporter
 * has nothing useful to type — "it went white" — while the browser is holding
 * the exact message and stack. Those go into the report instead of being
 * thrown away when the user reloads. `useReportIssue` is the safe accessor: it
 * returns a no-op rather than throwing when the provider is absent, which
 * matters here, because a component that throws while rendering an error is a
 * blank page with no way out.
 */
export default function PageErrorFallback({ error, onRetry }) {
  const openReportIssue = useReportIssue()
  const { pathname } = useLocation()

  const message = error?.message || 'An unexpected error occurred while rendering this page.'

  const report = () => {
    openReportIssue({
      title: `Page crashed: ${message}`.slice(0, 120),
      description: [
        'This page failed to render and showed the error screen.',
        '',
        `Route: ${pathname}`,
        `Error: ${message}`,
        '',
        'Stack:',
        // Bounded: a stack can run to thousands of characters and the rest of
        // it is framework frames nobody reads.
        (error?.stack || '(no stack captured)').split('\n').slice(0, 12).join('\n'),
      ].join('\n'),
    })
  }

  return (
    <div className="flex flex-col items-center justify-center min-h-[50vh] gap-4 text-center px-4">
      <div
        className="w-16 h-16 rounded-3xl flex items-center justify-center"
        style={{ background: 'rgba(239,68,68,0.12)', border: '1px solid rgba(239,68,68,0.25)' }}
      >
        <AlertOctagon size={28} style={{ color: '#f87171' }} />
      </div>

      <div>
        <h2 className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Something went wrong</h2>
        <p className="text-sm mt-1 max-w-md" style={{ color: 'var(--text-muted)' }}>{message}</p>
        <p className="text-xs mt-2 max-w-md" style={{ color: 'var(--text-faint)' }}>
          The rest of the app still works — use the menu to go somewhere else, or report this
          and the error details are attached for you.
        </p>
      </div>

      <div className="flex flex-wrap items-center justify-center gap-2">
        <button onClick={onRetry} className="btn-3d inline-flex items-center gap-2">
          <RotateCw size={14} /> Try again
        </button>
        <button
          type="button"
          onClick={report}
          className="inline-flex items-center gap-2 rounded-full px-4 py-2.5 text-sm font-medium transition"
          style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
        >
          <Flag size={14} /> Report this issue
        </button>
      </div>
    </div>
  )
}

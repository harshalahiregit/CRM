import { useCallback, useState } from 'react'

// Guard against losing typed data when a modal is dismissed by an accidental
// backdrop click. If the form has unsaved changes, confirm before closing;
// a pristine (untouched) form still closes instantly so nothing gets in the way.

// --- Legacy helper: native browser confirm. Kept for non-modal call sites. ---
export function guardedClose(onClose, dirty, message = 'Discard your changes? What you entered will be lost.') {
  if (dirty && typeof window !== 'undefined' && !window.confirm(message)) return
  onClose?.()
}

/**
 * Styled in-app replacement for the browser's window.confirm "Discard changes?"
 * popup. Returns `{ guard, dialog }`:
 *   - `guard(onClose, dirty)` — closes instantly when pristine; otherwise opens
 *     a themed confirm dialog and only runs `onClose` if the user confirms.
 *   - `dialog` — render this once inside the modal's JSX (it self-portals above
 *     the modal via a high z-index; renders nothing until a close is pending).
 *
 * Usage:
 *   const { guard, dialog } = useDiscardGuard()
 *   const requestClose = () => guard(onClose, dirty())
 *   return (<>
 *     <div className="overlay" onClick={requestClose}>…</div>
 *     {dialog}
 *   </>)
 */
export function useDiscardGuard() {
  // `pending` holds the onClose fn awaiting confirmation. useState treats a bare
  // function as an updater, so it's always stored/read wrapped in an object.
  const [pending, setPending] = useState(null)

  const guard = useCallback((onClose, dirty) => {
    if (dirty) setPending({ run: onClose || (() => {}) })
    else onClose?.()
  }, [])

  const dialog = pending ? (
    <div
      className="fixed inset-0 z-[90] flex items-start justify-center p-4 pt-[20vh] bg-black/50"
      onClick={() => setPending(null)}
    >
      <div
        className="w-full max-w-sm rounded-2xl overflow-hidden"
        style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}
        onClick={e => e.stopPropagation()}
      >
        <div className="px-5 pt-4 pb-3">
          <h2 className="font-bold mb-1" style={{ color: 'var(--text-h)', fontSize: 15 }}>Discard changes?</h2>
          <p className="text-xs leading-relaxed" style={{ color: 'var(--text-muted)' }}>
            What you entered will be lost.
          </p>
        </div>
        <div className="flex items-center justify-end gap-2 px-5 py-3" style={{ background: 'var(--bg-input)', borderTop: '1px solid var(--border)' }}>
          <button onClick={() => setPending(null)} className="text-xs font-semibold px-4 py-2 rounded-xl" style={{ color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
            Keep editing
          </button>
          <button onClick={() => { const fn = pending.run; setPending(null); fn?.() }} className="text-xs font-bold px-4 py-2 rounded-xl" style={{ background: 'var(--color-danger-500)', color: '#fff' }}>
            Discard
          </button>
        </div>
      </div>
    </div>
  ) : null

  return { guard, dialog }
}

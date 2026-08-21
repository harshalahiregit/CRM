import { useState, useRef, useEffect } from 'react'

/**
 * A button that cannot be double-submitted.
 *
 * The codebase already guards 302 buttons with a local `saving`/`busy` flag,
 * which works but has to be repeated at every call site — and roughly eighty
 * buttons were missed, so clicking Save twice created two records.
 *
 * This holds the pending state itself: it awaits whatever `onClick` returns and
 * stays disabled until that settles. A synchronous handler still works (an
 * awaited non-promise resolves immediately), so it is a drop-in replacement.
 *
 * Pass `busy` to drive it from outside instead, for the cases that already have
 * a flag; the two are OR-ed, so an external guard is never weakened.
 */
export default function AsyncButton({
  onClick,
  busy = false,
  disabled = false,
  pendingLabel,
  children,
  ...rest
}) {
  const [pending, setPending] = useState(false)
  // A handler that navigates away unmounts us mid-flight; setting state then
  // would warn and leak, so writes stop once we are gone.
  const alive = useRef(true)
  useEffect(() => () => { alive.current = false }, [])

  const run = async (e) => {
    if (pending || busy || disabled) return
    setPending(true)
    try {
      await onClick?.(e)
    } finally {
      if (alive.current) setPending(false)
    }
  }

  const isBusy = pending || busy

  return (
    <button
      {...rest}
      type={rest.type ?? 'button'}
      onClick={run}
      disabled={isBusy || disabled}
      aria-busy={isBusy || undefined}
      style={{ ...rest.style, opacity: (isBusy || disabled) ? 0.6 : rest.style?.opacity, cursor: (isBusy || disabled) ? 'not-allowed' : rest.style?.cursor }}
    >
      {isBusy && pendingLabel ? pendingLabel : children}
    </button>
  )
}

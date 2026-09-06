/**
 * Opening an attachment that sits behind a Bearer token.
 *
 * The browser will not attach an Authorization header to an <img src> or a
 * followed <a href>, so authenticated files are fetched as a blob and handed to
 * a new tab as an object URL. That URL is revoked on a timer rather than
 * immediately: revoking it in the same tick races the tab that is still opening,
 * and the tab wins about as often as it loses.
 *
 * The two sides pass different fetchers — the employee's own endpoint and the
 * admin one — so this file never decides which claims somebody may read.
 */

import { useCallback, useState } from 'react'

/** Long enough for a slow tab to load the bytes, short enough not to leak. */
const REVOKE_AFTER_MS = 60_000

export default function useAttachmentOpener(fetcher) {
  const [opening, setOpening] = useState(null)
  const [error,   setError]   = useState(null)

  const open = useCallback(async attachment => {
    if (!attachment?.id) return

    setOpening(attachment.id)
    setError(null)
    try {
      const blob = await fetcher(attachment.id)
      const url  = URL.createObjectURL(blob)

      // A popup blocker can refuse this, which is silent — window.open returns
      // null rather than throwing, so it has to be checked.
      const tab = window.open(url, '_blank', 'noopener,noreferrer')
      if (!tab) setError('Your browser blocked the new tab. Allow pop-ups to view attachments.')

      setTimeout(() => URL.revokeObjectURL(url), REVOKE_AFTER_MS)
    } catch {
      setError('That file could not be opened.')
    } finally {
      setOpening(null)
    }
  }, [fetcher])

  return { open, opening, error, clearError: () => setError(null) }
}

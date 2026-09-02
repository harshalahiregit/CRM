/**
 * Open a file that sits behind a Bearer token.
 *
 * The browser attaches no Authorization header to an <a href> it follows or an
 * <img src> it loads, so any route behind auth:sanctum answers 401 to both. The
 * bytes have to be fetched by the axios client — which does carry the token —
 * and handed to a new tab as an object URL.
 *
 * Several screens got this wrong the same way, each building a URL string for an
 * <a href>, so this lives in one place rather than being reimplemented per page.
 *
 * The object URL is revoked on a timer rather than immediately: revoking it in
 * the same tick races the tab that is still loading it, and the tab loses often
 * enough to matter.
 */

/** Long enough for a slow tab, short enough not to leak. */
const REVOKE_AFTER_MS = 60_000

/**
 * @param {(id:any)=>Promise<Blob>} fetchBlob  the api call, e.g. hrApi.leave.applications.attachmentBlob
 * @param {any} id
 * @param {(msg:string, type?:string)=>void} [onError] optional; called with a message a person can act on
 */
export async function openAuthedFile(fetchBlob, id, onError) {
  try {
    const blob = await fetchBlob(id)
    const url  = URL.createObjectURL(blob)

    // window.open returns null when a pop-up blocker steps in — silently, so it
    // has to be checked rather than assumed.
    const tab = window.open(url, '_blank', 'noopener,noreferrer')
    if (!tab) onError?.('Your browser blocked the new tab. Allow pop-ups to view attachments.', 'error')

    setTimeout(() => URL.revokeObjectURL(url), REVOKE_AFTER_MS)
  } catch {
    onError?.('That attachment could not be opened.', 'error')
  }
}

export default openAuthedFile

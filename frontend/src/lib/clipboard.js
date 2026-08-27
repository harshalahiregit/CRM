/**
 * Copy text to the clipboard, robustly.
 *
 * navigator.clipboard only exists in a SECURE context (https or localhost); over
 * plain http on a LAN IP it is undefined and throws, which is the usual reason
 * "Copy link" silently fails in dev/on-prem. This falls back to the legacy
 * execCommand path and reports success/failure so callers can show the raw text
 * when even that fails.
 *
 * @returns {Promise<boolean>} true if the text reached the clipboard.
 */
export async function copyText(text) {
  const value = String(text ?? '')
  try {
    if (navigator?.clipboard?.writeText && window.isSecureContext) {
      await navigator.clipboard.writeText(value)
      return true
    }
  } catch { /* fall through to the legacy path */ }

  try {
    const ta = document.createElement('textarea')
    ta.value = value
    ta.setAttribute('readonly', '')
    ta.style.position = 'fixed'
    ta.style.top = '-9999px'
    document.body.appendChild(ta)
    ta.select()
    const ok = document.execCommand('copy')
    document.body.removeChild(ta)
    return ok
  } catch {
    return false
  }
}

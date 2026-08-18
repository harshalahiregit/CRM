// Shared OAuth 2.0 "implicit" popup helper for the client-side cloud pickers.
//
// Providers that hand the access token back in the URL fragment (pCloud, and any
// future provider using the same flow) open an authorize URL in a popup; the popup
// lands on /cloud-oauth.html, which postMessages the fragment back here. We parse
// the token out and resolve. Google and OneDrive have their own SDK token flows and
// do NOT use this helper.

/** The same-origin page the provider redirects the popup to after consent. */
export function callbackUrl() {
  return `${window.location.origin}/cloud-oauth.html`
}

/** Parse a URL fragment/query string ("#a=1&b=2" or "?a=1") into a plain object. */
function parseParams(str) {
  const out = {}
  const s = (str || '').replace(/^[#?]/, '')
  if (!s) return out
  for (const pair of s.split('&')) {
    const [k, v] = pair.split('=')
    if (k) out[decodeURIComponent(k)] = decodeURIComponent(v || '')
  }
  return out
}

/**
 * Open `authorizeUrl` in a centred popup and resolve with the parsed token params
 * once the callback page reports back. Rejects if the user closes the popup, the
 * provider returns an error, or nothing arrives within the timeout.
 */
export function openOAuthPopup(authorizeUrl, { timeoutMs = 120000 } = {}) {
  return new Promise((resolve, reject) => {
    const w = 520
    const h = 640
    const left = window.screenX + Math.max(0, (window.outerWidth - w) / 2)
    const top = window.screenY + Math.max(0, (window.outerHeight - h) / 2)
    const popup = window.open(
      authorizeUrl,
      'cloud-oauth',
      `width=${w},height=${h},left=${left},top=${top},menubar=no,toolbar=no,location=no,status=no`,
    )
    if (!popup) {
      reject(new Error('Popup blocked. Allow pop-ups for this site and try again.'))
      return
    }

    let settled = false
    const cleanup = () => {
      window.removeEventListener('message', onMessage)
      clearInterval(closedTimer)
      clearTimeout(timer)
    }
    const done = (fn, arg) => { if (!settled) { settled = true; cleanup(); try { popup.close() } catch {} fn(arg) } }

    const onMessage = (e) => {
      if (e.origin !== window.location.origin) return
      if (!e.data || e.data.source !== 'cloud-oauth') return
      const params = { ...parseParams(e.data.payload) }
      if (params.error) done(reject, new Error(params.error_description || params.error))
      else if (params.access_token) done(resolve, params)
      else done(reject, new Error('No access token was returned.'))
    }
    window.addEventListener('message', onMessage)

    // The user closed the popup before finishing.
    const closedTimer = setInterval(() => {
      if (popup.closed) done(reject, new Error('Sign-in was cancelled.'))
    }, 500)

    const timer = setTimeout(() => done(reject, new Error('Sign-in timed out.')), timeoutMs)
  })
}

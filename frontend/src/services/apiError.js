/**
 * Shared API error normaliser.
 *
 * Every service used to do this:
 *
 *   const msg = err?.response?.data?.message || 'Something went wrong'
 *   throw new Error(msg)
 *
 * which THREW AWAY Laravel's field-level `errors` object. A 422 therefore
 * surfaced as the useless generic "The given data was invalid." and the user had
 * no idea which input was wrong.
 *
 * This builds a message that already carries the per-field detail plus an
 * actionable tip, so the ~400 existing `toast.error(e.message)` call sites all
 * improve without being touched. The structured pieces are also attached to the
 * Error (`fields`, `fieldErrors`, `status`, `tip`, `title`) so a form can
 * highlight inputs, and Toast can render them as distinct lines.
 */

/** How many field errors to spell out before collapsing into "and N more". */
const MAX_LISTED = 4

/** `client_id` / `line_items.0.qty` -> "Client", "Line items → item 1 → qty". */
export function prettyField(path) {
  return String(path)
    .split('.')
    .map((seg) => (/^\d+$/.test(seg) ? `item ${Number(seg) + 1}` : seg.replace(/_id$/, '').replace(/_/g, ' ')))
    .join(' → ')
    .replace(/^./, (c) => c.toUpperCase())
}

/** A short, actionable next step based on the HTTP status. */
function tipFor(status, hasFields) {
  if (hasFields) return 'Correct the fields listed above, then submit again.'
  switch (status) {
    case 401: return 'Your session has expired — sign in again.'
    case 403: return "You don't have permission for this action. Ask an admin if you need access."
    case 404: return 'That record no longer exists. Refresh the page and try again.'
    case 409: return 'Someone changed this record while you were editing. Reload to get the latest version.'
    case 419: return 'Your session token expired — reload the page and retry.'
    case 429: return 'Too many attempts in a row. Wait a moment before trying again.'
    case 500:
    case 502:
    case 503: return 'The server hit an error. If it keeps happening, report the time it occurred.'
    default:  return null
  }
}

/**
 * Normalise an axios error into a rich Error.
 * Always throws — services use it as `.catch(handleErr)`.
 */
export function handleErr(err) {
  const res    = err?.response
  const status = res?.status ?? 0
  const data   = res?.data ?? {}

  // Laravel validation shape: { message, errors: { field: [msg, ...] } }
  const raw = data.errors && typeof data.errors === 'object' && !Array.isArray(data.errors) ? data.errors : null
  const fields = raw
    ? Object.entries(raw).map(([path, msgs]) => ({
        path,
        label: prettyField(path),
        message: Array.isArray(msgs) ? msgs[0] : String(msgs),
      }))
    : []
  const hasFields = fields.length > 0

  // Framework/boilerplate headlines carry no information, so they get replaced.
  // Anything else the server sends is a message written for this exact failure
  // (ApiErrorMapper, BusinessException) and is more useful than a field count —
  // e.g. "Email is required" beats "Please check 1 field".
  const GENERIC_TITLES = ['validation failed', 'the given data was invalid.', 'server error', 'error']
  const serverMsg = typeof data.message === 'string' ? data.message.trim() : ''
  const serverMsgIsUseful = serverMsg !== '' && !GENERIC_TITLES.includes(serverMsg.toLowerCase())

  let title
  if (serverMsgIsUseful) {
    title = serverMsg
  } else if (hasFields) {
    title = fields.length === 1
      ? 'Please check 1 field'
      : `Please check ${fields.length} fields`
  } else if (!res) {
    // No response at all — the request never reached the server.
    title = err?.message?.includes('timeout')
      ? 'The server took too long to respond'
      : "Can't reach the server"
  } else {
    title = data.message || data.error || `Request failed (${status})`
  }

  // The server may send a hint specific to the failure (ApiErrorMapper does);
  // prefer it over the generic status-based line.
  const tip = !res
    ? 'Check that the backend is running and your connection is up.'
    : (typeof data.hint === 'string' && data.hint.trim() ? data.hint.trim() : tipFor(status, hasFields))

  // Compose the flat message so untouched `toast.error(e.message)` calls still
  // show the detail. Toast splits this back into title / details / tip.
  const shown  = fields.slice(0, MAX_LISTED)
  const hidden = fields.length - shown.length
  const detail = shown.map((f) => `• ${f.label}: ${f.message}`)
  if (hidden > 0) detail.push(`• …and ${hidden} more`)

  const error = new Error([title, ...detail, tip ? `Tip: ${tip}` : ''].filter(Boolean).join('\n'))
  error.name        = 'ApiError'
  error.title       = title
  error.status      = status
  error.fields      = fields                                   // [{path,label,message}]
  error.fieldErrors = raw ?? null                              // raw Laravel shape
  error.tip         = tip
  error.details     = detail
  throw error
}

export default handleErr

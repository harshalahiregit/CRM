/**
 * What went wrong, in words the person reading the screen can act on.
 *
 * Roughly 230 files reach into `error.response.data.message` by hand, and each
 * one guesses differently at what to do when that key is missing. The worst
 * shape is the common one:
 *
 *     catch (e) { alert(e?.response?.data?.message || 'Failed to save') }
 *
 * On a 422 from this API that prints the literal string "Validation failed" —
 * `message` is hard-coded to exactly that in bootstrap/app.php, and the field
 * and the reason live in `errors`, which the line above throws away. On a form
 * with thirty fields the person is told only that something is wrong.
 *
 * This is the one place that knows the shape. It is deliberately small and
 * dependency-free so that adopting it is a one-line change per call site.
 *
 * THE SHAPES IT HAS TO HANDLE, all of which exist in this backend:
 *
 *   422  {status:'error', message:'Validation failed', errors:{field:[msg]}}
 *   422  Laravel's own default, {message:'The given data...', errors:{...}}
 *   4xx  {status:'error', message:'Cannot delete "Director" — 5 employees...'}
 *        — a real sentence written for a human; it must survive intact
 *   403  {status:'error', message:'You are not authorised to...'}
 *   5xx  often an exception message, which must NOT reach a user
 *   none  network failure, CORS, timeout — error.response is undefined
 */

/** Laravel repeats the raw field name inside the sentence; the label says it already. */
const stripFieldPrefix = (msg) => String(msg).replace(/^The .+? field /, '')

/** `required_skills.3` → `required skills (entry 4)`. Humans count from one. */
export function humanFieldName(field) {
  const indexed = String(field).match(/^(.+)\.(\d+)$/)

  return indexed
    ? `${indexed[1].replace(/[_.]/g, ' ')} (entry ${Number(indexed[2]) + 1})`
    : String(field).replace(/[_.]/g, ' ')
}

/**
 * The validation errors as {field: 'first message'}, or null when this is not a
 * validation failure. Keys are the API's own, so a form can map them to inputs.
 */
export function fieldErrors(error) {
  const errors = error?.response?.data?.errors

  if (!errors || typeof errors !== 'object' || Array.isArray(errors)) return null

  const out = {}
  for (const [field, messages] of Object.entries(errors)) {
    const first = Array.isArray(messages) ? messages[0] : messages
    if (first) out[field] = stripFieldPrefix(first)
  }

  return Object.keys(out).length ? out : null
}

/** The field a form should focus first — the API's key, not the label. */
export function firstInvalidField(error) {
  const errors = fieldErrors(error)

  return errors ? Object.keys(errors)[0] : null
}

/**
 * One line suitable for a toast.
 *
 * A server sentence is preferred over anything invented here: "Cannot delete
 * “Director” — 5 employee(s) still hold this designation" tells somebody what to
 * do, and replacing it with "Something went wrong" is a downgrade the user pays
 * for. Only the cases where the server has nothing useful to say get a fallback.
 */
export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
  // No response at all: the request never landed.
  if (error && !error.response) {
    return 'Could not reach the server. Check your connection and try again.'
  }

  const status = error?.response?.status
  const data = error?.response?.data
  const message = typeof data?.message === 'string' ? data.message : null

  if (status === 422) {
    const errors = fieldErrors(error)

    if (errors) {
      const [field, first] = Object.entries(errors)[0]
      const more = Object.keys(errors).length - 1

      return `${humanFieldName(field)}: ${first}${more > 0 ? ` (and ${more} more)` : ''}`
    }
  }

  if (status === 403) return message || 'You do not have permission to do that.'
  if (status === 404) return message || 'That record no longer exists.'
  if (status === 419) return 'Your session expired. Please sign in again.'

  // A 5xx message is an exception string. Never show it.
  if (status >= 500) return fallback

  return message || fallback
}

/**
 * Every validation problem, as lines for a summary block.
 * Returns [] when the failure is not a validation failure.
 */
export function errorSummary(error) {
  const errors = fieldErrors(error)

  if (!errors) return []

  return Object.entries(errors).map(([field, msg]) => `${humanFieldName(field)}: ${msg}`)
}

/**
 * Render a rich-text value for display — the browser-side twin of
 * App\Support\RichText::toHtml().
 *
 * Notes / descriptions / terms only recently became notepad (rich text) fields.
 * Rows saved before that are plain text whose line breaks were supplied by a
 * `whitespace-pre-wrap` class on the element. Feeding those straight into
 * dangerouslySetInnerHTML collapses every break into one run-on paragraph, so a
 * value with no markup is escaped and its newlines become <br> instead.
 *
 * Values that DO contain markup were sanitized server-side by HtmlSanitizer
 * before being stored, so they pass through untouched.
 *
 * Usage: <div className="rich-content" dangerouslySetInnerHTML={richHtml(v)} />
 * — it returns the whole `{ __html }` object so a call site can't forget the wrapper.
 */
const escapeHtml = (s) =>
  s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

export function richHtml(value) {
  const v = value == null ? '' : String(value)
  if (!v.trim()) return { __html: '' }
  // No angle brackets at all → it can't be markup, so treat it as legacy plain text.
  const looksLikeHtml = /<[a-z][\s\S]*>/i.test(v)
  return { __html: looksLikeHtml ? v : escapeHtml(v).replace(/\n/g, '<br>') }
}

export default richHtml

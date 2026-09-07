/**
 * Text that may be rich, rendered as rich.
 *
 * Several fields in this app hold either HTML or plain text depending on who
 * wrote the row: staff compose in a rich editor, portal users type into a plain
 * input. The portal screens printed the stored string as text, so a staff
 * reply of "hii" arrived as the literal characters `<p>hii</p><p><br></p>`, and
 * a governance action item arrived as a wall of `<span style=...>` followed by
 * a base64 image src.
 *
 * The server sends the `*_html` twin already run through the allowlist
 * sanitizer (App\Support\RichText) — which is what makes setting it here safe —
 * plus a plain-text twin used as the fallback for any response that predates
 * that field.
 *
 * `.rich-content` is the app's existing stylesheet for sanitized HTML, so this
 * renders the same way the admin screens do rather than inventing a second look.
 *
 * @param {string} html   sanitized HTML from the server (preferred)
 * @param {string} text   plain-text fallback
 * @param {string} clamp  optional: 'line' to keep it to a single line
 */
export default function RichText({ html, text, style, className = '', clamp }) {
  const clamped = clamp === 'line'
    ? { display: '-webkit-box', WebkitLineClamp: 1, WebkitBoxOrient: 'vertical', overflow: 'hidden' }
    : null

  if (html) {
    return (
      <div
        className={`rich-content ${className}`.trim()}
        style={{ ...style, ...clamped }}
        dangerouslySetInnerHTML={{ __html: html }}
      />
    )
  }

  return <div className={className} style={{ whiteSpace: 'pre-wrap', ...style, ...clamped }}>{text}</div>
}

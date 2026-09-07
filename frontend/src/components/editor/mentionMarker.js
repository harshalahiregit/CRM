/**
 * What an @mention leaves behind in rich text.
 *
 * A mention used to be inserted as ordinary text — "@Priya Sharma " — and the
 * server then had to work out who was meant by matching that text against
 * everybody's name. It matched on the FULL name, character for character, so:
 *
 *   - "@Priya" reached nobody, because the record says "Priya Sharma";
 *   - "@Priya Sharma" reached nobody either whenever the editor put a
 *     non-breaking space between the words, which browsers do routinely;
 *   - and a short name matched inside ordinary words, so a colleague called
 *     "Dr" was notified by "@Drive the update over".
 *
 * Picking a person from a list already knows exactly who they are, so the
 * insertion now carries their id and the server reads it directly. Renames,
 * middle names, nicknames and whichever kind of space the editor used stop
 * mattering. Plain "@Name" text is still understood, for a comment typed
 * without the picker.
 *
 * `data-mention` survives the server's HTML sanitizer, which allows it on
 * <span> for exactly this purpose. An id arriving this way is a claim, not a
 * fact — the server re-checks it against the roster before notifying anyone.
 */

/** The class the marker carries, so it can be styled where it is rendered. */
export const MENTION_CLASS = 'mention-chip'

const escapeHtml = (s) => String(s)
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

/**
 * Put a mention into a Quill editor at `index`.
 *
 * Falls back to plain text when the person has no id (a free-text entry, or a
 * list that only supplies labels) — the server's name matching still covers it.
 *
 * @param {object} quill   the Quill instance
 * @param {number} index   where the "@" was
 * @param {object} person  { id, name } — id is what makes this reliable
 * @param {string} name    the display name, already normalised
 */
export function insertMentionIntoQuill(quill, index, person, name) {
  const id = person?.id
  if (!id) {
    quill.insertText(index, `@${name} `, 'user')
    quill.setSelection(index + name.length + 2, 0, 'user')
    return
  }

  // dangerouslyPasteHTML rather than insertText: the marker has to reach the
  // server as markup. It is built here from an escaped name and a numeric id,
  // never from anything a user typed as HTML.
  quill.clipboard.dangerouslyPasteHTML(
    index,
    `<span class="${MENTION_CLASS}" data-mention="${Number(id)}">@${escapeHtml(name)}</span>&nbsp;`,
    'user',
  )
  // Past the chip and its trailing space, so typing continues normally.
  quill.setSelection(index + name.length + 2, 0, 'user')
}

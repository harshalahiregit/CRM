/**
 * Print a lead's information sheet.
 *
 * The old CRM cloned the on-screen profile panel and restyled it in place, which
 * meant the printout inherited whatever the app's theme happened to be — dark mode
 * printed a black page. This builds a clean, self-contained light document in a
 * hidden iframe instead, so what prints is predictable regardless of theme, and
 * nothing on the live page is touched.
 *
 * An iframe rather than window.open: no popup blocker, and no risk of leaving a
 * stray tab behind if the user cancels the dialog.
 */
const esc = (v) => String(v ?? '')
  .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;')

const money = (v) => '₹' + Number(v || 0).toLocaleString('en-IN')
const date = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

/** Only render rows that have a value — empty labels are noise on paper. */
const rows = (pairs) => pairs
  .filter(([, v]) => v !== null && v !== undefined && String(v).trim() !== '' && String(v) !== '—')
  .map(([k, v]) => `<tr><th>${esc(k)}</th><td>${esc(v)}</td></tr>`)
  .join('')

export function printLead(lead, customFields = []) {
  const status = lead.status?.name || '—'
  const flags = [
    lead.date_converted ? 'CONVERTED' : null,
    lead.lost ? 'LOST' : null,
    lead.junk ? 'JUNK' : null,
  ].filter(Boolean)

  const contact = rows([
    ['Name', lead.name],
    ['Title', lead.title],
    ['Company', lead.company],
    ['Email', lead.email],
    ['Phone', lead.phone],
    ['Website', lead.website],
  ])

  const address = rows([
    ['Address', lead.address],
    ['City', lead.city],
    ['State', lead.state],
    ['Zip', lead.zip],
    ['Country', lead.country],
  ])

  const pipeline = rows([
    ['Status', status],
    ['Source', lead.source?.name],
    ['Value', lead.lead_value ? money(lead.lead_value) : null],
    ['Temperature', lead.lead_temperature],
    ['Score', lead.lead_score],
    ['Assigned to', lead.assigned_user?.name],
    ['Created', date(lead.created_at)],
    ['Last contact', lead.last_contact_date ? date(lead.last_contact_date) : null],
    ['Converted', lead.date_converted ? date(lead.date_converted) : null],
    ['Lost reason', lead.lost_reason],
    ['Junk reason', lead.junk_reason],
  ])

  const custom = rows((customFields || []).map(f => [f.name, f.value]))

  const section = (title, body) => body
    ? `<h2>${esc(title)}</h2><table>${body}</table>`
    : ''

  const html = `<!doctype html>
<html><head><meta charset="utf-8"><title>Lead — ${esc(lead.name || '')}</title>
<style>
  /* Deliberately theme-independent: printouts are always light. */
  * { box-sizing: border-box; }
  body { font: 13px/1.55 Arial, Helvetica, sans-serif; color: #1e293b; margin: 32px; background: #fff; }
  h1 { font-size: 20px; margin: 0 0 2px; }
  .sub { color: #64748b; font-size: 12px; margin: 0 0 4px; }
  .flags { margin: 8px 0 0; }
  .flag { display: inline-block; font-size: 10px; font-weight: bold; letter-spacing: .06em;
          border: 1px solid #cbd5e1; border-radius: 4px; padding: 2px 6px; margin-right: 6px; color: #475569; }
  hr { border: 0; border-top: 1px solid #e2e8f0; margin: 16px 0; }
  h2 { font-size: 12px; text-transform: uppercase; letter-spacing: .08em; color: #7c3aed; margin: 18px 0 6px; }
  table { width: 100%; border-collapse: collapse; }
  th, td { text-align: left; vertical-align: top; padding: 5px 0; border-bottom: 1px solid #f1f5f9; }
  th { width: 34%; color: #64748b; font-weight: normal; }
  td { color: #0f172a; font-weight: 600; }
  .desc { border: 1px solid #e2e8f0; border-radius: 6px; padding: 10px; }
  .desc img { max-width: 100%; }
  footer { margin-top: 24px; color: #94a3b8; font-size: 11px; }
  @media print { body { margin: 0; } @page { margin: 18mm; } }
</style></head><body>
  <h1>${esc(lead.name || 'Lead')}</h1>
  <p class="sub">${esc(lead.company || lead.title || '')}</p>
  ${flags.length ? `<p class="flags">${flags.map(f => `<span class="flag">${esc(f)}</span>`).join('')}</p>` : ''}
  <hr>
  ${section('Contact', contact)}
  ${section('Address', address)}
  ${section('Pipeline', pipeline)}
  ${section('Custom fields', custom)}
  ${lead.description ? `<h2>Description</h2><div class="desc">${lead.description}</div>` : ''}
  <footer>Printed ${esc(new Date().toLocaleString('en-IN'))}</footer>
</body></html>`

  const frame = document.createElement('iframe')
  frame.setAttribute('aria-hidden', 'true')
  frame.style.cssText = 'position:fixed;right:0;bottom:0;width:0;height:0;border:0;'
  document.body.appendChild(frame)

  const doc = frame.contentDocument
  doc.open(); doc.write(html); doc.close()

  const cleanup = () => frame.remove()
  frame.contentWindow.addEventListener('afterprint', cleanup)

  // Wait for layout (and any embedded images) before printing, then remove the
  // frame. The timeout is a backstop for browsers that never fire afterprint.
  frame.contentWindow.focus()
  setTimeout(() => {
    try { frame.contentWindow.print() } catch { cleanup() }
    setTimeout(cleanup, 60000)
  }, 250)
}

export default printLead

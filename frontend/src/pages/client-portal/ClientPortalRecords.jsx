import { useState, useEffect } from 'react'
import { useSearchParams } from 'react-router-dom'
import { clientPortalApi } from '@/lib/clientPortalApi'

/**
 * Every record list in the portal.
 *
 * Ten screens that differ only in their columns and endpoint do not need ten
 * files — they need one table and ten column definitions. A new section is a
 * few lines below rather than another near-identical page to keep in step.
 *
 * A 403 is rendered as "not shared with you", not as an error: the server
 * refusing a section the contact was never granted is correct behaviour, and
 * showing it as a failure would suggest something is broken.
 */
const money = (v, row) =>
  v == null ? '—'
    : new Intl.NumberFormat('en-IN', { style: 'currency', currency: row?.currency || 'INR', maximumFractionDigits: 2 }).format(Number(v) || 0)

const date = (v) => (v ? new Date(v).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—')

const STATUS_TONE = {
  Paid: '#10b981', Accepted: '#10b981', finished: '#10b981', closed: '#10b981', Active: '#10b981',
  Overdue: '#ef4444', Declined: '#ef4444', Expired: '#ef4444',
  Sent: '#3b82f6', open: '#3b82f6', in_progress: '#3b82f6', 'in-progress': '#3b82f6',
  'Partially Paid': '#f59e0b', on_hold: '#f59e0b', not_started: '#9ca3af',
}

function Status({ value }) {
  if (!value) return <span style={{ color: 'var(--text-muted,#9ca3af)' }}>—</span>
  const c = STATUS_TONE[value] ?? '#a78bfa'
  return (
    <span style={{ display: 'inline-block', padding: '2px 9px', borderRadius: 20, fontSize: 11, fontWeight: 700,
      color: c, background: `${c}1f`, border: `1px solid ${c}55`, whiteSpace: 'nowrap' }}>
      {String(value).replace(/_/g, ' ')}
    </span>
  )
}

export const RECORD_VIEWS = {
  invoices: { title: 'Invoices', fetch: (f) => clientPortalApi.invoices(f), cols: [
    { k: 'number', h: 'Invoice', bold: true }, { k: 'date', h: 'Date', fmt: date },
    { k: 'due_date', h: 'Due', fmt: date }, { k: 'total', h: 'Total', fmt: money, right: true },
    { k: 'balance', h: 'Balance', fmt: money, right: true }, { k: 'status', h: 'Status', status: true },
  ]},
  payments: { title: 'Payments', fetch: () => clientPortalApi.payments(), cols: [
    { k: 'date', h: 'Date', fmt: date }, { k: 'invoice_number', h: 'Invoice', bold: true },
    { k: 'amount', h: 'Amount', fmt: money, right: true }, { k: 'mode', h: 'Mode' },
  ]},
  'credit-notes': { title: 'Credit Notes', fetch: () => clientPortalApi.creditNotes(), cols: [
    { k: 'number', h: 'Number', bold: true }, { k: 'date', h: 'Date', fmt: date },
    { k: 'total', h: 'Total', fmt: money, right: true }, { k: 'status', h: 'Status', status: true },
  ]},
  estimates: { title: 'Estimates', fetch: () => clientPortalApi.estimates(), cols: [
    { k: 'reference', h: 'Reference', bold: true }, { k: 'date', h: 'Date', fmt: date },
    { k: 'valid_until', h: 'Valid until', fmt: date }, { k: 'total', h: 'Total', fmt: money, right: true },
    { k: 'status', h: 'Status', status: true },
  ]},
  proposals: { title: 'Proposals', fetch: () => clientPortalApi.proposals(), cols: [
    { k: 'subject', h: 'Subject', bold: true }, { k: 'created_at', h: 'Sent', fmt: date },
    { k: 'total', h: 'Total', fmt: money, right: true }, { k: 'status', h: 'Status', status: true },
  ]},
  contracts: { title: 'Contracts', fetch: () => clientPortalApi.contracts(), cols: [
    { k: 'subject', h: 'Contract', bold: true }, { k: 'contract_type', h: 'Type' },
    { k: 'start_date', h: 'Start', fmt: date }, { k: 'end_date', h: 'Ends', fmt: date },
    { k: 'value', h: 'Value', fmt: money, right: true }, { k: 'status', h: 'Status', status: true },
  ]},
  projects: { title: 'Projects', fetch: () => clientPortalApi.projects(), cols: [
    { k: 'name', h: 'Project', bold: true }, { k: 'start_date', h: 'Start', fmt: date },
    { k: 'deadline', h: 'Deadline', fmt: date },
    { k: 'progress', h: 'Progress', fmt: (v) => (v == null ? '—' : `${v}%`), right: true },
    { k: 'status', h: 'Status', status: true },
  ]},
  tickets: { title: 'Support Tickets', fetch: () => clientPortalApi.tickets(), cols: [
    { k: 'subject', h: 'Subject', bold: true }, { k: 'created_at', h: 'Raised', fmt: date },
    { k: 'priority', h: 'Priority' }, { k: 'status', h: 'Status', status: true },
  ]},
  files: { title: 'Files', fetch: () => clientPortalApi.files(), cols: [
    { k: 'name', h: 'File', bold: true }, { k: 'at', h: 'Uploaded', fmt: date },
    { k: 'size', h: 'Size', right: true, fmt: (v) => (v ? `${Math.max(1, Math.round(v / 1024))} KB` : '—') },
  ]},
  notes: { title: 'Notes', fetch: () => clientPortalApi.notes(), cols: [
    { k: 'content', h: 'Note', html: true }, { k: 'type', h: 'Type' }, { k: 'at', h: 'Added', fmt: date },
  ]},
  contacts: { title: 'Your Team', fetch: () => clientPortalApi.contacts(), cols: [
    { k: 'first_name', h: 'Name', bold: true, fmt: (_, r) => [r.first_name, r.last_name].filter(Boolean).join(' ') },
    { k: 'title', h: 'Designation' }, { k: 'department', h: 'Department' }, { k: 'email', h: 'Email' },
  ]},
}

/**
 * Raise a support ticket.
 *
 * The portal does not create the ticket — it asks Helpdesk to, through the
 * server-side TicketIntakeContract, so numbering, SLA, department routing and
 * the acknowledgement email all happen the way Helpdesk does them everywhere
 * else. This form only collects the three things it can honestly supply.
 *
 * Priority is offered as a hint and labelled as one: Helpdesk caps it and
 * agents re-triage, so promising the customer their choice sticks would be a
 * lie. Urgent is deliberately absent from the list.
 */
function RaiseTicket({ onRaised }) {
  const [open, setOpen] = useState(false)
  const [subject, setSubject] = useState('')
  const [body, setBody] = useState('')
  const [priority, setPriority] = useState('')
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState('')

  const close = () => { setOpen(false); setErr(''); setSubject(''); setBody(''); setPriority('') }

  const submit = async () => {
    if (!subject.trim() || !body.trim()) { setErr('Please give the ticket a subject and describe the problem.'); return }
    setSaving(true); setErr('')
    try {
      await clientPortalApi.raiseTicket({ subject: subject.trim(), body: body.trim(), priority: priority || undefined })
      close()
      onRaised()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not raise the ticket. Please try again.')
    } finally { setSaving(false) }
  }

  const field = {
    width: '100%', padding: '9px 11px', borderRadius: 9, fontSize: 13, boxSizing: 'border-box',
    background: 'var(--bg-input,#0d0f15)', border: '1px solid var(--border,#2a2f3a)', color: 'var(--text-h,#fff)',
  }
  const label = { display: 'block', fontSize: 11, fontWeight: 700, textTransform: 'uppercase',
    letterSpacing: '.05em', color: 'var(--text-muted,#9ca3af)', margin: '0 0 5px' }

  if (!open) {
    return (
      <button type="button" onClick={() => setOpen(true)}
        style={{ padding: '8px 14px', borderRadius: 10, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
          background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', border: 'none' }}>
        Raise a ticket
      </button>
    )
  }

  return (
    <div role="dialog" aria-label="Raise a support ticket"
      style={{ position: 'fixed', inset: 0, zIndex: 200, background: 'rgba(0,0,0,.6)',
        display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 18 }}
      onClick={(e) => { if (e.target === e.currentTarget && !saving) close() }}>
      <div style={{ width: 'min(520px,96vw)', maxHeight: '90vh', overflowY: 'auto', padding: 22, borderRadius: 16,
        background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)' }}>
        <h2 style={{ fontSize: 16, fontWeight: 800, color: 'var(--text-h,#fff)', margin: '0 0 4px' }}>Raise a support ticket</h2>
        <p style={{ fontSize: 12, color: 'var(--text-muted,#9ca3af)', margin: '0 0 16px' }}>
          We will email you a confirmation and reply to the address on your account.
        </p>

        <div style={{ marginBottom: 13 }}>
          <label style={label} htmlFor="rt-subject">Subject *</label>
          <input id="rt-subject" style={field} value={subject} maxLength={191}
            onChange={(e) => setSubject(e.target.value)} placeholder="Short summary of the problem" />
        </div>

        <div style={{ marginBottom: 13 }}>
          <label style={label} htmlFor="rt-body">What is happening? *</label>
          <textarea id="rt-body" rows={5} style={{ ...field, resize: 'vertical' }} value={body} maxLength={10000}
            onChange={(e) => setBody(e.target.value)}
            placeholder="What you were doing, what you expected, and what happened instead." />
        </div>

        <div style={{ marginBottom: 16 }}>
          <label style={label} htmlFor="rt-priority">How urgent is it?</label>
          <select id="rt-priority" style={field} value={priority} onChange={(e) => setPriority(e.target.value)}>
            <option value="">Let the support team decide</option>
            <option value="low">Low — no rush</option>
            <option value="medium">Medium — normal</option>
            <option value="high">High — blocking work</option>
          </select>
          {/* Said plainly rather than discovered later. */}
          <p style={{ fontSize: 11, color: 'var(--text-muted,#9ca3af)', margin: '5px 0 0' }}>
            A guide for our team — they may adjust it after reading the ticket.
          </p>
        </div>

        {err && <p style={{ fontSize: 12.5, color: '#ef4444', margin: '0 0 12px' }}>{err}</p>}

        <div style={{ display: 'flex', gap: 9, justifyContent: 'flex-end' }}>
          <button type="button" onClick={close} disabled={saving}
            style={{ padding: '9px 15px', borderRadius: 10, fontSize: 12.5, fontWeight: 700, cursor: 'pointer',
              background: 'var(--bg-input,#0d0f15)', border: '1px solid var(--border,#2a2f3a)', color: 'var(--text-muted,#9ca3af)' }}>
            Cancel
          </button>
          <button type="button" onClick={submit} disabled={saving}
            style={{ padding: '9px 15px', borderRadius: 10, fontSize: 12.5, fontWeight: 700,
              cursor: saving ? 'default' : 'pointer', opacity: saving ? .7 : 1,
              background: 'linear-gradient(135deg,#7C3AED,#6d28d9)', color: '#fff', border: 'none' }}>
            {saving ? 'Sending…' : 'Raise ticket'}
          </button>
        </div>
      </div>
    </div>
  )
}

export default function ClientPortalRecords({ view }) {
  const cfg = RECORD_VIEWS[view]
  const [params] = useSearchParams()
  const [rows, setRows] = useState(null)
  const [denied, setDenied] = useState(false)
  const [err, setErr] = useState('')
  const [raised, setRaised] = useState(0)
  // Only offered once Helpdesk has bound a real intake — otherwise the button
  // could only ever fail, which is worse than not showing it.
  const [canRaise, setCanRaise] = useState(false)

  useEffect(() => {
    if (view !== 'tickets') return
    let alive = true
    clientPortalApi.me()
      .then((m) => { if (alive) setCanRaise(!!m?.can_raise_ticket) })
      .catch(() => { if (alive) setCanRaise(false) })
    return () => { alive = false }
  }, [view])

  useEffect(() => {
    let alive = true
    setRows(null); setDenied(false); setErr('')
    cfg.fetch(params.get('filter'))
      .then((d) => { if (alive) setRows(Array.isArray(d) ? d : (d?.data ?? [])) })
      .catch((e) => {
        if (!alive) return
        // Not an error — the server correctly refusing a section nobody granted.
        if (e?.response?.status === 403) setDenied(true)
        else setErr(e?.response?.data?.message || 'Could not load this section.')
      })
    return () => { alive = false }
    // cfg is RECORD_VIEWS[view] — a module-level const, so its reference is
    // stable for a given view and cannot retrigger this on its own.
  }, [view, params, cfg, raised])

  const card = { background: 'var(--bg-card,#12141b)', border: '1px solid var(--border,#2a2f3a)', borderRadius: 14 }

  return (
    <div style={{ maxWidth: 1100 }}>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 12, flexWrap: 'wrap', margin: '0 0 16px' }}>
        <h1 style={{ fontSize: 21, fontWeight: 800, color: 'var(--text-h,#fff)', margin: 0 }}>{cfg.title}</h1>
        {view === 'tickets' && canRaise && !denied && (
          <RaiseTicket onRaised={() => setRaised((n) => n + 1)} />
        )}
      </div>

      {denied ? (
        <div style={{ ...card, padding: 22 }}>
          <p style={{ fontSize: 13, fontWeight: 600, color: 'var(--text-h,#fff)', margin: 0 }}>This section has not been shared with you.</p>
          <p style={{ fontSize: 12, color: 'var(--text-muted,#9ca3af)', margin: '5px 0 0' }}>
            Ask your account manager if you need access to {cfg.title.toLowerCase()}.
          </p>
        </div>
      ) : err ? (
        <div style={{ ...card, padding: 22, color: '#ef4444', fontSize: 13 }}>{err}</div>
      ) : rows === null ? (
        <div style={{ ...card, padding: 22, color: 'var(--text-muted,#9ca3af)', fontSize: 13 }}>Loading…</div>
      ) : rows.length === 0 ? (
        <div style={{ ...card, padding: 22, color: 'var(--text-muted,#9ca3af)', fontSize: 13 }}>
          Nothing here yet.
        </div>
      ) : (
        <div style={{ ...card, overflowX: 'auto' }}>
          <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 560 }}>
            <thead>
              <tr>
                {cfg.cols.map((c) => (
                  <th key={c.k} style={{ textAlign: c.right ? 'right' : 'left', padding: '11px 16px', fontSize: 10.5,
                    fontWeight: 800, letterSpacing: '.06em', textTransform: 'uppercase',
                    color: 'var(--text-muted,#9ca3af)', borderBottom: '1px solid var(--border,#2a2f3a)', whiteSpace: 'nowrap' }}>
                    {c.h}
                  </th>
                ))}
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={r.id ?? i}>
                  {cfg.cols.map((c) => (
                    <td key={c.k} style={{ padding: '11px 16px', fontSize: 12.5, borderBottom: '1px solid var(--border,#2a2f3a)',
                      color: c.bold ? 'var(--text-h,#fff)' : 'var(--text-body,#cbd5e1)', fontWeight: c.bold ? 700 : 400,
                      textAlign: c.right ? 'right' : 'left', fontVariantNumeric: c.right ? 'tabular-nums' : 'normal' }}>
                      {c.status
                        ? <Status value={r[c.k]} />
                        : c.html
                          // Note bodies are rich text, already sanitised server-side
                          // by HtmlSanitizer before they were ever stored.
                          ? <span dangerouslySetInnerHTML={{ __html: r[c.k] ?? '' }} />
                          : c.fmt ? c.fmt(r[c.k], r) : (r[c.k] ?? '—')}
                    </td>
                  ))}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

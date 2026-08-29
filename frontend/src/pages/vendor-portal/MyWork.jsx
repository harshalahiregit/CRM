import { useEffect, useState } from 'react'
import { Loader2, Plus, X, Send } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — "My Work": Projects, Tasks, Tickets and Expenses. Projects are
 * read-only; Tasks let the vendor advance status; Tickets can be raised and
 * replied to; Expenses can be logged against the vendor's own projects. Data
 * comes from the role-gated my-work endpoints.
 */
export default function MyWork({ view, api = portalApi, caps = { ticketWrite: true } }) {
  switch (view) {
    case 'tasks':    return <Tasks api={api} />
    case 'tickets':  return <Tickets api={api} caps={caps} />
    case 'expenses': return <Expenses api={api} />
    default:         return <Projects api={api} />
  }
}

const date = v => (v ? String(v).slice(0, 10) : '—')
const TONE = { completed: 'ok', done: 'ok', closed: 'muted', resolved: 'ok', active: 'info', in_progress: 'info', open: 'warn', pending: 'warn', not_started: 'muted', on_hold: 'warn', overdue: 'bad', cancelled: 'bad' }
function Pill({ value }) {
  const tone = TONE[String(value ?? '').toLowerCase()] || 'muted'
  const bg = { ok: 'rgba(34,197,94,0.15)', info: 'rgba(59,130,246,0.15)', warn: 'rgba(245,158,11,0.15)', bad: 'rgba(239,68,68,0.15)', muted: 'rgba(148,163,184,0.15)' }[tone]
  const fg = { ok: '#22c55e', info: '#3b82f6', warn: '#f59e0b', bad: '#ef4444', muted: '#94a3b8' }[tone]
  return <span style={{ padding: '2px 10px', borderRadius: 999, fontSize: 11, fontWeight: 700, textTransform: 'capitalize', background: bg, color: fg }}>{String(value ?? '—').replace(/_/g, ' ')}</span>
}

/* ── Projects (read) ──────────────────────────────────────────────────────── */
function Projects({ api }) {
  const [rows, setRows] = useState(null)
  useEffect(() => { api.myWork.projects().then(d => setRows(d || [])).catch(() => setRows([])) }, [])
  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 className="mw-h2">My Projects</h2>
      {rows === null ? <Center /> : rows.length === 0 ? <Empty /> : (
        <Table head={['Project', 'Role', 'Progress', 'Deadline', 'Status']}>
          {rows.map(r => (
            <tr key={r.id}><td className="mw-strong">{r.name}</td><td>{r.role}</td><td>{r.progress ?? 0}%</td><td>{date(r.deadline)}</td><td><Pill value={r.status} /></td></tr>
          ))}
        </Table>
      )}
    </Wrap>
  )
}

/* ── Tasks (status write) ─────────────────────────────────────────────────── */
function Tasks({ api }) {
  const [rows, setRows] = useState(null)
  const [statuses, setStatuses] = useState({})
  useEffect(() => {
    api.myWork.tasks().then(d => setRows(d || [])).catch(() => setRows([]))
    api.myWork.taskStatuses().then(s => setStatuses(s || {})).catch(() => setStatuses({}))
  }, [])
  const change = async (id, status) => {
    setRows(rs => rs.map(r => r.id === id ? { ...r, status, _saving: true } : r))
    try { await api.myWork.updateTaskStatus(id, status) } catch { /* keep optimistic */ }
    setRows(rs => rs.map(r => r.id === id ? { ...r, _saving: false } : r))
  }
  const keys = Object.keys(statuses)
  return (
    <Wrap>
      <style>{CSS}</style>
      <h2 className="mw-h2">My Tasks</h2>
      {rows === null ? <Center /> : rows.length === 0 ? <Empty /> : (
        <Table head={['Task', 'Project', 'Priority', 'Due', 'Status']}>
          {rows.map(r => (
            <tr key={r.id}>
              <td className="mw-strong">{r.name}</td><td>{r.project || '—'}</td><td>{r.priority || '—'}</td><td>{date(r.due_date)}</td>
              <td>
                {keys.length
                  ? <select className="mw-input" style={{ padding: '4px 8px', width: 'auto' }} value={r.status} disabled={r._saving} onChange={e => change(r.id, e.target.value)}>
                      {keys.map(k => <option key={k} value={k}>{statuses[k]}</option>)}
                    </select>
                  : <Pill value={r.status} />}
              </td>
            </tr>
          ))}
        </Table>
      )}
    </Wrap>
  )
}

/* ── Tickets (raise + reply) ──────────────────────────────────────────────── */
function Tickets({ api, caps }) {
  const [rows, setRows] = useState(null)
  const [raising, setRaising] = useState(false)
  const [openId, setOpenId] = useState(null)
  const reload = () => api.myWork.tickets().then(d => setRows(d || [])).catch(() => setRows([]))
  useEffect(() => { reload() }, [])
  return (
    <Wrap>
      <style>{CSS}</style>
      <div className="mw-head"><h2 className="mw-h2" style={{ margin: 0 }}>My Tickets</h2>{caps?.ticketWrite && <button className="mw-btn mw-btn-primary" onClick={() => setRaising(true)}><Plus size={15} /> Raise Ticket</button>}</div>
      {rows === null ? <Center /> : rows.length === 0 ? <Empty text="No tickets yet. Raise one if you need help." /> : (
        <Table head={['Subject', 'Priority', 'Status']}>
          {rows.map(r => (
            <tr key={r.id} style={{ cursor: caps?.ticketWrite ? 'pointer' : 'default' }} onClick={caps?.ticketWrite ? () => setOpenId(r.id) : undefined}><td className="mw-strong">{r.subject}</td><td>{r.priority || '—'}</td><td><Pill value={r.status} /></td></tr>
          ))}
        </Table>
      )}
      {raising && <RaiseForm api={api} onClose={() => setRaising(false)} onDone={() => { setRaising(false); reload() }} />}
      {openId != null && <TicketModal api={api} id={openId} onClose={() => setOpenId(null)} />}
    </Wrap>
  )
}
function RaiseForm({ api, onClose, onDone }) {
  const [f, setF] = useState({ subject: '', body: '', priority: 'medium' })
  const [saving, setSaving] = useState(false); const [error, setError] = useState('')
  const submit = async () => {
    if (!f.subject.trim() || !f.body.trim()) { setError('Subject and message are required.'); return }
    setSaving(true)
    try { await api.myWork.raiseTicket(f); onDone() } catch (e) { setError(e?.response?.data?.message || 'Could not raise the ticket.') } finally { setSaving(false) }
  }
  return (
    <Modal title="Raise a Ticket" onClose={onClose}>
      <label className="mw-lbl">Subject *<input className="mw-input" value={f.subject} onChange={e => setF({ ...f, subject: e.target.value })} /></label>
      <label className="mw-lbl" style={{ marginTop: 10 }}>Message *<textarea className="mw-input" rows={4} value={f.body} onChange={e => setF({ ...f, body: e.target.value })} style={{ resize: 'vertical' }} /></label>
      <label className="mw-lbl" style={{ marginTop: 10 }}>Priority<select className="mw-input" value={f.priority} onChange={e => setF({ ...f, priority: e.target.value })}><option value="low">Low</option><option value="medium">Medium</option><option value="high">High</option></select></label>
      <Foot error={error} saving={saving} onClose={onClose} onSubmit={submit} label="Raise Ticket" />
    </Modal>
  )
}
function TicketModal({ api, id, onClose }) {
  const [t, setT] = useState(null); const [msg, setMsg] = useState(''); const [sending, setSending] = useState(false)
  const load = () => api.myWork.ticket(id).then(setT).catch(() => setT(null))
  useEffect(() => { load() }, [id]) // eslint-disable-line react-hooks/exhaustive-deps
  const send = async () => {
    if (!msg.trim()) return
    setSending(true)
    try { await api.myWork.replyTicket(id, msg); setMsg(''); await load() } finally { setSending(false) }
  }
  return (
    <Modal title={t?.subject || 'Ticket'} onClose={onClose}>
      {!t ? <Center /> : (
        <>
          <div style={{ display: 'flex', gap: 8, marginBottom: 12 }}><Pill value={t.status} /><span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Priority: {t.priority}</span></div>
          <div style={{ fontSize: 13.5, color: 'var(--text-body,#cbd5e1)', whiteSpace: 'pre-wrap', paddingBottom: 12, borderBottom: '1px solid var(--border,rgba(255,255,255,0.08))' }}>{t.description}</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 8, margin: '12px 0' }}>
            {(t.replies || []).map(r => (
              <div key={r.id} style={{ alignSelf: r.mine ? 'flex-end' : 'flex-start', maxWidth: '80%', background: r.mine ? 'rgba(124,58,237,0.15)' : 'var(--bg-input,rgba(255,255,255,0.05))', borderRadius: 10, padding: '8px 12px' }}>
                <div style={{ fontSize: 10.5, color: 'var(--text-muted)', marginBottom: 2 }}>{r.author}</div>
                <div style={{ fontSize: 13, color: 'var(--text-h)', whiteSpace: 'pre-wrap' }}>{r.message}</div>
              </div>
            ))}
          </div>
          <div style={{ display: 'flex', gap: 8 }}>
            <input className="mw-input" placeholder="Write a reply…" value={msg} onChange={e => setMsg(e.target.value)} onKeyDown={e => e.key === 'Enter' && send()} />
            <button className="mw-btn mw-btn-primary" disabled={sending} onClick={send}>{sending ? <Loader2 className="mw-spin" size={14} /> : <Send size={14} />}</button>
          </div>
        </>
      )}
    </Modal>
  )
}

/* ── Expenses (log) ───────────────────────────────────────────────────────── */
function Expenses({ api }) {
  const [rows, setRows] = useState(null)
  const [projects, setProjects] = useState([])
  const [adding, setAdding] = useState(false)
  const reload = () => api.myWork.expenses().then(d => setRows(d || [])).catch(() => setRows([]))
  useEffect(() => { reload(); api.myWork.projects().then(p => setProjects(p || [])).catch(() => setProjects([])) }, [])
  return (
    <Wrap>
      <style>{CSS}</style>
      <div className="mw-head"><h2 className="mw-h2" style={{ margin: 0 }}>My Expenses</h2><button className="mw-btn mw-btn-primary" onClick={() => setAdding(true)} disabled={projects.length === 0}><Plus size={15} /> Log Expense</button></div>
      {rows === null ? <Center /> : rows.length === 0 ? <Empty text={projects.length === 0 ? 'You need a project before logging expenses.' : 'No expenses logged yet.'} /> : (
        <Table head={['Title', 'Category', 'Date', 'Amount']}>
          {rows.map(r => (
            <tr key={r.id}><td className="mw-strong">{r.title}</td><td>{r.category || '—'}</td><td>{date(r.expense_date)}</td><td>₹{Number(r.amount || 0).toLocaleString('en-IN')}</td></tr>
          ))}
        </Table>
      )}
      {adding && <ExpenseForm api={api} projects={projects} onClose={() => setAdding(false)} onDone={() => { setAdding(false); reload() }} />}
    </Wrap>
  )
}
function ExpenseForm({ api, projects, onClose, onDone }) {
  const [f, setF] = useState({ project_id: projects[0]?.id || '', title: '', category: '', amount: '', expense_date: '', note: '' })
  const [saving, setSaving] = useState(false); const [error, setError] = useState('')
  const submit = async () => {
    if (!f.project_id || !f.title.trim() || f.amount === '') { setError('Project, title and amount are required.'); return }
    setSaving(true)
    try { await api.myWork.logExpense({ ...f, amount: Number(f.amount) }); onDone() } catch (e) { setError(e?.response?.data?.message || 'Could not log the expense.') } finally { setSaving(false) }
  }
  return (
    <Modal title="Log an Expense" onClose={onClose}>
      <div className="mw-grid">
        <label className="mw-lbl">Project *<select className="mw-input" value={f.project_id} onChange={e => setF({ ...f, project_id: e.target.value })}>{projects.map(p => <option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
        <label className="mw-lbl">Title *<input className="mw-input" value={f.title} onChange={e => setF({ ...f, title: e.target.value })} /></label>
        <label className="mw-lbl">Category<input className="mw-input" value={f.category} onChange={e => setF({ ...f, category: e.target.value })} /></label>
        <label className="mw-lbl">Amount (₹) *<input className="mw-input" type="number" min="0" step="0.01" value={f.amount} onChange={e => setF({ ...f, amount: e.target.value })} /></label>
        <label className="mw-lbl">Date<input className="mw-input" type="date" value={f.expense_date} onChange={e => setF({ ...f, expense_date: e.target.value })} /></label>
        <label className="mw-lbl" style={{ gridColumn: '1 / -1' }}>Note<input className="mw-input" value={f.note} onChange={e => setF({ ...f, note: e.target.value })} /></label>
      </div>
      <Foot error={error} saving={saving} onClose={onClose} onSubmit={submit} label="Log Expense" />
    </Modal>
  )
}

/* ── shared ───────────────────────────────────────────────────────────────── */
function Wrap({ children }) { return <div style={{ maxWidth: 940, margin: '0 auto' }}>{children}</div> }
function Center() { return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="mw-spin" size={22} /></div> }
function Empty({ text = 'Nothing assigned yet.' }) { return <div className="mw-card" style={{ textAlign: 'center', color: 'var(--text-muted)', padding: 44, fontSize: 14 }}>{text}</div> }
function Table({ head, children }) {
  return <div className="mw-card" style={{ padding: '8px 4px' }}><div style={{ overflowX: 'auto' }}><table className="mw-table"><thead><tr>{head.map(h => <th key={h}>{h}</th>)}</tr></thead><tbody>{children}</tbody></table></div></div>
}
function Modal({ title, onClose, children }) {
  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)', zIndex: 60, display: 'flex', alignItems: 'flex-start', justifyContent: 'center', padding: '5vh 16px', overflowY: 'auto' }}>
      <div onClick={e => e.stopPropagation()} style={{ width: '100%', maxWidth: 600, background: 'var(--bg-card,#14161c)', border: '1px solid var(--border,rgba(255,255,255,0.1))', borderRadius: 14, overflow: 'hidden' }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, padding: '14px 18px', borderBottom: '1px solid var(--border,rgba(255,255,255,0.08))' }}><strong style={{ color: 'var(--text-h)', flex: 1 }}>{title}</strong><button onClick={onClose} className="mw-iconbtn"><X size={16} /></button></div>
        <div style={{ padding: 18 }}>{children}</div>
      </div>
    </div>
  )
}
function Foot({ error, saving, onClose, onSubmit, label }) {
  return (<>{error && <div style={{ marginTop: 12, color: '#ef4444', fontSize: 13 }}>{error}</div>}<div style={{ display: 'flex', justifyContent: 'flex-end', gap: 10, marginTop: 18 }}><button onClick={onClose} className="mw-btn">Cancel</button><button onClick={onSubmit} disabled={saving} className="mw-btn mw-btn-primary">{saving ? <Loader2 className="mw-spin" size={14} /> : <Send size={14} />} {label}</button></div></>)
}

const CSS = `
.mw-h2 { font-size: 18px; font-weight: 800; color: var(--text-h); margin: 0 0 16px; }
.mw-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; gap: 12px; flex-wrap: wrap; }
.mw-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; }
.mw-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.mw-table th { text-align: left; font-size: 10.5px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); padding: 10px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.08)); white-space: nowrap; }
.mw-table td { padding: 11px 14px; border-bottom: 1px solid var(--border, rgba(255,255,255,0.05)); color: var(--text-body, #cbd5e1); }
.mw-table tbody tr:last-child td { border-bottom: none; }
.mw-table tbody tr:hover { background: var(--bg-input, rgba(255,255,255,0.03)); }
.mw-strong { font-weight: 700; color: var(--text-h); }
.mw-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
.mw-lbl { font-size: 12px; color: var(--text-muted); display: flex; flex-direction: column; gap: 4px; }
.mw-input { width: 100%; background: var(--bg-input, rgba(255,255,255,0.05)); border: 1px solid var(--border, rgba(255,255,255,0.12)); border-radius: 8px; padding: 7px 9px; color: var(--text-h); font-size: 13px; font-family: inherit; }
.mw-input:focus { outline: none; border-color: var(--portal-purple, #7c3aed); }
.mw-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 9px; font-size: 13px; font-weight: 700; cursor: pointer; border: 1px solid var(--border, rgba(255,255,255,0.14)); background: transparent; color: var(--text-h); }
.mw-btn:hover { background: var(--bg-input, rgba(255,255,255,0.05)); }
.mw-btn-primary { background: var(--portal-purple, #7c3aed); border-color: var(--portal-purple, #7c3aed); color: #fff; }
.mw-btn-primary:disabled { opacity: 0.6; cursor: default; }
.mw-iconbtn { background: transparent; border: none; color: var(--text-muted); cursor: pointer; padding: 4px; border-radius: 6px; }
.mw-iconbtn:hover { color: var(--text-h); }
.mw-spin { animation: mw-spin 0.9s linear infinite; }
@keyframes mw-spin { to { transform: rotate(360deg); } }
`

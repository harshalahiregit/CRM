import { useState, useEffect } from 'react'
import { Send, Upload, Calendar, ChevronDown, ChevronRight, FileCheck } from 'lucide-react'

/**
 * §32 governance tabs shared by both vendor portals (TPV + Purchase). Purely
 * presentational and API-agnostic: each tab takes a `gov` object (the portal's
 * own governance API block) and, for certificates, a `listWorkers` fetcher. The
 * two portals hit their own separate backends — nothing here is module-specific.
 */

const label = (s) => String(s || '').replace(/_/g, ' ')
const dt = (v) => (v ? new Date(v).toLocaleString() : '—')
const d = (v) => (v ? new Date(v).toLocaleDateString() : '—')
const STATUS_TONE = { Open: '#d97706', In_Progress: '#0891b2', Closed: '#16a34a', Verified: '#16a34a', Pending: '#64748b' }

/* ── Meetings & MOM ───────────────────────────────────────────────────── */
export function MeetingsTab({ gov }) {
  const [rows, setRows] = useState(null)
  const [open, setOpen] = useState(null)     // meeting id whose MOM is expanded
  const [mom, setMom] = useState({})         // id → loaded MOM detail

  useEffect(() => { gov.meetings().then(r => setRows(r?.data ?? [])).catch(() => setRows([])) }, [])

  const toggle = (id) => {
    if (open === id) { setOpen(null); return }
    setOpen(id)
    if (!mom[id]) gov.meetingMom(id).then(m => setMom(s => ({ ...s, [id]: m }))).catch(() => {})
  }

  if (rows === null) return <Loading />
  if (!rows.length) return <Empty text="No meetings recorded for you yet." />
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {rows.map(m => (
        <div key={m.id} style={card}>
          <button onClick={() => toggle(m.id)} style={rowBtn}>
            {open === m.id ? <ChevronDown size={16} /> : <ChevronRight size={16} />}
            <Calendar size={15} style={{ color: '#0891b2' }} />
            <span style={{ fontWeight: 800, color: 'var(--text-h)' }}>{m.reference} · {m.title}</span>
            <span style={{ flex: 1 }} />
            <Pill text={label(m.meeting_type)} tone="#64748b" />
            <Pill text={label(m.status)} tone={STATUS_TONE[m.status]} />
          </button>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', margin: '6px 0 0', paddingLeft: 26 }}>
            {dt(m.scheduled_at)} · {label(m.mode)}{m.location ? ` · ${m.location}` : ''}
          </div>
          {open === m.id && <MomDetail data={mom[m.id]} />}
        </div>
      ))}
    </div>
  )
}

function MomDetail({ data }) {
  if (!data) return <div style={{ padding: '10px 26px', color: 'var(--text-muted)', fontSize: 12.5 }}>Loading minutes…</div>
  const agenda = data.agenda_items ?? data.agendaItems ?? []
  const items = data.mom_items ?? data.momItems ?? []
  const decisions = data.decisions ?? []
  return (
    <div style={{ paddingLeft: 26, marginTop: 10, display: 'grid', gap: 12 }}>
      {agenda.length > 0 && (
        <Section title="Agenda">
          {agenda.map((a, i) => <li key={i} style={li}>{a.title || a.description}</li>)}
        </Section>
      )}
      {items.length > 0 && (
        <Section title="Action items (MOM)">
          {items.map((it) => (
            <li key={it.id} style={li}>
              <b>{it.action_ref ? `${it.action_ref} · ` : ''}</b>{it.description}
              <span style={{ color: 'var(--text-muted)' }}>
                {' '}— {it.responsible?.name || it.responsible_names || 'Unassigned'} · due {d(it.target_date)} · {label(it.status)}
              </span>
            </li>
          ))}
        </Section>
      )}
      {decisions.length > 0 && (
        <Section title="Decisions">
          {decisions.map((x, i) => <li key={i} style={li}>{x.description || x.decision}</li>)}
        </Section>
      )}
      {agenda.length + items.length + decisions.length === 0 &&
        <div style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>No minutes captured for this meeting.</div>}
    </div>
  )
}

/* ── Action items — vendor adds progress ──────────────────────────────── */
export function ActionsTab({ gov }) {
  const [rows, setRows] = useState(null)
  const [draft, setDraft] = useState({})
  const load = () => gov.actions().then(r => setRows(r?.data ?? [])).catch(() => setRows([]))
  useEffect(() => { load() }, [])

  const submit = (id) => {
    if (!draft[id]?.trim()) return
    gov.respondAction(id, { note: draft[id] }).then(() => { setDraft(s => ({ ...s, [id]: '' })); load() })
  }

  if (rows === null) return <Loading />
  if (!rows.length) return <Empty text="No action items assigned to you." />
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {rows.map(a => (
        <div key={a.id} style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
            <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>
              {a.action_ref ? `${a.action_ref} · ` : ''}{a.description}
            </div>
            <div style={{ display: 'flex', gap: 6 }}>
              {a.priority && <Pill text={label(a.priority)} tone="#64748b" />}
              <Pill text={label(a.status)} tone={STATUS_TONE[a.status]} />
            </div>
          </div>
          <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>
            {a.responsible?.name || a.responsible_names || 'Unassigned'} · due {d(a.target_date)}
          </div>
          {a.remark && <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-muted)', whiteSpace: 'pre-line' }}><b>Progress:</b> {a.remark}</div>}
          <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
            <input value={draft[a.id] || ''} onChange={e => setDraft(s => ({ ...s, [a.id]: e.target.value }))}
              placeholder="Add a progress update…" style={input} />
            <button onClick={() => submit(a.id)} style={btnPrimary}><Send size={14} /> Update</button>
          </div>
        </div>
      ))}
    </div>
  )
}

/* ── Worker certificate upload ────────────────────────────────────────── */
export function CertificatesTab({ gov, listWorkers }) {
  const [workers, setWorkers] = useState(null)
  const [form, setForm] = useState({ worker_id: '', kind: 'training', name: '', category: '', valid_until: '' })
  const [file, setFile] = useState(null)
  const [msg, setMsg] = useState('')
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    listWorkers().then(r => setWorkers(r?.data ?? r ?? [])).catch(() => setWorkers([]))
  }, [])

  const submit = () => {
    if (!form.worker_id || !form.name.trim() || !file) { setMsg('Pick a worker, name the certificate and attach a file.'); return }
    const fd = new FormData()
    fd.append('kind', form.kind)
    fd.append('name', form.name)
    if (form.category) fd.append('category', form.category)
    if (form.valid_until) fd.append('valid_until', form.valid_until)
    fd.append('certificate', file)
    setBusy(true)
    gov.uploadCertificate(form.worker_id, fd)
      .then(() => { setMsg('Certificate uploaded.'); setForm(f => ({ ...f, name: '', category: '', valid_until: '' })); setFile(null) })
      .catch(() => setMsg('Upload failed — check the file type (PDF/PNG/JPG, ≤10 MB).'))
      .finally(() => setBusy(false))
  }

  if (workers === null) return <Loading />
  return (
    <div style={{ ...card, padding: 16, maxWidth: 560 }}>
      <h3 style={h3}><FileCheck size={15} /> Upload a training / competency certificate</h3>
      {msg && <div style={{ color: msg.includes('failed') || msg.includes('Pick') ? '#dc2626' : '#16a34a', fontSize: 12.5, fontWeight: 600, marginBottom: 8 }}>{msg}</div>}
      {!workers.length
        ? <Empty text="No workers on your roster yet." />
        : (
          <div style={{ display: 'grid', gap: 8 }}>
            <select value={form.worker_id} onChange={e => setForm(f => ({ ...f, worker_id: e.target.value }))} style={input}>
              <option value="">Select worker…</option>
              {workers.map(w => <option key={w.id} value={w.id}>{w.name}{w.worker_code ? ` (${w.worker_code})` : ''}</option>)}
            </select>
            <select value={form.kind} onChange={e => setForm(f => ({ ...f, kind: e.target.value }))} style={input}>
              <option value="training">Training</option>
              <option value="competency">Competency</option>
            </select>
            <input value={form.name} onChange={e => setForm(f => ({ ...f, name: e.target.value }))} placeholder="Certificate name (e.g. Working at Height)" style={input} />
            <input value={form.category} onChange={e => setForm(f => ({ ...f, category: e.target.value }))} placeholder="Category (optional)" style={input} />
            <label style={{ fontSize: 12, color: 'var(--text-muted)' }}>Valid until (optional)
              <input type="date" value={form.valid_until} onChange={e => setForm(f => ({ ...f, valid_until: e.target.value }))} style={{ ...input, marginTop: 4 }} />
            </label>
            <input type="file" accept=".pdf,.png,.jpg,.jpeg" onChange={e => setFile(e.target.files?.[0] ?? null)} style={{ fontSize: 12.5, color: 'var(--text-muted)' }} />
            <button onClick={submit} disabled={busy} style={{ ...btnPrimary, marginTop: 4, opacity: busy ? 0.6 : 1 }}>
              <Upload size={14} /> {busy ? 'Uploading…' : 'Upload certificate'}
            </button>
          </div>
        )}
    </div>
  )
}

/* ── bits ─────────────────────────────────────────────────────────────── */
const Loading = () => <div style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</div>
const Empty = ({ text }) => <div style={{ ...card, padding: 20, color: 'var(--text-muted)' }}>{text}</div>
function Pill({ text, tone }) {
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: `${tone || '#64748b'}1f`, color: tone || '#64748b' }}>{text}</span>
}
function Section({ title, children }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', marginBottom: 4 }}>{title}</div>
      <ul style={{ margin: 0, paddingLeft: 18, display: 'grid', gap: 3 }}>{children}</ul>
    </div>
  )
}

const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 14 }
const input = { width: '100%', padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input, var(--bg-card))', color: 'var(--text-h)', fontSize: 13 }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: '#0891b2', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700, whiteSpace: 'nowrap' }
const h3 = { display: 'flex', alignItems: 'center', gap: 6, margin: '0 0 10px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }
const rowBtn = { display: 'flex', alignItems: 'center', gap: 8, width: '100%', background: 'none', border: 'none', cursor: 'pointer', padding: 0, textAlign: 'left' }
const li = { fontSize: 12.5, color: 'var(--text-h)' }

import { useState, useEffect } from 'react'
import { Send, Upload, Clock } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'

// §32 Purchase Vendor Portal — governance-response half. Mirror of the TPV
// portal (separate Purchase-owned data). No PPE matrix — Purchase has none.
const label = (s) => String(s || '').replace(/_/g, ' ')
const SEV = { Minor: '#0891b2', Major: '#d97706', Critical: '#dc2626' }
const TABS = [
  { key: 'ncrs', label: 'NCRs' },
  { key: 'capas', label: 'CAPAs' },
  { key: 'requests', label: 'Requests' },
]

export default function PurchasePortalGovernance() {
  const [tab, setTab] = useState('ncrs')
  return (
    <div style={{ padding: 4 }}>
      <div style={{ marginBottom: 16 }}>
        <p style={{ color: '#0891b2', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em', textTransform: 'uppercase' }}>Governance</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 900, margin: '2px 0 0' }}>Respond &amp; Request</h1>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Respond to non-conformances and corrective actions, and raise approval or extension requests.</p>
      </div>

      <div style={{ display: 'flex', gap: 6, marginBottom: 14, flexWrap: 'wrap' }}>
        {TABS.map(t => (
          <button key={t.key} onClick={() => setTab(t.key)} style={{ ...tabBtn, ...(tab === t.key ? tabActive : {}) }}>{t.label}</button>
        ))}
      </div>

      {tab === 'ncrs' && <Ncrs />}
      {tab === 'capas' && <Capas />}
      {tab === 'requests' && <Requests />}
    </div>
  )
}

function Ncrs() {
  const [rows, setRows] = useState(null)
  const [draft, setDraft] = useState({})
  const load = () => purchasePortalApi.governance.ncrs().then(d => setRows(d?.data ?? [])).catch(() => setRows([]))
  useEffect(() => { load() }, [])
  const submit = (id) => {
    if (!draft[id]?.trim()) return
    purchasePortalApi.governance.respondNcr(id, { response: draft[id] }).then(() => { setDraft(d => ({ ...d, [id]: '' })); load() })
  }
  if (rows === null) return <Loading />
  if (!rows.length) return <Empty text="No NCRs raised against you." />
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {rows.map(n => (
        <div key={n.id} style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
            <div>
              <div style={{ fontWeight: 800, color: 'var(--text-h)' }}>{n.reference} · {n.title}</div>
              <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{n.finding || n.requirement || ''}</div>
            </div>
            <div style={{ display: 'flex', gap: 6 }}>
              <Pill text={label(n.severity)} tone={SEV[n.severity]} />
              <Pill text={label(n.status)} tone="#64748b" />
            </div>
          </div>
          {n.response
            ? <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-muted)' }}><b>Your response:</b> {n.response}</div>
            : (
              <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
                <input value={draft[n.id] || ''} onChange={e => setDraft(d => ({ ...d, [n.id]: e.target.value }))} placeholder="Type your response…" style={input} />
                <button onClick={() => submit(n.id)} style={btnPrimary}><Send size={14} /> Respond</button>
              </div>
            )}
        </div>
      ))}
    </div>
  )
}

function Capas() {
  const [rows, setRows] = useState(null)
  const [draft, setDraft] = useState({})
  const load = () => purchasePortalApi.governance.capas().then(d => setRows(d?.data ?? [])).catch(() => setRows([]))
  useEffect(() => { load() }, [])
  const submit = (id) => purchasePortalApi.governance.submitCapa(id, { note: draft[id] || '' }).then(() => { setDraft(d => ({ ...d, [id]: '' })); load() })
  if (rows === null) return <Loading />
  if (!rows.length) return <Empty text="No corrective actions assigned to you." />
  return (
    <div style={{ display: 'grid', gap: 10 }}>
      {rows.map(c => (
        <div key={c.id} style={card}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
            <div style={{ fontWeight: 800, color: 'var(--text-h)' }}>{c.reference} · {c.title}</div>
            <Pill text={label(c.status)} tone="#64748b" />
          </div>
          <div style={{ display: 'flex', gap: 8, marginTop: 10 }}>
            <input value={draft[c.id] || ''} onChange={e => setDraft(d => ({ ...d, [c.id]: e.target.value }))} placeholder="Evidence note…" style={input} />
            <button onClick={() => submit(c.id)} style={btnPrimary}><Upload size={14} /> Submit</button>
          </div>
          {c.verification_notes && <div style={{ marginTop: 8, fontSize: 12.5, color: 'var(--text-muted)' }}><b>Submitted:</b> {c.verification_notes}</div>}
        </div>
      ))}
    </div>
  )
}

function Requests() {
  const [ap, setAp] = useState({ title: '', description: '' })
  const [ex, setEx] = useState({ subject: '', reason: '' })
  const [msg, setMsg] = useState('')
  const sendApproval = () => { if (!ap.title.trim()) return; purchasePortalApi.governance.requestApproval(ap).then(() => { setAp({ title: '', description: '' }); setMsg('Approval request submitted.') }) }
  const sendExtension = () => { if (!ex.reason.trim()) return; purchasePortalApi.governance.requestExtension(ex).then(() => { setEx({ subject: '', reason: '' }); setMsg('Extension request submitted.') }) }
  return (
    <div style={{ display: 'grid', gap: 12, gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))' }}>
      {msg && <div style={{ gridColumn: '1/-1', color: '#16a34a', fontSize: 13, fontWeight: 600 }}>{msg}</div>}
      <div style={{ ...card, padding: 16 }}>
        <h3 style={h3}><Send size={15} /> Request an approval</h3>
        <input value={ap.title} onChange={e => setAp(a => ({ ...a, title: e.target.value }))} placeholder="What do you need approved?" style={{ ...input, marginBottom: 8 }} />
        <textarea value={ap.description} onChange={e => setAp(a => ({ ...a, description: e.target.value }))} placeholder="Details (optional)" style={{ ...input, minHeight: 70 }} />
        <button onClick={sendApproval} style={{ ...btnPrimary, marginTop: 10 }}>Submit request</button>
      </div>
      <div style={{ ...card, padding: 16 }}>
        <h3 style={h3}><Clock size={15} /> Request an extension</h3>
        <input value={ex.subject} onChange={e => setEx(a => ({ ...a, subject: e.target.value }))} placeholder="What for?" style={{ ...input, marginBottom: 8 }} />
        <textarea value={ex.reason} onChange={e => setEx(a => ({ ...a, reason: e.target.value }))} placeholder="Reason" style={{ ...input, minHeight: 70 }} />
        <button onClick={sendExtension} style={{ ...btnPrimary, marginTop: 10 }}>Submit request</button>
      </div>
    </div>
  )
}

const Loading = () => <div style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</div>
const Empty = ({ text }) => <div style={{ ...card, padding: 20, color: 'var(--text-muted)' }}>{text}</div>
function Pill({ text, tone }) {
  return <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700, background: `${tone || '#64748b'}1f`, color: tone || '#64748b' }}>{text}</span>
}
const card = { background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 14 }
const input = { flex: 1, width: '100%', padding: '8px 12px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-input, var(--bg-card))', color: 'var(--text-h)', fontSize: 13 }
const btnPrimary = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', background: '#0891b2', color: '#fff', cursor: 'pointer', fontSize: 13, fontWeight: 700, whiteSpace: 'nowrap' }
const tabBtn = { padding: '7px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const tabActive = { background: '#0891b2', color: '#fff', borderColor: '#0891b2' }
const h3 = { display: 'flex', alignItems: 'center', gap: 6, margin: '0 0 10px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }

import { useState, useEffect } from 'react'
import { Send, Upload, Clock } from 'lucide-react'
import { purchasePortalApi } from '@/services/purchasePortalApi'
import { MeetingsTab, ActionsTab, CertificatesTab } from '@/components/portal/GovernanceTabs'

// §32 Purchase Vendor Portal — governance-response half. Mirror of the TPV
// portal (separate Purchase-owned data). No PPE matrix — Purchase has none.
const label = (s) => String(s || '').replace(/_/g, ' ')
const SEV = { Minor: '#0891b2', Major: '#d97706', Critical: '#dc2626' }
const TABS = [
  { key: 'ncrs', label: 'NCRs' },
  { key: 'capas', label: 'CAPAs' },
  { key: 'meetings', label: 'Meetings & MOM' },
  { key: 'actions', label: 'Action Items' },
  { key: 'requests', label: 'Requests' },
  { key: 'certificates', label: 'Certificates' },
    { key: 'ppe', label: 'PPE Matrix' },
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
      {tab === 'meetings' && <MeetingsTab gov={purchasePortalApi.governance} />}
      {tab === 'actions' && <ActionsTab gov={purchasePortalApi.governance} />}
      {tab === 'requests' && <Requests />}
      {tab === 'certificates' && <CertificatesTab gov={purchasePortalApi.governance} listWorkers={purchasePortalApi.workers.list} />}
      {tab === 'ppe' && <PpeMatrix />}
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

/* ── PPE Matrix ───────────────────────────────────────────────────────── */
/**
 * The site's PPE rule, read-only.
 *
 * Purchase had no matrix at all until now, so this tab did not exist while TPV's
 * did. It matters because the badge and the gate both refuse a worker for
 * "Mandatory PPE not issued: …" — and that is only actionable if the vendor can
 * see what the requirement is.
 */
function PpeMatrix() {
  const [rows, setRows] = useState(null)
  useEffect(() => {
    purchasePortalApi.governance.ppeMatrix()
      .then(d => setRows(d?.rules ?? []))
      .catch(() => setRows([]))
  }, [])

  if (rows === null) return <div style={{ padding: 18, color: 'var(--text-muted)' }}>Loading…</div>
  if (!rows.length) {
    return (
      <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, padding: 20, color: 'var(--text-muted)' }}>
        No PPE requirements have been configured for your workers yet.
      </div>
    )
  }

  const label = (v) => String(v || '').replace(/_/g, ' ').replace(/^./, c => c.toUpperCase())

  return (
    <div style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 14, overflow: 'hidden' }}>
      <div style={{ overflowX: 'auto' }}>
        <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 13 }}>
          <thead>
            <tr style={{ textAlign: 'left', color: 'var(--text-muted)', fontSize: 11, textTransform: 'uppercase', letterSpacing: '0.04em' }}>
              {['Applies to', 'PPE', 'Class', 'Qty', 'When'].map(h => (
                <th key={h} style={{ padding: '10px 14px' }}>{h}</th>
              ))}
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i} style={{ borderTop: '1px solid var(--border)' }}>
                <td style={{ padding: '10px 14px', color: 'var(--text-h)' }}>
                  {r.scope_type === 'all' ? 'All workers' : `${label(r.scope_type)}: ${r.scope_value}`}
                </td>
                <td style={{ padding: '10px 14px', color: 'var(--text-h)', fontWeight: 600 }}>{r.product || '—'}</td>
                <td style={{ padding: '10px 14px' }}>
                  <span style={{ display: 'inline-block', padding: '3px 9px', borderRadius: 999, fontSize: 11, fontWeight: 700,
                    background: r.ppe_class === 'mandatory' ? '#dc26261f' : '#64748b1f',
                    color: r.ppe_class === 'mandatory' ? '#dc2626' : '#64748b' }}>
                    {label(r.ppe_class)}
                  </span>
                </td>
                <td style={{ padding: '10px 14px', color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{r.qty}</td>
                <td style={{ padding: '10px 14px', color: 'var(--text-muted)' }}>{r.condition || '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}

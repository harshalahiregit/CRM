import { useState, useEffect } from 'react'
import {
  Plus, RefreshCw, Clock, CalendarPlus, ArrowUpCircle, Ban, History, AlertTriangle, Loader, X,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { useAuth } from '@/context/AuthContext'
import { canApproveTpv, canManageTpv, fmtDate } from '../constants'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput, SelectInput, InfoBox,
} from '@/components/ui/kit3d'

const BANDS = {
  green:   { color: '#10b981', bg: 'rgba(16,185,129,0.15)' },
  orange:  { color: '#f59e0b', bg: 'rgba(245,158,11,0.15)' },
  red:     { color: '#ef4444', bg: 'rgba(239,68,68,0.15)' },
  expired: { color: '#ef4444', bg: 'rgba(239,68,68,0.18)' },
}
const DAY = 86400
const bandFor = (s) => (s <= 0 ? 'expired' : s <= DAY ? 'red' : s <= 3 * DAY ? 'orange' : 'green')
const fmtRemaining = (s) => {
  if (s <= 0) return '0'
  const d = Math.floor(s / DAY), h = Math.floor((s % DAY) / 3600), m = Math.floor((s % 3600) / 60)
  return `${d}d ${String(h).padStart(2, '0')}h ${String(m).padStart(2, '0')}m`
}

/** Live-ticking countdown cell driven by the server's seconds_remaining. */
function CountdownCell({ row }) {
  const [secs, setSecs] = useState(Math.max(0, row.seconds_remaining || 0))
  useEffect(() => { setSecs(Math.max(0, row.seconds_remaining || 0)) }, [row.seconds_remaining])
  useEffect(() => {
    const id = setInterval(() => { if (!document.hidden) setSecs((s) => Math.max(0, s - 1)) }, 1000)
    return () => clearInterval(id)
  }, [])

  if (row.access_status === 'Converted') {
    return <span style={{ fontSize: 11.5, fontWeight: 700, color: '#a78bfa' }}>Converted</span>
  }
  const expired = secs <= 0 || row.access_status === 'Expired'
  const cfg = BANDS[expired ? 'expired' : bandFor(secs)]
  return (
    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11.5, fontWeight: 800 }}>
      <Clock size={12} /> {expired ? 'Expired' : fmtRemaining(secs)}
    </span>
  )
}

export default function TpvTemporaryVendors() {
  const { user } = useAuth()
  const admin = canApproveTpv(user)
  const manage = canManageTpv(user)

  const [rows, setRows] = useState([])
  const [loading, setLoad] = useState(true)
  const [err, setErr] = useState(null)
  const [modal, setModal] = useState(null) // { type, row }

  const load = () => {
    setLoad(true)
    tpvApi.access.list()
      .then((d) => { setRows(d?.data ?? d ?? []); setLoad(false) })
      .catch(() => { setErr('Could not load temporary vendors.'); setLoad(false) })
  }
  useEffect(() => { load() }, [])

  const onDone = () => { setModal(null); load() }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: 20, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>TIME-BOXED ACCESS</p>
          <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Temporary Vendors</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Create, extend, convert and monitor temporary third-party vendor access.</p>
        </div>
        <div style={{ display: 'flex', gap: 9 }}>
          <button onClick={load} style={ghostBtn}><RefreshCw size={14} /> Refresh</button>
          {manage && <button onClick={() => setModal({ type: 'create' })} style={solidBtn}><Plus size={15} /> New Temporary Vendor</button>}
        </div>
      </div>

      {err && <InfoBox tone="danger">{err}</InfoBox>}

      {loading ? (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
          {[1, 2, 3].map((i) => <div key={i} className="skeleton" style={{ height: 56, borderRadius: 12, background: 'var(--border)' }} />)}
        </div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: '44px 24px', textAlign: 'center' }}>
          <Clock size={26} style={{ color: '#a78bfa' }} />
          <h3 style={{ margin: '10px 0 4px', fontSize: 16, fontWeight: 800, color: 'var(--text-h)' }}>No temporary vendors yet</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>Create a temporary vendor to grant time-boxed portal access.</p>
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, borderRadius: 16, overflow: 'hidden' }}>
          <div style={{ overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', minWidth: 900, fontSize: 12.5 }}>
              <thead><tr>
                {['Company', 'Email', 'Countdown', 'Status', 'Validity', 'Created', 'Actions'].map((h, i) => (
                  <th key={h} style={{ ...th, textAlign: i === 6 ? 'right' : 'left' }}>{h}</th>
                ))}
              </tr></thead>
              <tbody>
                {rows.map((row) => {
                  const converted = row.access_status === 'Converted'
                  return (
                    <tr key={row.id} style={{ borderTop: '1px solid var(--border)' }}>
                      <td style={td}>
                        <div style={{ fontWeight: 700, color: 'var(--text-h)' }}>{row.company_name}</div>
                        <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{row.vendor_code}</div>
                      </td>
                      <td style={td}>{row.email}</td>
                      <td style={td}><CountdownCell row={row} /></td>
                      <td style={td}>{row.access_status}</td>
                      <td style={td}>{row.validity_days ? `${row.validity_days} days` : '—'}</td>
                      <td style={td}>{fmtDate(row.created_at)}</td>
                      <td style={{ ...td, textAlign: 'right', whiteSpace: 'nowrap' }}>
                        <div style={{ display: 'inline-flex', gap: 5 }}>
                          <ActBtn title="Details" icon={History} color="#94a3b8" onClick={() => setModal({ type: 'details', row })} />
                          {manage && !converted && <ActBtn title="Extend" icon={CalendarPlus} color="#0ea5e9" onClick={() => setModal({ type: 'extend', row })} />}
                          {admin && !converted && <ActBtn title="Convert to Permanent" icon={ArrowUpCircle} color="#10b981" onClick={() => setModal({ type: 'convert', row })} />}
                          {admin && !converted && <ActBtn title="Force Expire" icon={Ban} color="#ef4444" onClick={() => setModal({ type: 'expire', row })} />}
                        </div>
                      </td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        </div>
      )}

      {modal?.type === 'create' && <CreateModal onClose={() => setModal(null)} onDone={onDone} />}
      {modal?.type === 'extend' && <ExtendModal row={modal.row} onClose={() => setModal(null)} onDone={onDone} />}
      {modal?.type === 'convert' && <ConfirmModal row={modal.row} kind="convert" onClose={() => setModal(null)} onDone={onDone} />}
      {modal?.type === 'expire' && <ConfirmModal row={modal.row} kind="expire" onClose={() => setModal(null)} onDone={onDone} />}
      {modal?.type === 'details' && <DetailsModal row={modal.row} onClose={() => setModal(null)} />}
    </div>
  )
}

const VALIDITY = [[1, '1 day'], [3, '3 days'], [7, '7 days'], [15, '15 days'], ['custom', 'Custom…']]

function CreateModal({ onClose, onDone }) {
  const [f, setF] = useState({ company_name: '', email: '', phone: '', validity: 7, custom: '', access_start_at: '', password: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const [created, setCreated] = useState(null) // { email, generated_password }
  const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target.value }))

  const valid = f.company_name.trim() && f.email.trim() && (!f.password || f.password.length >= 8)

  const save = async () => {
    if (!valid) { setErr('Company name and a valid email are required (password, if set, needs 8+ characters).'); return }
    setSaving(true); setErr(null)
    try {
      const validity_days = f.validity === 'custom' ? Number(f.custom) : Number(f.validity)
      const res = await tpvApi.access.createTemporary({
        company_name: f.company_name, email: f.email, phone: f.phone || undefined,
        validity_days, access_start_at: f.access_start_at || undefined,
        password: f.password || undefined,
      })
      const gen = res?.generated_password ?? res?.data?.generated_password
      // Reveal an auto-generated credential once; if the admin set their own, just finish.
      if (gen) { setCreated({ email: f.email, generated_password: gen }); setSaving(false) }
      else onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not create the temporary vendor.'); setSaving(false) }
  }

  if (created) {
    return (
      <Overlay onClose={onDone} width={560}>
        <ModalHead title="Temporary Vendor Created" sub="Save this login now — the password is shown only once." />
        <div style={{ padding: '10px 22px' }}>
          <div style={{ fontSize: 12.5, lineHeight: 2, padding: '12px 16px', borderRadius: 10, background: 'rgba(16,185,129,0.08)', border: '1px solid rgba(16,185,129,0.28)' }}>
            <div><strong>Login email:</strong> {created.email}</div>
            <div><strong>Password:</strong> <code style={{ fontSize: 13, padding: '2px 8px', borderRadius: 6, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)' }}>{created.generated_password}</code></div>
          </div>
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: '10px 2px 0' }}>The credentials have also been emailed to the vendor.</p>
        </div>
        <ModalFooter onClose={onDone} onConfirm={onDone} confirmLabel="Done" color="#7C3AED" />
      </Overlay>
    )
  }

  return (
    <Overlay onClose={onClose} width={560}>
      <ModalHead title="New Temporary Vendor" sub="Set a login password or leave blank to auto-generate. Credentials are emailed to the vendor." />
      <div style={{ padding: '10px 22px', display: 'flex', flexDirection: 'column', gap: 4 }}>
        <Field label="Company Name" full><TextInput value={f.company_name} onChange={set('company_name')} placeholder="Vendor company" /></Field>
        <Field label="Email (login)" full><TextInput type="email" value={f.email} onChange={set('email')} placeholder="vendor@company.com" /></Field>
        <Field label="Password (optional)" full><TextInput type="text" value={f.password} onChange={set('password')} placeholder="Leave blank to auto-generate" /></Field>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          <Field label="Phone"><TextInput value={f.phone} onChange={set('phone')} placeholder="+91 …" /></Field>
          <Field label="Validity"><SelectInput value={f.validity} onChange={set('validity')} options={VALIDITY} pairs /></Field>
        </div>
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
          {f.validity === 'custom' && <Field label="Custom days"><TextInput type="number" min="1" max="365" value={f.custom} onChange={set('custom')} /></Field>}
          <Field label="Access Start (optional)"><TextInput type="date" value={f.access_start_at} onChange={set('access_start_at')} /></Field>
        </div>
        {err && <ModalError>{err}</ModalError>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} disabled={!valid} confirmLabel="Create" color="#7C3AED" />
    </Overlay>
  )
}

function ExtendModal({ row, onClose, onDone }) {
  const [f, setF] = useState({ validity: 7, custom: '', extension_reason: '' })
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const set = (k) => (e) => setF((p) => ({ ...p, [k]: e.target.value }))

  const save = async () => {
    if (!f.extension_reason.trim()) { setErr('A reason is required to extend access.'); return }
    setSaving(true); setErr(null)
    try {
      const validity_days = f.validity === 'custom' ? Number(f.custom) : Number(f.validity)
      await tpvApi.access.extend(row.id, { validity_days, extension_reason: f.extension_reason })
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Could not extend access.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={480}>
      <ModalHead title="Extend Access" sub={row.company_name} />
      <div style={{ padding: '10px 22px' }}>
        <Field label="New Validity" full><SelectInput value={f.validity} onChange={set('validity')} options={VALIDITY} pairs /></Field>
        {f.validity === 'custom' && <Field label="Custom days" full><TextInput type="number" min="1" max="365" value={f.custom} onChange={set('custom')} /></Field>}
        <Field label="Reason (required)" full><TextInput value={f.extension_reason} onChange={set('extension_reason')} placeholder="Awaiting document verification" /></Field>
        {err && <ModalError>{err}</ModalError>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={save} loading={saving} confirmLabel="Extend Access" color="#0ea5e9" />
    </Overlay>
  )
}

function ConfirmModal({ row, kind, onClose, onDone }) {
  const [saving, setSaving] = useState(false)
  const [err, setErr] = useState(null)
  const cfg = kind === 'convert'
    ? { title: 'Convert to Permanent', color: '#10b981', label: 'Convert', text: `Convert ${row.company_name} to a Permanent Vendor? This removes the expiry, issues a registration number, and preserves all history.` }
    : { title: 'Force Expire', color: '#ef4444', label: 'Expire Now', text: `Immediately expire ${row.company_name}'s access? Active sessions are terminated and the account is locked until extended.` }

  const go = async () => {
    setSaving(true); setErr(null)
    try {
      if (kind === 'convert') await tpvApi.access.convert(row.id)
      else await tpvApi.access.expire(row.id)
      onDone()
    } catch (e) { setErr(e?.response?.data?.message || 'Action failed.'); setSaving(false) }
  }

  return (
    <Overlay onClose={onClose} width={440}>
      <ModalHead title={cfg.title} sub={row.company_name} />
      <div style={{ padding: '6px 22px 12px' }}>
        <p style={{ fontSize: 13, color: 'var(--text-h)', lineHeight: 1.55, margin: 0 }}>{cfg.text}</p>
        {err && <ModalError>{err}</ModalError>}
      </div>
      <ModalFooter onClose={onClose} onConfirm={go} loading={saving} confirmLabel={cfg.label} color={cfg.color} />
    </Overlay>
  )
}

function DetailsModal({ row, onClose }) {
  const [data, setData] = useState(null)
  useEffect(() => { tpvApi.access.status(row.id).then(setData).catch(() => setData({ timeline: [] })) }, [row.id])

  return (
    <Overlay onClose={onClose} width={600}>
      <ModalHead title="Access Timeline" sub={row.company_name} />
      <div style={{ padding: '10px 22px 20px', maxHeight: 460, overflowY: 'auto' }}>
        {!data ? (
          <div style={{ padding: 20, textAlign: 'center' }}><Loader size={18} /></div>
        ) : (
          <>
            <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px', marginBottom: 14 }}>
              {[['Status', data.access_status], ['Band', data.band],
                ['Expires', fmtDate(data.access_expires_at)], ['Validity', data.validity_days ? `${data.validity_days} days` : '—']]
                .map(([k, v]) => (
                  <div key={k} style={{ display: 'flex', justifyContent: 'space-between', borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
                    <span style={{ color: 'var(--text-muted)', fontSize: 12 }}>{k}</span>
                    <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600 }}>{v || '—'}</span>
                  </div>
                ))}
            </div>
            <p style={{ fontSize: 11, fontWeight: 800, textTransform: 'uppercase', color: '#a78bfa', margin: '0 0 8px' }}>Audit Timeline</p>
            <AuditTimeline entries={data.timeline || []} />
          </>
        )}
      </div>
      <div style={{ display: 'flex', justifyContent: 'flex-end', padding: '0 22px 18px' }}>
        <button onClick={onClose} style={ghostBtn}>Close</button>
      </div>
    </Overlay>
  )
}

/* ── bits ───────────────────────────────────────────────────────────────── */
const ModalHead = ({ title, sub }) => (
  <div style={{ padding: '20px 22px 6px' }}>
    <h2 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>{title}</h2>
    {sub && <p style={{ margin: '3px 0 0', fontSize: 12.5, color: 'var(--text-muted)' }}>{sub}</p>}
  </div>
)
const ModalError = ({ children }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '9px 12px', borderRadius: 10, background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)', marginTop: 8 }}>
    <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0 }} />
    <span style={{ fontSize: 12.5, color: 'var(--text-h)' }}>{children}</span>
  </div>
)
function ActBtn({ title, icon: Icon, color, onClick }) {
  return (
    <button title={title} onClick={onClick}
      style={{ width: 30, height: 30, borderRadius: 8, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)', color }}>
      <Icon size={15} />
    </button>
  )
}

const th = { padding: '11px 14px', fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.04em', color: 'var(--text-muted)', whiteSpace: 'nowrap', background: 'var(--bg-card)' }
const td = { padding: '11px 14px', color: 'var(--text-h)', whiteSpace: 'nowrap', verticalAlign: 'middle' }
const solidBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 16px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', border: 'none', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.6)' }
const ghostBtn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 600, color: 'var(--text-muted)', background: 'var(--bg-card)', border: '1px solid var(--border)' }

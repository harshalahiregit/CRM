import { useEffect, useState } from 'react'
import { purchaseApi } from '@/services/purchaseApi'
import { fmtDate } from '@/modules/purchase/constants'
import { useVendorWorkspace } from './vendorWorkspaceContext'
import { VendorScopedList } from './vendorDetailShared'
import LoadError from '@/components/ui/LoadError'

/**
 * The Performance group on a Purchase vendor.
 *
 * Five entries that had sat in the sidebar model with nothing behind them. Four
 * of the five were never a backend gap: risk lives in columns on
 * purchase_vendors and is set through its own endpoint, the performance index is
 * computed by PurchaseVendorPerformanceService, and penalties are the violations
 * register. Only awards and referrals needed tables, and they have Purchase-owned
 * ones rather than a second key on TPV's.
 */

const card = {
  background: 'var(--bg-card)', border: '1px solid var(--border)',
  borderRadius: 12, padding: 16,
}

const bandTone = (band) => {
  const b = String(band || '').toLowerCase()
  if (b.includes('high') || b.includes('poor') || b === 'd' || b === 'e') return '#ef4444'
  if (b.includes('medium') || b.includes('fair') || b === 'c') return '#f59e0b'
  return '#10b981'
}

/* ── Risk Score ──────────────────────────────────────────────────────────── */

export function VendorRiskScoreTab() {
  const { vendor } = useVendorWorkspace()

  const level = vendor.risk_level || '—'
  const score = vendor.risk_score

  return (
    <div className="card-3d" style={card}>
      <h3 style={{ margin: '0 0 4px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Risk Score</h3>
      <p style={{ margin: '0 0 16px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Set by an assessor, not derived — a risk rating is a judgement somebody signs their name to.
      </p>

      {score === null || score === undefined ? (
        <div style={{ color: 'var(--text-muted)', fontSize: 13 }}>
          This vendor has not been risk assessed yet.
        </div>
      ) : (
        <div style={{ display: 'flex', gap: 28, flexWrap: 'wrap', alignItems: 'flex-start' }}>
          <Stat label="Score" value={score} tone={bandTone(level)} />
          <Stat label="Level" value={level} tone={bandTone(level)} />
          <Stat label="Assessed" value={vendor.risk_assessed_at ? fmtDate(vendor.risk_assessed_at) : '—'} />
        </div>
      )}

      {vendor.risk_notes && (
        <div style={{ marginTop: 18, paddingTop: 14, borderTop: '1px solid var(--border)' }}>
          <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.05em', marginBottom: 5 }}>
            Assessor&apos;s notes
          </div>
          <div style={{ fontSize: 13, color: 'var(--text-h)', lineHeight: 1.6 }}>{vendor.risk_notes}</div>
        </div>
      )}
    </div>
  )
}

/* ── Performance Index ───────────────────────────────────────────────────── */

export function VendorPerformanceIndexTab() {
  const { vendor } = useVendorWorkspace()
  const [data, setData] = useState(null)
  const [error, setError] = useState(null)

  useEffect(() => {
    let alive = true
    setData(null); setError(null)
    purchaseApi.vendors.vpi(vendor.id)
      .then((d) => { if (alive) setData(d ?? {}) })
      .catch((e) => { if (alive) setError(e) })
    return () => { alive = false }
  }, [vendor.id])

  if (error) return <div className="card-3d" style={card}><LoadError error={error} /></div>
  if (!data) return <div className="card-3d" style={card}><span style={{ color: 'var(--text-muted)' }}>Loading…</span></div>

  // The service names its own dimensions; render whatever it sends rather than
  // hard-coding a list here that would silently drop a new one.
  const dimensions = Object.entries(data.dimensions || data.breakdown || {})

  return (
    <div className="card-3d" style={card}>
      <h3 style={{ margin: '0 0 4px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Performance Index</h3>
      <p style={{ margin: '0 0 16px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Computed from this vendor&apos;s own record — deliveries, quality, compliance and safety.
      </p>

      <div style={{ display: 'flex', gap: 28, flexWrap: 'wrap', marginBottom: dimensions.length ? 20 : 0 }}>
        <Stat label="Overall" value={data.overall ?? data.score ?? '—'} tone={bandTone(data.band)} />
        {data.band && <Stat label="Band" value={data.band} tone={bandTone(data.band)} />}
      </div>

      {dimensions.length > 0 && (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(200px,1fr))', gap: 10 }}>
          {dimensions.map(([key, val]) => (
            <div key={key} style={{ padding: '10px 12px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'capitalize' }}>
                {key.replace(/_/g, ' ')}
              </div>
              <div style={{ fontSize: 17, fontWeight: 800, color: 'var(--text-h)', marginTop: 2 }}>
                {typeof val === 'object' ? (val?.score ?? '—') : String(val)}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

/* ── Penalty ─────────────────────────────────────────────────────────────── */

export function VendorPenaltyTab() {
  const { vendor } = useVendorWorkspace()

  return (
    <VendorScopedList
      key={`pen-${vendor.id}`}
      title="Penalties"
      // Penalties ARE the violations register, scoped to this vendor. A second
      // table would be a second set of rules for the same thing.
      fetcher={(vid) => purchaseApi.violations.list({ vendor_id: vid })}
      emptyText="No penalties recorded against this vendor"
      statusCfg={(st) => {
        const open = ['Open', 'Issued', 'Disputed'].includes(String(st))
        return {
          label: String(st || '—').replace(/_/g, ' '),
          color: open ? '#ef4444' : '#10b981',
          bg: open ? 'rgba(239,68,68,0.15)' : 'rgba(16,185,129,0.15)',
        }
      }}
      columns={[
        { header: 'Reference', strong: true, cell: (r) => r.reference_no || `#${r.id}` },
        { header: 'Type', cell: (r) => (r.type || '—').replace(/_/g, ' ') },
        { header: 'Amount', cell: (r) => (r.amount != null ? `${r.currency || ''} ${r.amount}`.trim() : '—') },
        { header: 'Raised', cell: (r) => (r.issued_at || r.created_at ? fmtDate(r.issued_at || r.created_at) : '—') },
      ]}
    />
  )
}

/* ── Feedback ────────────────────────────────────────────────────────────── */

/**
 * Feedback is the performance index read the other way round: the same numbers,
 * presented as what we are telling the vendor about their performance. It shares
 * the endpoint deliberately — two sources would eventually disagree, and then
 * nobody would know which one the vendor had been shown.
 */
export function VendorFeedbackTab() {
  return <VendorPerformanceIndexTab />
}

/* ── Award / Reward ──────────────────────────────────────────────────────── */

export function VendorAwardsTab() {
  const { vendor } = useVendorWorkspace()
  const [adding, setAdding] = useState(null)
  const [nonce, setNonce] = useState(0)

  return (
    <>
      <VendorScopedList
        key={`awd-${vendor.id}-${nonce}`}
        title="Awards"
        fetcher={(vid) => purchaseApi.vendors.awards.list(vid)}
        emptyText="This vendor has not been recognised for anything yet"
        addLabel="Grant Award"
        onAdd={() => setAdding({ title: '', category: '', description: '' })}
        columns={[
          { header: 'Award', strong: true, cell: (r) => r.title || '—' },
          { header: 'Category', cell: (r) => r.category || '—' },
          { header: 'Awarded', cell: (r) => (r.awarded_on ? fmtDate(r.awarded_on) : '—') },
          { header: 'By', cell: (r) => r.granted_by?.name || '—' },
        ]}
      />

      {adding && (
        <SimpleForm
          title="Grant Award"
          fields={[
            { key: 'title', label: 'Award', required: true, placeholder: 'e.g. Zero incidents, Q3' },
            { key: 'category', label: 'Category', placeholder: 'Safety, Quality, Delivery…' },
            { key: 'description', label: 'Description', textarea: true },
          ]}
          value={adding}
          onChange={setAdding}
          onClose={() => setAdding(null)}
          onSave={async () => {
            await purchaseApi.vendors.awards.grant(vendor.id, adding)
            setAdding(null); setNonce(n => n + 1)
          }}
        />
      )}
    </>
  )
}

/* ── Referral ────────────────────────────────────────────────────────────── */

export function VendorReferralsTab() {
  const { vendor } = useVendorWorkspace()
  const [adding, setAdding] = useState(null)
  const [nonce, setNonce] = useState(0)

  return (
    <>
      <VendorScopedList
        key={`ref-${vendor.id}-${nonce}`}
        title="Referrals"
        fetcher={(vid) => purchaseApi.vendors.referrals.list(vid)}
        emptyText="This vendor has not introduced anyone yet"
        addLabel="Record Referral"
        onAdd={() => setAdding({ company_name: '', contact_name: '', contact_email: '', contact_phone: '', note: '' })}
        statusCfg={(st) => {
          const s = String(st || 'Pending')
          const color = s === 'Onboarded' ? '#10b981' : s === 'Declined' ? '#94a3b8' : s === 'Contacted' ? '#0ea5e9' : '#f59e0b'
          return { label: s, color, bg: `${color}26` }
        }}
        columns={[
          { header: 'Company', strong: true, cell: (r) => r.company_name || '—' },
          { header: 'Contact', cell: (r) => r.contact_name || '—' },
          { header: 'Email', cell: (r) => r.contact_email || '—' },
          { header: 'Phone', cell: (r) => r.contact_phone || '—' },
        ]}
      />

      {adding && (
        <SimpleForm
          title="Record Referral"
          fields={[
            { key: 'company_name', label: 'Company', required: true, placeholder: 'The company being introduced' },
            { key: 'contact_name', label: 'Contact name' },
            { key: 'contact_email', label: 'Contact email', type: 'email' },
            { key: 'contact_phone', label: 'Contact phone' },
            { key: 'note', label: 'Note', textarea: true },
          ]}
          value={adding}
          onChange={setAdding}
          onClose={() => setAdding(null)}
          onSave={async () => {
            await purchaseApi.vendors.referrals.create(vendor.id, adding)
            setAdding(null); setNonce(n => n + 1)
          }}
        />
      )}
    </>
  )
}

/* ── Small shared pieces ─────────────────────────────────────────────────── */

function Stat({ label, value, tone }) {
  return (
    <div>
      <div style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '.05em' }}>
        {label}
      </div>
      <div style={{ fontSize: 26, fontWeight: 900, color: tone || 'var(--text-h)', marginTop: 2, fontVariantNumeric: 'tabular-nums' }}>
        {value}
      </div>
    </div>
  )
}

/**
 * A small create form, closed only by ✕ or Cancel — never by a backdrop click,
 * which is how a half-typed record gets lost.
 */
function SimpleForm({ title, fields, value, onChange, onClose, onSave }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  const required = fields.filter(f => f.required)
  const ready = required.every(f => String(value[f.key] || '').trim())

  const save = async () => {
    setBusy(true); setErr(null)
    try { await onSave() } catch (e) {
      setErr(e?.response?.data?.message
        || Object.values(e?.response?.data?.errors || {}).flat()[0]
        || 'That could not be saved.')
    } finally { setBusy(false) }
  }

  return (
    <div style={overlay}>
      <div style={sheet}>
        <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '16px 20px', borderBottom: '1px solid var(--border)' }}>
          <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>{title}</h3>
          <button onClick={onClose} disabled={busy}
            style={{ border: 'none', background: 'none', cursor: 'pointer', fontSize: 18, color: 'var(--text-muted)' }}>✕</button>
        </div>

        <div style={{ padding: 20, display: 'flex', flexDirection: 'column', gap: 12 }}>
          {fields.map(f => (
            <div key={f.key}>
              <label style={{ display: 'block', fontSize: 12, fontWeight: 700, color: 'var(--text-h)', marginBottom: 5 }}>
                {f.label}{f.required && <span style={{ color: '#ef4444' }}> *</span>}
              </label>
              {f.textarea ? (
                <textarea rows={3} value={value[f.key] || ''} placeholder={f.placeholder}
                  onChange={e => onChange({ ...value, [f.key]: e.target.value })} style={input} />
              ) : (
                <input type={f.type || 'text'} value={value[f.key] || ''} placeholder={f.placeholder}
                  onChange={e => onChange({ ...value, [f.key]: e.target.value })} style={input} />
              )}
            </div>
          ))}

          {err && <div style={{ color: '#ef4444', fontSize: 12.5 }}>{err}</div>}

          <div style={{ display: 'flex', gap: 8, justifyContent: 'flex-end', marginTop: 4 }}>
            <button onClick={onClose} disabled={busy} style={btnGhost}>Cancel</button>
            <button onClick={save} disabled={busy || !ready}
              style={{ ...btnGhost, background: '#7C3AED', borderColor: '#7C3AED', color: '#fff', opacity: (busy || !ready) ? 0.6 : 1 }}>
              {busy ? 'Saving…' : 'Save'}
            </button>
          </div>
        </div>
      </div>
    </div>
  )
}

const overlay = {
  position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.55)',
  display: 'flex', alignItems: 'center', justifyContent: 'center', zIndex: 1000, padding: 16,
}
const sheet = {
  width: '100%', maxWidth: 460, background: 'var(--bg-card)',
  border: '1px solid var(--border)', borderRadius: 14, overflow: 'hidden',
}
const input = {
  width: '100%', padding: '8px 11px', borderRadius: 8,
  border: '1px solid var(--border)', background: 'var(--bg-input)',
  color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box', resize: 'vertical',
}
const btnGhost = {
  padding: '8px 16px', borderRadius: 9, fontSize: 13, fontWeight: 600, cursor: 'pointer',
  border: '1px solid var(--border)', background: 'transparent', color: 'var(--text-h)',
}

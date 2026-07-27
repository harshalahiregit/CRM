import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, CheckCircle2, Circle } from 'lucide-react'
import { purchaseApi } from '@/services/purchaseApi'
import PurchaseVendorDocuments from '@/modules/purchase/components/PurchaseVendorDocuments'

const docApiFor = (vendorId) => ({
  checklist: () => purchaseApi.documents.checklist(vendorId),
  upload: (_, type, file) => purchaseApi.documents.upload(vendorId, type, file),
  resubmit: purchaseApi.documents.resubmit,
  review: purchaseApi.documents.review,
  open: purchaseApi.documents.open,
})

/**
 * Purchase Vendor Onboarding wizard (admin view) — step progress, document
 * checklist and approve/reject/hold decisions. All Purchase-owned endpoints.
 */
export default function PurchaseVendorOnboardingWizard() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [ob, setOb] = useState(null)
  const [progress, setProgress] = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)

  const load = useCallback(() => {
    setLoading(true)
    Promise.all([purchaseApi.onboarding.get(id), purchaseApi.onboarding.progress(id)])
      .then(([o, p]) => { setOb(o?.onboarding ?? o); setProgress(p) })
      .catch(() => {})
      .finally(() => setLoading(false))
  }, [id])
  useEffect(() => { load() }, [load])

  const decide = async (fn) => { setBusy(true); try { await fn(); load() } catch { /* noop */ } finally { setBusy(false) } }

  if (loading) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Loading…</div>
  if (!ob) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Onboarding not found.</div>

  const vendorId = ob.purchase_vendor_id ?? ob.vendor?.id
  const steps = progress?.steps || []

  return (
    <div style={{ padding: 20 }}>
      <button onClick={() => navigate('/app/purchase/onboarding')} style={{ ...linkBtn, marginBottom: 12 }}><ArrowLeft size={14} /> Back</button>

      <div className="card-3d" style={{ padding: 18, marginBottom: 16 }}>
        <h1 style={{ fontSize: 18, fontWeight: 800, color: 'var(--text-h)', margin: 0 }}>{ob.vendor?.company_name || 'Vendor'} — Onboarding</h1>
        <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 4 }}>Step {ob.current_step}/6 · {ob.status_label || ob.status}</div>
      </div>

      <div className="card-3d" style={{ padding: 18, marginBottom: 16 }}>
        <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '.04em', marginTop: 0 }}>Steps</h2>
        {steps.map((s) => (
          <div key={s.key} style={{ display: 'flex', alignItems: 'center', gap: 8, padding: '7px 0' }}>
            {s.complete ? <CheckCircle2 size={16} style={{ color: '#10b981' }} /> : <Circle size={16} style={{ color: 'var(--text-muted)' }} />}
            <span style={{ color: 'var(--text-h)', fontWeight: 600 }}>{s.label}</span>
            <span style={{ color: 'var(--text-muted)', fontSize: 12, marginLeft: 'auto' }}>{s.detail}</span>
          </div>
        ))}
      </div>

      <div className="card-3d" style={{ padding: 18, marginBottom: 16 }}>
        <h2 style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)', textTransform: 'uppercase', letterSpacing: '.04em', marginTop: 0 }}>Documents</h2>
        {vendorId ? <PurchaseVendorDocuments api={docApiFor(vendorId)} manage admin onChanged={load} /> : <div style={{ color: 'var(--text-muted)' }}>No vendor linked.</div>}
      </div>

      <div style={{ display: 'flex', gap: 8 }}>
        <button disabled={busy} onClick={() => decide(() => purchaseApi.onboarding.approve(id, 'Approved'))} style={{ ...btn, background: '#10b981', color: '#fff', border: 'none' }}>Approve</button>
        <button disabled={busy} onClick={() => decide(() => purchaseApi.onboarding.reject(id, 'Rejected'))} style={{ ...btn, background: '#ef4444', color: '#fff', border: 'none' }}>Reject</button>
        <button disabled={busy} onClick={() => decide(() => purchaseApi.onboarding.hold(id, 'On hold'))} style={btn}>Hold</button>
      </div>
    </div>
  )
}

const btn = { display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 8, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', cursor: 'pointer', fontSize: 13, fontWeight: 700 }
const linkBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '5px 8px', borderRadius: 6, background: 'transparent', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }

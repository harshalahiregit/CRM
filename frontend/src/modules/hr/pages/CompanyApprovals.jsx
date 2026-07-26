import { useState, useEffect, useCallback } from 'react'
import { Building2, Check, X, Mail, Phone, Globe } from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading, HrEmpty } from '@/components/ui/HrState'

const unwrap = r => r?.data ?? r

export default function CompanyApprovals() {
  const [companies, setCompanies] = useState([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(null)
  const [toast, setToast] = useState(null)
  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  const load = useCallback(async () => {
    setLoading(true)
    try { setCompanies(unwrap(await hrApi.recruitmentServices.pendingCompanies()) || []) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [])
  useEffect(() => { load() }, [load])

  const approve = async (c) => {
    if (!window.confirm(`Approve "${c.name}" (${c.company_code})? Their login will be activated.`)) return
    setBusy(c.id)
    try { await hrApi.recruitmentServices.approveCompany(c.id); showToast('Company approved'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to approve', 'error') }
    finally { setBusy(null) }
  }
  const reject = async (c) => {
    const reason = window.prompt(`Reject "${c.name}"? Optional reason:`)
    if (reason === null) return
    setBusy(c.id)
    try { await hrApi.recruitmentServices.rejectCompany(c.id, reason); showToast('Company rejected'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to reject', 'error') }
    finally { setBusy(null) }
  }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <div style={{ marginBottom: 16 }}>
        <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.05em' }}>HR · Recruitment Services</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Company Account Approvals</h1>
      </div>

      {loading ? <HrLoading label="Loading pending companies…" /> : (
        companies.length === 0 ? (
          <HrEmpty icon={Building2} title="No pending companies" hint="Self-registered companies awaiting approval appear here." />
        ) : (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(320px,1fr))', gap: 12 }}>
            {companies.map(c => (
              <div key={c.id} className="card-3d" style={{ padding: 16 }}>
                <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
                  <div>
                    <p style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 15 }}>{c.name}</p>
                    <p style={{ fontSize: 11.5, color: '#a78bfa', fontWeight: 700, marginTop: 2 }}>{c.company_code} · {c.company_type}</p>
                  </div>
                  <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, height: 'fit-content', background: 'rgba(245,158,11,0.12)', color: '#f59e0b' }}>Pending</span>
                </div>
                <div style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 10, display: 'flex', flexDirection: 'column', gap: 4 }}>
                  {c.industry && <span>{c.industry}{c.company_size ? ` · ${c.company_size}` : ''}</span>}
                  <span><Mail size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {c.contact_email || '—'}</span>
                  {c.contact_phone && <span><Phone size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {c.contact_phone}</span>}
                  {c.website && <span><Globe size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {c.website}</span>}
                  {c.gst_number && <span>GST: {c.gst_number}</span>}
                </div>
                <div style={{ display: 'flex', gap: 8, marginTop: 14 }}>
                  <button onClick={() => approve(c)} disabled={busy === c.id} style={{ flex: 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 5, padding: '8px 0', borderRadius: 8, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: 'linear-gradient(135deg,#10b981,#059669)', opacity: busy === c.id ? 0.6 : 1 }}><Check size={13} /> Approve</button>
                  <button onClick={() => reject(c)} disabled={busy === c.id} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '8px 14px', borderRadius: 8, border: '1px solid var(--border)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#f87171', background: 'var(--bg-input)' }}><X size={13} /> Reject</button>
                </div>
              </div>
            ))}
          </div>
        )
      )}
    </div>
  )
}

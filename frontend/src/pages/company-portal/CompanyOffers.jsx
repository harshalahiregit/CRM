import { useState, useEffect, useCallback } from 'react'
import { FileText, Download, Check, Edit3, XCircle, MessageSquare, UserCheck } from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const ST = {
  Generated: { c: '#94a3b8', bg: 'rgba(148,163,184,0.14)' }, Sent: { c: '#3b82f6', bg: 'rgba(59,130,246,0.12)' },
  Viewed: { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)' }, Accepted: { c: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  Declined: { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' }, Expired: { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  Completed: { c: '#22d3ee', bg: 'rgba(34,211,238,0.14)' },
}
const money = v => v ? `₹${(v / 100000).toFixed(1)}L` : '—'
const fmt = d => d ? new Date(d).toLocaleDateString() : '—'
const locked = s => s === 'Expired' || s === 'Accepted' || s === 'Completed'

export default function CompanyOffers({ requestId, showToast }) {
  const [items, setItems] = useState([])
  const [loading, setLoading] = useState(true)
  const [busy, setBusy] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try { setItems(unwrap(await companyPortalApi.offers(requestId)) || []) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [requestId, showToast])
  useEffect(() => { load() }, [load])

  const decide = async (o, decision) => {
    if (busy) return
    let comment = ''
    if (decision === 'changes' || decision === 'reject' || decision === 'feedback') { comment = window.prompt('Add a comment:') ?? ''; if (decision !== 'feedback' && comment === null) return }
    setBusy(true)
    try { await companyPortalApi.offerDecision(requestId, o.id, decision, comment); showToast('Decision recorded'); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  const download = async (o) => {
    try { const blob = await companyPortalApi.offerDownload(requestId, o.id); const url = URL.createObjectURL(blob); window.open(url, '_blank'); setTimeout(() => URL.revokeObjectURL(url), 30000) }
    catch (e) { showToast(e.response?.data?.message || 'No offer letter available', 'error') }
  }

  if (loading) return <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div>
  if (items.length === 0) return <div className="card-3d" style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>No offers released yet.</div>

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      {items.map(o => {
        const s = ST[o.status] || {}
        const isLocked = locked(o.status)
        return (
          <div key={o.id} className="card-3d" style={{ padding: 16 }}>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 10, flexWrap: 'wrap' }}>
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                  <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 12 }}>{o.offer_number}</span>
                  <span style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 15 }}>{o.candidate}</span>
                  <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: s.bg, color: s.c }}>{o.status}</span>
                  {o.company_decision && <span style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)' }}>· {o.company_decision.replace('Client ', '')}</span>}
                </div>
                <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 4 }}>{o.position || '—'}{o.department ? ` · ${o.department}` : ''} · {money(o.offered_ctc)} · Joining {fmt(o.joining_date)}</p>
              </div>
            </div>

            {/* Joining */}
            <div style={{ display: 'flex', gap: 16, marginTop: 10, fontSize: 11.5, color: 'var(--text-muted)', flexWrap: 'wrap', padding: '8px 12px', background: 'var(--bg-input)', borderRadius: 8 }}>
              <span><UserCheck size={11} style={{ display: 'inline', verticalAlign: -1 }} /> Joining: <b style={{ color: 'var(--text-h)' }}>{o.joining?.joining_status || '—'}</b></span>
              <span>Expected: <b style={{ color: 'var(--text-h)' }}>{fmt(o.joining?.expected_joining)}</b></span>
              <span>Actual: <b style={{ color: 'var(--text-h)' }}>{fmt(o.joining?.actual_joining)}</b></span>
              {o.joining?.employee_status && <span>Employee: <b style={{ color: '#10b981' }}>{o.joining.employee_status}{o.joining.employee_code ? ` (${o.joining.employee_code})` : ''}</b></span>}
            </div>

            <div style={{ display: 'flex', gap: 6, marginTop: 12, flexWrap: 'wrap' }}>
              <button onClick={() => download(o)} style={mini}><Download size={11} /> Download PDF</button>
              <button onClick={() => decide(o, 'approve')} disabled={isLocked} style={{ ...mini, color: '#10b981', opacity: isLocked ? 0.4 : 1 }}><Check size={11} /> Approve</button>
              <button onClick={() => decide(o, 'changes')} disabled={isLocked} style={{ ...mini, color: '#f59e0b', opacity: isLocked ? 0.4 : 1 }}><Edit3 size={11} /> Request Changes</button>
              <button onClick={() => decide(o, 'reject')} disabled={isLocked} style={{ ...mini, color: '#f87171', opacity: isLocked ? 0.4 : 1 }}><XCircle size={11} /> Reject</button>
              <button onClick={() => decide(o, 'feedback')} style={{ ...mini }}><MessageSquare size={11} /> Feedback</button>
            </div>
          </div>
        )
      })}
    </div>
  )
}

const mini = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }

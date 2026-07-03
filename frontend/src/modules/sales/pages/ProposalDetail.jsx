import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import { ArrowLeft, Send, Copy, Receipt, Trash2, FileText, CheckCircle, XCircle, Download } from 'lucide-react'
import { salesApi } from '@/services/salesApi'
import StatusBadge from '../components/StatusBadge'
import ActivityTimeline from '../components/ActivityTimeline'

const fmt = (v) => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'

export default function ProposalDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [proposal, setProposal] = useState(null)
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState(null)

  const showToast = (msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }

  useEffect(() => {
    salesApi.proposals.get(id).then(p => { setProposal(p); setLoading(false) })
  }, [id])

  if (loading) return (
    <div className="space-y-4 animate-fade-in">
      {[1, 2, 3].map(i => <div key={i} className="skeleton h-28 rounded-2xl" style={{ background: 'var(--border)' }} />)}
    </div>
  )

  if (!proposal) return (
    <div className="flex flex-col items-center justify-center min-h-[50vh] gap-3">
      <p className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Proposal not found</p>
      <button onClick={() => navigate('/app/sales/proposals')} className="text-sm" style={{ color: '#a78bfa' }}>← Back to Proposals</button>
    </div>
  )

  const subtotal = proposal.items?.reduce((s, r) => s + r.amount, 0) || 0
  const taxTotal = proposal.items?.reduce((s, r) => s + (r.amount * r.tax / 100), 0) || 0
  const grand = subtotal + taxTotal

  const events = [
    { type: 'created', label: 'Proposal created', date: proposal.created_at },
    proposal.sent_at && { type: 'sent', label: 'Sent to client', detail: proposal.client_email, date: proposal.sent_at },
    proposal.accepted_at && { type: 'accepted', label: 'Accepted by client', date: proposal.accepted_at },
    proposal.declined_at && { type: 'declined', label: 'Declined by client', date: proposal.declined_at },
  ].filter(Boolean)

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">
      {toast && <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl" style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      {/* Top bar */}
      <div className="flex items-center justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => navigate('/app/sales/proposals')} className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]" style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <div className="flex items-center gap-2">
              <p className="font-black text-base" style={{ color: 'var(--text-h)' }}>PROP-{String(proposal.id).padStart(3, '0')}</p>
              <StatusBadge status={proposal.status} size="lg" />
            </div>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>{proposal.subject}</p>
          </div>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {[
            { icon: Send, label: 'Send', action: () => showToast('Proposal sent to client!') },
            { icon: Copy, label: 'Duplicate', action: () => showToast('Duplicated!') },
            { icon: FileText, label: 'To Estimate', action: () => showToast('Converted to estimate!') },
            { icon: Receipt, label: 'To Invoice', action: () => showToast('Converted to invoice!') },
            { icon: Download, label: 'Export PDF', action: () => showToast('PDF ready!') },
          ].map(a => (
            <button key={a.label} onClick={a.action} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
              style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <a.icon size={13} /> {a.label}
            </button>
          ))}
          <button onClick={() => { navigate('/app/sales/proposals') }} className="p-2 rounded-xl transition-colors hover:bg-[rgba(239,68,68,0.08)]" style={{ border: '1px solid rgba(239,68,68,0.2)' }}>
            <Trash2 size={14} style={{ color: '#f87171' }} />
          </button>
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
        {/* Document Preview */}
        <div className="lg:col-span-2 space-y-5">
          <div className="card-3d" style={{ padding: '28px' }}>
            {/* Document Header */}
            <div className="flex items-start justify-between mb-8">
              <div>
                <div className="w-10 h-10 rounded-2xl flex items-center justify-center mb-3"
                  style={{ background: 'linear-gradient(145deg,#9f67ff,#7C3AED)', boxShadow: '0 6px 18px rgba(124,58,237,0.4)' }}>
                  <FileText size={18} className="text-white" />
                </div>
                <p className="text-xl font-black" style={{ color: 'var(--text-h)' }}>Proposal</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>PROP-{String(proposal.id).padStart(3, '0')}</p>
              </div>
              <div className="text-right">
                <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Sangoe Technologies Pvt. Ltd.</p>
                <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>hello@sangoe.in</p>
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Mumbai, Maharashtra, India</p>
              </div>
            </div>

            {/* Client & Dates */}
            <div className="grid grid-cols-2 gap-6 mb-8 p-4 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
              <div>
                <p className="label-caps mb-1">Prepared For</p>
                <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{proposal.client}</p>
              </div>
              <div className="grid grid-cols-2 gap-4">
                <div>
                  <p className="label-caps mb-1">Date</p>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(proposal.created_at)}</p>
                </div>
                <div>
                  <p className="label-caps mb-1">Expires</p>
                  <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(proposal.expiry_date)}</p>
                </div>
              </div>
            </div>

            {/* Subject */}
            <div className="mb-6">
              <p className="label-caps mb-1">Subject</p>
              <p className="font-bold" style={{ color: 'var(--text-h)' }}>{proposal.subject}</p>
              {proposal.notes && <p className="text-sm mt-2" style={{ color: 'var(--text-muted)' }}>{proposal.notes}</p>}
            </div>

            {/* Line Items */}
            {proposal.items && proposal.items.length > 0 && (
              <div className="mb-6">
                <p className="label-caps mb-3">Line Items</p>
                <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                  <table className="w-full text-xs">
                    <thead><tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
                      {['Item', 'Description', 'Qty', 'Rate', 'Tax', 'Amount'].map(h => <th key={h} className="px-4 py-2.5 text-left label-caps">{h}</th>)}
                    </tr></thead>
                    <tbody>
                      {proposal.items.map((item, i) => (
                        <tr key={i} style={{ borderBottom: i < proposal.items.length - 1 ? '1px solid var(--border)' : 'none' }}>
                          <td className="px-4 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{item.item_name}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.description}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.qty}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{fmt(item.rate)}</td>
                          <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.tax}%</td>
                          <td className="px-4 py-2.5 font-bold" style={{ color: '#a78bfa' }}>{fmt(item.amount)}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>

                {/* Totals */}
                <div className="mt-4 ml-auto w-64 space-y-1.5 text-xs">
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}>
                    <span>Subtotal</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(subtotal)}</span>
                  </div>
                  <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}>
                    <span>GST</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(taxTotal)}</span>
                  </div>
                  <div className="flex justify-between pt-2 font-black text-sm" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-h)' }}>
                    <span>Grand Total</span><span style={{ color: '#a78bfa' }}>{fmt(grand)}</span>
                  </div>
                </div>
              </div>
            )}

            {/* Client Actions */}
            {(proposal.status === 'Sent' || proposal.status === 'Open') && (
              <div className="mt-6 pt-5 flex gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                <button onClick={() => showToast('Status updated to Accepted!')} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                  <CheckCircle size={14} /> Mark Accepted
                </button>
                <button onClick={() => showToast('Status updated to Declined!')} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
                  <XCircle size={14} /> Mark Declined
                </button>
              </div>
            )}
          </div>
        </div>

        {/* Right panel: Info + Timeline */}
        <div className="space-y-4">
          <div className="card-3d" style={{ padding: '20px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Proposal Info</h3>
            <div className="space-y-3 text-xs">
              {[
                { label: 'Client', value: proposal.client },
                { label: 'Amount', value: fmt(proposal.amount) },
                { label: 'Status', value: <StatusBadge status={proposal.status} /> },
                { label: 'Created', value: fmtDate(proposal.created_at) },
                { label: 'Expires', value: fmtDate(proposal.expiry_date) },
              ].map(row => (
                <div key={row.label} className="flex justify-between items-center">
                  <span style={{ color: 'var(--text-muted)' }}>{row.label}</span>
                  <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{row.value}</span>
                </div>
              ))}
            </div>
          </div>

          <div className="card-3d" style={{ padding: '20px' }}>
            <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Activity</h3>
            <ActivityTimeline events={events} />
          </div>
        </div>
      </div>
    </div>
  )
}

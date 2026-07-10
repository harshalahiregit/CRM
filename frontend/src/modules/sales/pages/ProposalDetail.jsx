import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Send, Trash2, FileText,
  CheckCircle, XCircle, Download, Link2, MessageSquare,
  Lock, Globe, X, Eye, QrCode
} from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { salesApi } from '@/services/salesApi'
import StatusBadge from '../components/StatusBadge'
import ActivityTimeline from '../components/ActivityTimeline'
import ConfirmDialog from '@/components/ui/ConfirmDialog'

const fmt     = v => '₹' + Number(v || 0).toLocaleString('en-IN')
const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtTime = d => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : ''

// Mock comments seeded per proposal
const MOCK_COMMENTS = [
  { id: 1, author: 'Zafar Farooque', role: 'Admin', text: 'Proposal drafted and ready for review.', internal: true, created_at: new Date(Date.now() - 86400000 * 2).toISOString() },
  { id: 2, author: 'Client User', role: 'Client', text: 'Looks good. Can we discuss the timeline on a call?', internal: false, created_at: new Date(Date.now() - 86400000).toISOString() },
]

export default function ProposalDetail() {
  const { id }   = useParams()
  const navigate = useNavigate()
  const [proposal, setProposal]       = useState(null)
  const [loading, setLoading]         = useState(true)
  const [toast, setToast]             = useState(null)
  const [showPortal, setShowPortal]   = useState(false)
  const [copied, setCopied]           = useState(false)
  const [comments, setComments]       = useState(MOCK_COMMENTS)
  const [newComment, setNewComment]   = useState('')
  const [isInternal, setIsInternal]   = useState(false)
  const [posting, setPosting]         = useState(false)
  const [confirmDelete, setConfirmDelete] = useState(false)

  const showToast = (msg, type = 'success') => {
    setToast({ msg, type })
    setTimeout(() => setToast(null), 3500)
  }

  const reload = () => salesApi.proposals.get(id).then(setProposal)

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

  const subtotal = Number(proposal.subtotal || 0)
  const taxTotal  = Number(proposal.tax_total || 0)
  const grand     = Number(proposal.total || 0)

  const events = [
    { type: 'created',  label: 'Proposal created',       date: proposal.created_at },
    proposal.sent_at     && { type: 'sent',     label: 'Sent to client', detail: proposal.client_email, date: proposal.sent_at },
    proposal.accepted_at && { type: 'accepted', label: 'Accepted by client',   date: proposal.accepted_at },
    proposal.declined_at && { type: 'declined', label: 'Declined by client',   date: proposal.declined_at },
  ].filter(Boolean)

  const url = `${window.location.origin}/portal/proposals/${proposal.portal_token}`

  const copyLink = () => {
    navigator.clipboard.writeText(url).then(() => {
      setCopied(true); setTimeout(() => setCopied(false), 2000)
    })
  }

  const handleSend = async () => {
    try {
      await salesApi.proposals.send(proposal.id)
      showToast('Proposal sent to client!')
      reload()
    } catch (e) {
      showToast(e.message || 'Failed to send', 'error')
    }
  }

  const handleStatusChange = async (status) => {
    try {
      await salesApi.proposals.updateStatus(proposal.id, status)
      showToast(`Status updated to ${status}!`)
      reload()
    } catch (e) {
      showToast(e.message || 'Failed to update status', 'error')
    }
  }

  const handleGenerateQR = async () => {
    try {
      await salesApi.proposals.generateQR(proposal.id)
      showToast('QR code generated!')
      reload()
    } catch (e) {
      showToast(e.message || 'Failed to generate QR', 'error')
    }
  }

  const handleDownloadPdf = async () => {
    try {
      await salesApi.proposals.downloadPdf(proposal.id, `PROP-${String(proposal.id).padStart(3, '0')}.pdf`)
    } catch (e) {
      showToast(e.message || 'Failed to download PDF', 'error')
    }
  }

  const handleDelete = async () => {
    try {
      await salesApi.proposals.delete(proposal.id)
      showToast('Proposal deleted')
      setTimeout(() => navigate('/app/sales/proposals'), 800)
    } catch (e) {
      showToast(e.message || 'Failed to delete', 'error')
    }
  }

  const postComment = async () => {
    if (!newComment.trim()) return
    setPosting(true)
    await new Promise(r => setTimeout(r, 300))
    setComments(prev => [...prev, {
      id: prev.length + 1,
      author: 'Zafar Farooque',
      role: 'Admin',
      text: newComment.trim(),
      internal: isInternal,
      created_at: new Date().toISOString(),
    }])
    setNewComment('')
    setPosting(false)
    showToast(isInternal ? 'Internal note added' : 'Comment posted')
  }

  return (
    <>
      {toast && (
        <div className="fixed top-5 right-5 z-[9999] px-5 py-3 rounded-2xl text-sm font-semibold text-white shadow-2xl"
          style={{ background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>
          {toast.msg}
        </div>
      )}
      <div className="space-y-6 animate-[tiltIn_0.35s_ease_forwards]">

        {/* Top bar */}
        <div className="flex items-center justify-between flex-wrap gap-3">
          <div className="flex items-center gap-3">
            <button onClick={() => navigate('/app/sales/proposals')}
              className="w-9 h-9 rounded-xl flex items-center justify-center transition-colors hover:bg-[rgba(124,58,237,0.08)]"
              style={{ border: '1px solid var(--border)' }}>
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
            <button onClick={() => setShowPortal(s => !s)}
              className="flex items-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold transition-all hover:scale-[1.02]"
              style={{ background: showPortal ? 'rgba(99,91,255,0.12)' : 'var(--bg-input)', border: `1px solid ${showPortal ? 'rgba(99,91,255,0.4)' : 'var(--border)'}`, color: showPortal ? '#818cf8' : 'var(--text-muted)' }}>
              <Link2 size={13} /> Portal Link
            </button>
            {[
              { icon: Send,     label: 'Send',        action: handleSend },
              { icon: QrCode,   label: 'Generate QR', action: handleGenerateQR },
              { icon: Download, label: 'PDF',         action: handleDownloadPdf },
            ].map(a => (
              <button key={a.label} onClick={a.action}
                className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold transition-all hover:scale-[1.02]"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                <a.icon size={13} /> {a.label}
              </button>
            ))}
            <button onClick={() => setConfirmDelete(true)}
              className="p-2 rounded-xl transition-colors hover:bg-[rgba(239,68,68,0.08)]"
              style={{ border: '1px solid rgba(239,68,68,0.2)' }}>
              <Trash2 size={14} style={{ color: '#f87171' }} />
            </button>
          </div>
        </div>

        {/* Portal Link panel */}
        {showPortal && (
          <div className="rounded-2xl overflow-hidden" style={{ border: '1px solid rgba(99,91,255,0.3)', background: 'rgba(99,91,255,0.04)' }}>
            <div className="flex items-center justify-between px-5 py-3" style={{ borderBottom: '1px solid rgba(99,91,255,0.15)' }}>
              <div className="flex items-center gap-2">
                <Globe size={14} style={{ color: '#818cf8' }} />
                <p className="text-xs font-bold" style={{ color: '#818cf8' }}>Client Portal Link</p>
              </div>
              <div className="flex items-center gap-2">
                <span className="px-2 py-0.5 rounded text-[9px] font-bold"
                  style={{ background: proposal.status === 'Sent' || proposal.status === 'Open' ? 'rgba(16,185,129,0.12)' : 'rgba(100,116,139,0.12)', color: proposal.status === 'Sent' || proposal.status === 'Open' ? '#10b981' : '#94a3b8' }}>
                  {proposal.status === 'Sent' || proposal.status === 'Open' ? '● ACTIVE' : '○ INACTIVE'}
                </span>
                <button onClick={() => setShowPortal(false)} className="p-1 rounded-lg transition-colors hover:bg-[rgba(239,68,68,0.08)]">
                  <X size={13} style={{ color: 'var(--text-muted)' }} />
                </button>
              </div>
            </div>
            <div className="p-5 space-y-4">
              <p className="text-xs" style={{ color: 'var(--text-muted)' }}>
                Share this link with <strong style={{ color: 'var(--text-h)' }}>{proposal.client}</strong> so they can view, accept, or decline this proposal directly.
              </p>
              {/* URL copy row */}
              <div className="flex items-center gap-2">
                <div className="flex-1 flex items-center gap-2 px-3 py-2.5 rounded-xl text-xs font-mono truncate"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                  <Link2 size={11} style={{ flexShrink: 0 }} />
                  <span className="truncate">{url}</span>
                </div>
                <button onClick={copyLink}
                  className="px-4 py-2.5 rounded-xl text-xs font-bold transition-all hover:scale-[1.02]"
                  style={{ background: copied ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#818cf8,#6366f1)', color: '#fff', boxShadow: copied ? '0 4px 12px rgba(16,185,129,0.3)' : '0 4px 12px rgba(99,91,255,0.3)' }}>
                  {copied ? '✓ Copied!' : 'Copy Link'}
                </button>
              </div>
              {/* Info chips */}
              <div className="flex items-center gap-3 flex-wrap">
                {[
                  { icon: CheckCircle, label: 'Client can Accept', color: '#10b981' },
                  { icon: XCircle,     label: 'Client can Decline', color: '#f87171' },
                  { icon: MessageSquare, label: 'Comments enabled', color: '#a78bfa' },
                ].map(chip => (
                  <div key={chip.label} className="flex items-center gap-1.5 text-[11px] font-semibold" style={{ color: chip.color }}>
                    <chip.icon size={11} /> {chip.label}
                  </div>
                ))}
              </div>
            </div>
          </div>
        )}

        <div className="grid grid-cols-1 lg:grid-cols-3 gap-5">
          {/* Document preview */}
          <div className="lg:col-span-2 space-y-5">
            <div className="card-3d" style={{ padding: '28px' }}>
              {/* Doc header */}
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

              {/* Client & dates */}
              <div className="grid grid-cols-2 gap-6 mb-8 p-4 rounded-2xl" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div>
                  <p className="label-caps mb-1">Prepared For</p>
                  <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>{proposal.client}</p>
                </div>
                <div className="grid grid-cols-2 gap-4">
                  <div><p className="label-caps mb-1">Date</p><p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(proposal.created_at)}</p></div>
                  <div><p className="label-caps mb-1">Expires</p><p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(proposal.expiry_date)}</p></div>
                </div>
              </div>

              {/* Subject */}
              <div className="mb-6">
                <p className="label-caps mb-1">Subject</p>
                <p className="font-bold" style={{ color: 'var(--text-h)' }}>{proposal.subject}</p>
                {proposal.notes && <p className="text-sm mt-2" style={{ color: 'var(--text-muted)' }}>{proposal.notes}</p>}
              </div>

              {/* Line items */}
              {proposal.line_items && proposal.line_items.length > 0 && (
                <div className="mb-6">
                  <p className="label-caps mb-3">Line Items</p>
                  <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
                    <table className="w-full text-xs">
                      <thead><tr style={{ background: 'var(--bg-input)', borderBottom: '1px solid var(--border)' }}>
                        {['Item', 'Description', 'Qty', 'Rate', 'Tax', 'Amount'].map(h => <th key={h} className="px-4 py-2.5 text-left label-caps">{h}</th>)}
                      </tr></thead>
                      <tbody>
                        {proposal.line_items.map((item, i) => (
                          <tr key={i} style={{ borderBottom: i < proposal.line_items.length - 1 ? '1px solid var(--border)' : 'none' }}>
                            <td className="px-4 py-2.5 font-semibold" style={{ color: 'var(--text-h)' }}>{item.item_name}</td>
                            <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.description || '—'}</td>
                            <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.qty}</td>
                            <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{fmt(item.rate)}</td>
                            <td className="px-4 py-2.5" style={{ color: 'var(--text-muted)' }}>{item.tax}%</td>
                            <td className="px-4 py-2.5 font-bold" style={{ color: '#a78bfa' }}>{fmt(item.total)}</td>
                          </tr>
                        ))}
                      </tbody>
                    </table>
                  </div>
                  <div className="mt-4 ml-auto w-64 space-y-1.5 text-xs">
                    <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}><span>Subtotal</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(subtotal)}</span></div>
                    <div className="flex justify-between" style={{ color: 'var(--text-muted)' }}><span>GST</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmt(taxTotal)}</span></div>
                    <div className="flex justify-between pt-2 font-black text-sm" style={{ borderTop: '1px solid var(--border)', color: 'var(--text-h)' }}><span>Grand Total</span><span style={{ color: '#a78bfa' }}>{fmt(grand)}</span></div>
                  </div>
                </div>
              )}

              {/* Client actions */}
              {(proposal.status === 'Sent' || proposal.status === 'Open') && (
                <div className="mt-6 pt-5 flex gap-3" style={{ borderTop: '1px solid var(--border)' }}>
                  <button onClick={() => handleStatusChange('Accepted')} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
                    <CheckCircle size={14} /> Mark Accepted
                  </button>
                  <button onClick={() => handleStatusChange('Declined')} className="flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm font-bold" style={{ background: 'rgba(239,68,68,0.1)', color: '#f87171', border: '1px solid rgba(239,68,68,0.2)' }}>
                    <XCircle size={14} /> Mark Declined
                  </button>
                </div>
              )}
            </div>

            {/* ── Comments section ── */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <div className="flex items-center gap-2 mb-5">
                <MessageSquare size={15} style={{ color: '#a78bfa' }} />
                <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Comments</h3>
                <span className="ml-auto px-2 py-0.5 rounded-lg text-[10px] font-bold" style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>
                  {comments.length}
                </span>
              </div>

              {/* Comment list */}
              <div className="space-y-3 mb-5">
                {comments.map(c => (
                  <div key={c.id} className="flex gap-3">
                    {/* Avatar */}
                    <div className="w-7 h-7 rounded-xl flex items-center justify-center flex-shrink-0 text-[10px] font-black text-white mt-0.5"
                      style={{ background: c.internal ? 'linear-gradient(135deg,#f59e0b,#d97706)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
                      {c.author.charAt(0)}
                    </div>
                    <div className="flex-1">
                      <div className="flex items-center gap-2 mb-1">
                        <span className="text-xs font-bold" style={{ color: 'var(--text-h)' }}>{c.author}</span>
                        {c.internal && (
                          <span className="flex items-center gap-0.5 px-1.5 py-0.5 rounded text-[9px] font-bold"
                            style={{ background: 'rgba(245,158,11,0.1)', color: '#f59e0b' }}>
                            <Lock size={8} /> Internal
                          </span>
                        )}
                        <span className="text-[10px] ml-auto" style={{ color: 'var(--text-muted)' }}>{fmtTime(c.created_at)}</span>
                      </div>
                      <div className="px-3 py-2.5 rounded-xl text-xs leading-relaxed"
                        style={{ background: c.internal ? 'rgba(245,158,11,0.06)' : 'var(--bg-input)', border: `1px solid ${c.internal ? 'rgba(245,158,11,0.2)' : 'var(--border)'}`, color: 'var(--text-muted)' }}>
                        {c.text}
                      </div>
                    </div>
                  </div>
                ))}
              </div>

              {/* Add comment */}
              <div className="pt-4" style={{ borderTop: '1px solid var(--border)' }}>
                {/* Internal / Client toggle */}
                <div className="flex items-center gap-2 mb-3">
                  <button onClick={() => setIsInternal(false)}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                    style={{ background: !isInternal ? 'rgba(124,58,237,0.12)' : 'transparent', color: !isInternal ? '#a78bfa' : 'var(--text-muted)', border: `1px solid ${!isInternal ? 'rgba(124,58,237,0.3)' : 'var(--border)'}` }}>
                    <Globe size={11} /> Client Note
                  </button>
                  <button onClick={() => setIsInternal(true)}
                    className="flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold transition-all"
                    style={{ background: isInternal ? 'rgba(245,158,11,0.12)' : 'transparent', color: isInternal ? '#fbbf24' : 'var(--text-muted)', border: `1px solid ${isInternal ? 'rgba(245,158,11,0.3)' : 'var(--border)'}` }}>
                    <Lock size={11} /> Internal
                  </button>
                </div>
                <textarea
                  className="input-3d text-sm resize-none w-full"
                  rows={3}
                  placeholder={isInternal ? 'Add an internal note (only visible to your team)…' : 'Add a comment (visible to client on portal)…'}
                  value={newComment}
                  onChange={e => setNewComment(e.target.value)}
                />
                <div className="flex justify-end mt-2">
                  <button onClick={postComment} disabled={!newComment.trim() || posting}
                    className="flex items-center gap-1.5 px-4 py-2 rounded-xl text-xs font-bold text-white transition-all hover:scale-[1.02] disabled:opacity-50"
                    style={{ background: isInternal ? 'linear-gradient(135deg,#f59e0b,#d97706)' : 'linear-gradient(135deg,#7C3AED,#5b21b6)', boxShadow: isInternal ? '0 4px 12px rgba(245,158,11,0.35)' : '0 4px 12px rgba(124,58,237,0.35)' }}>
                    {posting ? 'Posting…' : isInternal ? '+ Add Internal Note' : '+ Post Comment'}
                  </button>
                </div>
              </div>
            </div>
          </div>

          {/* Right panel */}
          <div className="space-y-4">
            {/* Info card */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Proposal Info</h3>
              <div className="space-y-3 text-xs">
                {[
                  { label: 'Client',   value: proposal.client },
                  { label: 'Amount',   value: fmt(grand) },
                  { label: 'Status',   value: <StatusBadge status={proposal.status} /> },
                  { label: 'Created',  value: fmtDate(proposal.created_at) },
                  { label: 'Expires',  value: fmtDate(proposal.open_till) },
                ].map(row => (
                  <div key={row.label} className="flex justify-between items-center py-1" style={{ borderBottom: '1px solid var(--border)' }}>
                    <span style={{ color: 'var(--text-muted)' }}>{row.label}</span>
                    <span className="font-semibold" style={{ color: 'var(--text-h)' }}>{row.value}</span>
                  </div>
                ))}
              </div>
            </div>

            {/* Portal link quick card */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <div className="flex items-center gap-2 mb-3">
                <Link2 size={14} style={{ color: '#818cf8' }} />
                <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Portal</h3>
              </div>
              <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
                Client can view &amp; respond via their portal link.
              </p>
              <button onClick={copyLink}
                className="w-full py-2 rounded-xl text-xs font-bold transition-all hover:scale-[1.01]"
                style={{ background: copied ? 'rgba(16,185,129,0.1)' : 'rgba(99,91,255,0.1)', color: copied ? '#10b981' : '#818cf8', border: `1px solid ${copied ? 'rgba(16,185,129,0.25)' : 'rgba(99,91,255,0.25)'}` }}>
                {copied ? '✓ Link Copied!' : 'Copy Portal Link'}
              </button>
              {proposal.qr_code_data && (
                <div className="flex flex-col items-center mt-4 pt-4" style={{ borderTop: '1px solid var(--border)' }}>
                  <QRCodeSVG value={proposal.qr_code_data} size={120} />
                  <p className="text-[10px] mt-2" style={{ color: 'var(--text-muted)' }}>Scan to view proposal</p>
                </div>
              )}
            </div>

            {/* Email tracking */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <div className="flex items-center gap-2 mb-3">
                <Eye size={14} style={{ color: '#a78bfa' }} />
                <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Email Tracking</h3>
              </div>
              {proposal.email_opened_count > 0 ? (
                <div className="space-y-2 text-xs">
                  <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>First opened</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{fmtDate(proposal.email_opened_at)}</span></div>
                  <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Times opened</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{proposal.email_opened_count}</span></div>
                  {proposal.email_opened_device && <div className="flex justify-between"><span style={{ color: 'var(--text-muted)' }}>Device</span><span className="font-semibold truncate max-w-[140px]" style={{ color: 'var(--text-h)' }}>{proposal.email_opened_device}</span></div>}
                </div>
              ) : (
                <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Not opened yet. Tracking activates once the client opens the proposal email.</p>
              )}
            </div>

            {/* Activity */}
            <div className="card-3d" style={{ padding: '20px' }}>
              <h3 className="font-bold text-sm mb-4" style={{ color: 'var(--text-h)' }}>Activity</h3>
              <ActivityTimeline events={events} />
            </div>
          </div>
        </div>
      </div>

      {confirmDelete && (
        <ConfirmDialog
          title="Delete this proposal?"
          message={`This will permanently delete PROP-${String(proposal.id).padStart(3, '0')}. This cannot be undone.`}
          confirmLabel="Delete"
          tone="danger"
          onConfirm={handleDelete}
          onCancel={() => setConfirmDelete(false)}
        />
      )}
    </>
  )
}

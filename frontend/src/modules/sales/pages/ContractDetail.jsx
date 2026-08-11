import { useState, useEffect } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, PenLine, Download, Send, Eye, RefreshCw, QrCode,
  FileSignature, MessageSquare, History, ListTodo, Paperclip, X,
} from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { createPortal } from 'react-dom'
import { contractApi } from '@/services/contractApi'
import { taskApi } from '@/services/taskApi'
import { useToast } from '@/hooks/useToast'
import PagesEditor from '../components/PagesEditor'
import SignatureModal from '../components/SignatureModal'
import RichTextEditor from '@/components/ui/RichTextEditor'
import { richHtml } from '@/lib/richText'

const fmt = v => '₹' + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
const d10 = s => (s ? String(s).slice(0, 10) : '—')
const STATUS_COLORS = { draft: '#94a3b8', active: '#10b981', expired: '#f59e0b', terminated: '#ef4444', renewed: '#3b82f6' }

const TABS = ['Overview', 'Content', 'Discussion', 'Renewal History', 'Linked Tasks', 'Attachments']

export default function ContractDetail() {
  const { id } = useParams()
  const nav = useNavigate()
  const toast = useToast()
  const [contract, setContract] = useState(null)
  const [tab, setTab] = useState('Overview')
  const [signOpen, setSignOpen] = useState(false)
  const [sendOpen, setSendOpen] = useState(false)
  const [showQr, setShowQr] = useState(false)
  const [busy, setBusy] = useState(false)

  const load = () => contractApi.get(id).then(setContract).catch(e => { toast.error(e.message); nav('/app/sales/contracts') })
  useEffect(() => { load() }, [id])

  if (!contract) return <div className="p-6"><div className="skeleton h-40 rounded-2xl" style={{ background: 'var(--border)' }} /></div>

  const portalUrl = `${window.location.origin}/portal/contracts/${contract.public_token}`
  const sig = contract.signature_data ? safeJson(contract.signature_data) : null

  const doSign = async (payload) => {
    setBusy(true)
    try { await contractApi.sign(contract.id, payload); toast.success('Contract signed'); setSignOpen(false); load() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }

  const renew = async () => {
    try { const n = await contractApi.renew(contract.id); toast.success(`Renewed as ${n.reference_no}`); nav(`/app/sales/contracts/${n.id}`) }
    catch (e) { toast.error(e.message) }
  }

  return (
    <div className="space-y-6 animate-[tiltIn_0.35s_ease]">
      {/* Header */}
      <div className="flex items-start justify-between flex-wrap gap-3">
        <div className="flex items-center gap-3">
          <button onClick={() => nav('/app/sales/contracts')} className="w-9 h-9 rounded-xl flex items-center justify-center hover:bg-[rgba(124,58,237,0.08)]" style={{ border: '1px solid var(--border)' }}>
            <ArrowLeft size={16} style={{ color: 'var(--text-muted)' }} />
          </button>
          <div>
            <h1 className="font-black" style={{ fontSize: 'clamp(1.2rem,2vw,1.5rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>{contract.subject}</h1>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              {contract.reference_no} · {contract.client?.company || '—'} · v{contract.version}
              <span className="ml-2 text-[10px] font-bold px-2 py-0.5 rounded-md" style={{ background: `${STATUS_COLORS[contract.status]}22`, color: STATUS_COLORS[contract.status] }}>{contract.status}</span>
              {contract.signed_at && <span className="ml-1.5 text-[10px] font-bold px-2 py-0.5 rounded-md" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>✓ Signed</span>}
            </p>
          </div>
        </div>
        <div className="flex items-center gap-2 flex-wrap">
          {!contract.signed_at && (
            <button onClick={() => setSignOpen(true)} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
              <PenLine size={13} /> Sign
            </button>
          )}
          {[
            { icon: Eye, label: 'View as Client', action: () => window.open(portalUrl, '_blank') },
            { icon: QrCode, label: 'QR', action: () => setShowQr(q => !q) },
            { icon: Send, label: 'Send Email', action: () => setSendOpen(true) },
            { icon: Download, label: 'PDF', action: () => contractApi.downloadPdf(contract.id, `${contract.reference_no}.pdf`).catch(e => toast.error(e.message)) },
            ...(contract.status === 'active' ? [{ icon: RefreshCw, label: 'Renew', action: renew }] : []),
          ].map(a => (
            <button key={a.label} onClick={a.action} className="flex items-center gap-1.5 px-3 py-2 rounded-xl text-xs font-semibold" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
              <a.icon size={13} /> {a.label}
            </button>
          ))}
        </div>
      </div>

      {showQr && (
        <div className="card-3d flex items-center gap-5" style={{ padding: '20px' }}>
          <QRCodeSVG value={portalUrl} size={120} />
          <div>
            <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Legal verification QR</p>
            <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>Scanning opens the public contract view with the signature audit — printed on the PDF footer as a link too.</p>
            <p className="text-[11px] mt-1.5 break-all" style={{ color: 'var(--accent)' }}>{portalUrl}</p>
          </div>
        </div>
      )}

      {/* Tabs */}
      <div className="flex gap-1 flex-wrap card-3d" style={{ padding: '8px' }}>
        {TABS.map(t => (
          <button key={t} onClick={() => setTab(t)} className="px-3.5 py-2 rounded-xl text-xs font-bold transition-colors"
            style={{ background: tab === t ? 'linear-gradient(135deg,#7C3AED,#6d28d9)' : 'transparent', color: tab === t ? '#fff' : 'var(--text-muted)' }}>
            {t}
          </button>
        ))}
      </div>

      {tab === 'Overview' && <OverviewTab contract={contract} sig={sig} />}
      {tab === 'Content' && <ContentTab contract={contract} reload={load} toast={toast} />}
      {tab === 'Discussion' && <DiscussionTab contract={contract} reload={load} toast={toast} />}
      {tab === 'Renewal History' && <RenewalsTab id={contract.id} nav={nav} />}
      {tab === 'Linked Tasks' && <TasksTab contract={contract} toast={toast} />}
      {tab === 'Attachments' && (
        <div className="card-3d text-center py-10">
          <Paperclip size={22} className="mx-auto mb-2" style={{ color: 'var(--text-faint)' }} />
          <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>Contract attachments are coming soon</p>
          <p className="text-xs mt-1" style={{ color: 'var(--text-muted)' }}>File storage for contracts lands with the shared attachments work. Files on the customer's Attachments tab are available today.</p>
        </div>
      )}

      {signOpen && <SignatureModal onSign={doSign} onClose={() => setSignOpen(false)} busy={busy} />}
      {sendOpen && <SendContractModal contract={contract} onClose={() => setSendOpen(false)} toast={toast} />}
    </div>
  )
}

function safeJson(s) { try { return JSON.parse(s) } catch { return null } }

function OverviewTab({ contract, sig }) {
  const rows = [
    ['Client', contract.client?.company || '—'],
    ['Contract Type', contract.type?.name || '—'],
    ['Value', fmt(contract.value)],
    ['Period', `${d10(contract.start_date)} — ${d10(contract.end_date)}`],
    ['Renewal notice', contract.renewal_notice_days ? `${contract.renewal_notice_days} days before expiry` : '—'],
    ['Created by', contract.creator?.name || '—'],
    ['Renewed from', contract.renewed_from?.reference_no || contract.renewedFrom?.reference_no || '—'],
  ]
  return (
    <div className="grid lg:grid-cols-2 gap-4 items-start">
      <div className="card-3d" style={{ padding: '20px' }}>
        <p className="label-caps mb-3" style={{ color: 'var(--accent)' }}>Details</p>
        {rows.map(([k, v]) => (
          <div key={k} className="flex justify-between py-2 text-xs" style={{ borderBottom: '1px solid var(--border)' }}>
            <span style={{ color: 'var(--text-muted)' }}>{k}</span><span className="font-semibold" style={{ color: 'var(--text-h)' }}>{v}</span>
          </div>
        ))}
        {contract.description && <div className="rich-content text-xs mt-3" style={{ color: 'var(--text-muted)' }} dangerouslySetInnerHTML={richHtml(contract.description)} />}
      </div>
      <div className="card-3d" style={{ padding: '20px' }}>
        <p className="label-caps mb-3" style={{ color: 'var(--accent)' }}>Signature</p>
        {sig ? (
          <div>
            {sig.image && <img src={sig.image} alt="signature" className="rounded-xl mb-3" style={{ maxHeight: 90, background: '#fff', padding: 8, border: '1px solid var(--border)' }} />}
            <div className="text-xs space-y-1.5">
              <p><b style={{ color: 'var(--text-h)' }}>{sig.name}</b>{sig.email ? <span style={{ color: 'var(--text-muted)' }}> · {sig.email}</span> : null} <span style={{ color: 'var(--text-muted)' }}>({sig.method})</span></p>
              <p style={{ color: 'var(--text-muted)' }}>Signed {String(sig.at || '').slice(0, 16).replace('T', ' ')} · IP {sig.ip || '—'}</p>
              {sig.user_agent && <p className="truncate" style={{ color: 'var(--text-faint)' }}>{sig.user_agent}</p>}
            </div>
          </div>
        ) : (
          <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Not signed yet. Use <b>Sign</b> for an internal signature or <b>Send Email</b> so the client can sign via the public link.</p>
        )}
        <div className="mt-4 inline-block px-4 py-2 rounded-xl font-black text-sm" style={{ border: '2px solid var(--accent)', color: 'var(--accent)', transform: 'rotate(-3deg)' }}>
          {JSON.parse(localStorage.getItem('crm_tenant') || '{}')?.name || 'Company'} ✓
        </div>
      </div>
    </div>
  )
}

function ContentTab({ contract, reload, toast }) {
  const [pages, setPages] = useState((contract.pages || []).map(p => ({ title: p.title, content: p.content })))
  const [saving, setSaving] = useState(false)
  const save = async () => {
    setSaving(true)
    try { await contractApi.update(contract.id, { pages }); toast.success('Content saved'); reload() }
    catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }
  return (
    <div className="card-3d space-y-4" style={{ padding: '20px' }}>
      <PagesEditor pages={pages.length ? pages : [{ title: 'Page 1', content: '' }]} onChange={setPages} minHeight={260} />
      <div className="flex justify-end">
        <button onClick={save} disabled={saving} className="px-5 py-2.5 rounded-xl text-sm font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{saving ? 'Saving…' : 'Save Content'}</button>
      </div>
    </div>
  )
}

function DiscussionTab({ contract, reload, toast }) {
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const add = async () => {
    if (!body.trim()) return
    setBusy(true)
    try { await contractApi.comments.add(contract.id, body); setBody(''); reload() }
    catch (e) { toast.error(e.message) } finally { setBusy(false) }
  }
  const del = async (cid) => {
    try { await contractApi.comments.remove(contract.id, cid); reload() }
    catch (e) { toast.error(e.message) }
  }
  const comments = contract.comments || []
  return (
    <div className="space-y-4 max-w-3xl">
      <div className="card-3d" style={{ padding: '16px' }}>
        <textarea rows={2} className="input-3d text-sm resize-none" placeholder="Add to the discussion…" value={body} onChange={e => setBody(e.target.value)} />
        <div className="flex justify-end mt-2">
          <button onClick={add} disabled={busy} className="px-4 py-2 rounded-xl text-xs font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Post</button>
        </div>
      </div>
      {!comments.length ? (
        <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>No discussion yet.</p>
      ) : comments.map(c => (
        <div key={c.id} className="card-3d flex items-start justify-between gap-3" style={{ padding: '14px 16px' }}>
          <div>
            <p className="text-sm whitespace-pre-wrap" style={{ color: 'var(--text-h)' }}>{c.body}</p>
            <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>{c.author?.name || c.author_name || 'Client'} · {d10(c.created_at)}</p>
          </div>
          <button onClick={() => del(c.id)} className="p-1 rounded hover:bg-[rgba(239,68,68,0.08)]"><X size={12} style={{ color: '#f87171' }} /></button>
        </div>
      ))}
    </div>
  )
}

function RenewalsTab({ id, nav }) {
  const [chain, setChain] = useState(null)
  useEffect(() => { contractApi.renewals(id).then(setChain).catch(() => setChain([])) }, [id])
  if (!chain) return <div className="skeleton h-24 rounded-2xl" style={{ background: 'var(--border)' }} />
  return (
    <div className="card-3d overflow-hidden max-w-3xl" style={{ padding: 0 }}>
      <table className="w-full text-xs">
        <thead><tr style={{ background: 'rgba(124,58,237,0.04)', borderBottom: '1px solid var(--border)' }}>
          {['Reference', 'Version', 'Period', 'Value', 'Status', 'Signed'].map(h => <th key={h} className="py-3 px-4 text-left label-caps">{h}</th>)}
        </tr></thead>
        <tbody>
          {chain.map(c => (
            <tr key={c.id} className="cursor-pointer" onClick={() => nav(`/app/sales/contracts/${c.id}`)}
              style={{ borderBottom: '1px solid var(--border)', background: c.is_current ? 'rgba(124,58,237,0.05)' : 'transparent' }}>
              <td className="py-3 px-4 font-bold" style={{ color: 'var(--text-h)' }}>{c.reference_no}{c.is_current ? ' ←' : ''}</td>
              <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>v{c.version}</td>
              <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{d10(c.start_date)} — {d10(c.end_date)}</td>
              <td className="py-3 px-4 font-semibold" style={{ color: '#10b981' }}>{fmt(c.value)}</td>
              <td className="py-3 px-4" style={{ color: 'var(--text-muted)' }}>{c.status}</td>
              <td className="py-3 px-4" style={{ color: c.signed_at ? '#10b981' : 'var(--text-muted)' }}>{c.signed_at ? '✓' : '—'}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

function TasksTab({ contract, toast }) {
  const [tasks, setTasks] = useState(null)
  const [name, setName] = useState('')
  const load = () => taskApi.list({ rel_type: 'contract', rel_id: contract.id }).then(r => setTasks(r?.data ?? r ?? [])).catch(() => setTasks([]))
  useEffect(() => { load() }, [contract.id])
  const add = async () => {
    if (!name.trim()) return
    try { await taskApi.create({ name, rel_type: 'contract', rel_id: contract.id }); setName(''); load(); toast.success('Task created') }
    catch (e) { toast.error(e.message) }
  }
  return (
    <div className="space-y-4 max-w-3xl">
      <div className="flex gap-2">
        <input className="input-3d text-sm flex-1" placeholder="New task linked to this contract…" value={name} onChange={e => setName(e.target.value)} onKeyDown={e => e.key === 'Enter' && add()} />
        <button onClick={add} className="px-4 py-2 rounded-xl text-xs font-bold text-white" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>Add Task</button>
      </div>
      {!tasks ? <div className="skeleton h-16 rounded-2xl" style={{ background: 'var(--border)' }} /> :
        !tasks.length ? <p className="text-xs text-center py-6" style={{ color: 'var(--text-muted)' }}>No tasks linked to this contract.</p> :
        tasks.map(t => (
          <div key={t.id} className="card-3d flex items-center justify-between" style={{ padding: '12px 16px' }}>
            <p className="text-sm font-semibold" style={{ color: 'var(--text-h)' }}>{t.name}</p>
            <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>{t.status?.name || t.priority || ''} {t.due_date ? `· due ${d10(t.due_date)}` : ''}</span>
          </div>
        ))}
    </div>
  )
}

function SendContractModal({ contract, onClose, toast }) {
  const [to, setTo] = useState('')
  const [subject, setSubject] = useState(`Contract: ${contract.subject}`)
  const [body, setBody] = useState('<p>Please find the contract attached. You can review and sign it online using the button below.</p>')
  const [sending, setSending] = useState(false)

  const send = async () => {
    if (!to.trim()) return toast.error('Recipient email required')
    setSending(true)
    try { await contractApi.send(contract.id, { to, subject, body }); toast.success('Contract emailed'); onClose() }
    catch (e) { toast.error(e.message) } finally { setSending(false) }
  }

  return createPortal(
    <>
      <div className="drawer-backdrop" />
      <div className="drawer-panel" style={{ width: 'min(620px, 96vw)' }}>
        <div className="drawer-header">
          <h2 className="font-black text-lg" style={{ color: 'var(--text-h)' }}>Send Contract</h2>
          <button onClick={onClose} className="w-9 h-9 rounded-xl flex items-center justify-center" style={{ border: '1px solid var(--border)' }}><X size={16} style={{ color: 'var(--text-muted)' }} /></button>
        </div>
        <div className="drawer-body space-y-4">
          <div><label className="label">To *</label><input className="input-3d text-sm" placeholder="client@company.com" value={to} onChange={e => setTo(e.target.value)} /></div>
          <div><label className="label">Subject</label><input className="input-3d text-sm" value={subject} onChange={e => setSubject(e.target.value)} /></div>
          <div><label className="label">Message</label><RichTextEditor value={body} onChange={setBody} minHeight={140} /></div>
          <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>The PDF is attached automatically and the email includes the secure sign-online link.</p>
        </div>
        <div className="drawer-footer">
          <button onClick={onClose} className="flex-1 py-3 rounded-2xl text-sm font-semibold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
          <button onClick={send} disabled={sending} className="flex-[2] py-3 rounded-2xl text-sm font-bold text-white flex items-center justify-center gap-2 disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#10b981,#059669)' }}>
            <Send size={14} /> {sending ? 'Sending…' : 'Send'}
          </button>
        </div>
      </div>
    </>,
    document.body,
  )
}

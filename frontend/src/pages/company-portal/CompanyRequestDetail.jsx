import { useState, useEffect, useCallback, useRef } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, FileText, Users, Calendar, FileCheck, Clock, FolderOpen, MessageSquare,
  Activity, Upload, Download, Trash2, Send, LayoutList,
} from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'
import CompanyCandidates from './CompanyCandidates'
import CompanyInterviews from './CompanyInterviews'
import CompanyOffers from './CompanyOffers'

const unwrap = r => r?.data ?? r
const STATUS = {
  Draft: '#94a3b8', Submitted: '#3b82f6', Under_Review: '#f59e0b', Approved: '#10b981',
  Rejected: '#ef4444', Converted: '#a78bfa', Completed: '#22d3ee',
}
const label = s => (s || '').replace(/_/g, ' ')
const money = (a, b) => (!a && !b) ? '—' : `₹${a ? (a / 100000).toFixed(1) : '0'}–${b ? (b / 100000).toFixed(1) : '0'}L`
const fmt = d => d ? new Date(d).toLocaleDateString() : '—'

const TABS = [
  ['overview', 'Overview', FileText], ['jd', 'JD', LayoutList], ['candidates', 'Candidates', Users],
  ['interviews', 'Interviews', Calendar], ['offers', 'Offers', FileCheck], ['timeline', 'Timeline', Clock],
  ['documents', 'Documents', FolderOpen], ['messages', 'Messages', MessageSquare], ['activity', 'Activity', Activity],
]
const DOC_TYPES = ['jd', 'nda', 'po', 'agreement', 'scope', 'sow', 'other']

export default function CompanyRequestDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const [tab, setTab] = useState('overview')
  const [d, setD] = useState(null)
  const [loading, setLoading] = useState(true)
  const [toast, setToast] = useState(null)
  // Stable identity so tab children (whose loaders depend on it) don't refetch on every toast.
  const showToast = useCallback((msg, type = 'success') => { setToast({ msg, type }); setTimeout(() => setToast(null), 3000) }, [])

  const load = useCallback(async () => {
    try { setD(unwrap(await companyPortalApi.getRequest(id))) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [id])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div>
  if (!d) return <div style={{ color: '#f87171', padding: 24 }}>Request not found.</div>

  const o = d.overview

  return (
    <div>
      {toast && <div style={{ position: 'fixed', top: 20, right: 20, zIndex: 9999, padding: '12px 20px', borderRadius: 14, fontSize: 13, fontWeight: 700, color: '#fff', background: toast.type === 'success' ? 'linear-gradient(135deg,#10b981,#059669)' : 'linear-gradient(135deg,#f87171,#ef4444)' }}>{toast.msg}</div>}

      <button onClick={() => navigate('/company-portal/hiring-requests')} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, marginBottom: 10 }}><ArrowLeft size={14} /> Back to Requests</button>

      <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: 16, flexWrap: 'wrap', gap: 10 }}>
        <div>
          <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
            <span style={{ color: '#a78bfa', fontWeight: 800, fontSize: 13 }}>HR-{o.id}</span>
            <h1 style={{ color: 'var(--text-h)', fontSize: 21, fontWeight: 800, margin: 0 }}>{o.job_title}</h1>
            <span style={{ fontSize: 11, fontWeight: 700, padding: '3px 10px', borderRadius: 20, color: '#fff', background: STATUS[o.status] || '#64748b' }}>{label(o.status)}</span>
          </div>
          <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 4 }}>{o.department || '—'}{o.business_unit ? ` · ${o.business_unit}` : ''} · {o.number_of_positions} position(s) · Recruiter: {o.assigned_recruiter?.name || 'Unassigned'}</p>
        </div>
      </div>

      {/* Funnel counts */}
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(110px,1fr))', gap: 8, marginBottom: 18 }}>
        {[['Applications', d.counts?.applications], ['Shortlisted', d.counts?.shortlisted], ['Interviews', d.counts?.interviews], ['Selected', d.counts?.selected], ['Delivered', d.counts?.delivered]].map(([l, v]) => (
          <div key={l} className="kpi-3d" style={{ padding: '12px 8px', textAlign: 'center' }}>
            <div style={{ fontSize: 20, fontWeight: 900, color: '#a78bfa' }}>{v ?? 0}</div>
            <div style={{ fontSize: 9.5, color: 'var(--text-muted)', fontWeight: 700, marginTop: 2, textTransform: 'uppercase' }}>{l}</div>
          </div>
        ))}
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 4, marginBottom: 16, flexWrap: 'wrap', overflowX: 'auto' }}>
        {TABS.map(([key, lab, Icon]) => (
          <button key={key} onClick={() => setTab(key)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '7px 12px', borderRadius: 9, fontSize: 12.5, fontWeight: 700, cursor: 'pointer', whiteSpace: 'nowrap',
            border: `1px solid ${tab === key ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`, background: tab === key ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', color: tab === key ? '#a78bfa' : 'var(--text-muted)' }}>
            <Icon size={13} /> {lab}
          </button>
        ))}
      </div>

      {tab === 'overview' && <Overview o={o} />}
      {tab === 'jd' && <JD o={o} docs={(d.documents || []).filter(x => x.type === 'jd')} />}
      {tab === 'candidates' && <CompanyCandidates requestId={id} showToast={showToast} />}
      {tab === 'interviews' && <CompanyInterviews requestId={id} showToast={showToast} />}
      {tab === 'offers' && <CompanyOffers requestId={id} showToast={showToast} />}
      {(tab === 'timeline' || tab === 'activity') && <Timeline items={d.timeline || []} />}
      {tab === 'documents' && <Documents requestId={id} docs={d.documents || []} onChange={load} showToast={showToast} />}
      {tab === 'messages' && <Messages requestId={id} showToast={showToast} />}
    </div>
  )
}

function Row({ k, v }) {
  return <div style={{ display: 'flex', gap: 10, padding: '7px 0', borderBottom: '1px solid var(--border)' }}>
    <span style={{ fontSize: 12, color: 'var(--text-muted)', width: 150, flexShrink: 0 }}>{k}</span>
    <span style={{ fontSize: 12.5, color: 'var(--text-h)', fontWeight: 600 }}>{v || '—'}</span>
  </div>
}
function Overview({ o }) {
  return (
    <div className="card-3d" style={{ padding: 20 }}>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(280px,1fr))', gap: '0 32px' }}>
        <div>
          <Row k="Position" v={o.job_title} /><Row k="Department" v={o.department} /><Row k="Business Unit" v={o.business_unit} />
          <Row k="Vacancies" v={o.number_of_positions} /><Row k="Employment Type" v={o.employment_type} /><Row k="Work Mode" v={o.work_mode} />
          <Row k="Priority" v={o.priority} />
        </div>
        <div>
          <Row k="Experience" v={o.experience_required} /><Row k="Location" v={o.location} /><Row k="Salary" v={money(o.salary_min, o.salary_max)} />
          <Row k="Target Joining" v={fmt(o.target_joining_date)} /><Row k="Education" v={o.education} />
          <Row k="Skills" v={Array.isArray(o.required_skills) ? o.required_skills.join(', ') : o.required_skills} />
          <Row k="Created" v={fmt(o.created_at)} />
        </div>
      </div>
    </div>
  )
}
function JD({ o, docs }) {
  return (
    <div className="card-3d" style={{ padding: 20 }}>
      <p style={{ fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', marginBottom: 8 }}>Job Description</p>
      <p style={{ fontSize: 13, color: 'var(--text-h)', whiteSpace: 'pre-wrap', lineHeight: 1.6 }}>{o.job_description || 'No description provided.'}</p>
      {docs.length > 0 && <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 14 }}>{docs.length} JD file(s) attached — see the Documents tab.</p>}
    </div>
  )
}
function Timeline({ items }) {
  if (!items.length) return <div className="card-3d" style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>No activity yet.</div>
  return (
    <div className="card-3d" style={{ padding: 20 }}>
      {items.map((t, i) => (
        <div key={i} style={{ display: 'flex', gap: 12, paddingBottom: 14 }}>
          <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}>
            <div style={{ width: 9, height: 9, borderRadius: '50%', background: t.by === 'Client' ? '#7C3AED' : '#10b981', marginTop: 4 }} />
            {i < items.length - 1 && <div style={{ width: 2, flex: 1, background: 'var(--border)', marginTop: 4 }} />}
          </div>
          <div>
            <p style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)' }}>{t.action}</p>
            {t.note && <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{t.note}</p>}
            <p style={{ fontSize: 10.5, color: 'var(--text-muted)', marginTop: 2 }}>{t.at ? new Date(t.at).toLocaleString() : ''}</p>
          </div>
        </div>
      ))}
    </div>
  )
}
function Documents({ requestId, docs, onChange, showToast }) {
  const fileRef = useRef()
  const [type, setType] = useState('jd')
  const [busy, setBusy] = useState(false)

  const upload = async (e) => {
    const file = e.target.files?.[0]; if (!file) return
    setBusy(true)
    try { await companyPortalApi.uploadDocument(requestId, type, file); showToast('Document uploaded'); onChange() }
    catch (err) { showToast(err.response?.data?.message || 'Upload failed', 'error') }
    finally { setBusy(false); if (fileRef.current) fileRef.current.value = '' }
  }
  const download = async (doc) => {
    try {
      const blob = await companyPortalApi.documentBlob(requestId, doc.id)
      const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = doc.original_name; a.click(); URL.revokeObjectURL(url)
    } catch { showToast('Download failed', 'error') }
  }
  const remove = async (doc) => {
    if (!window.confirm(`Remove "${doc.original_name}"?`)) return
    try { await companyPortalApi.deleteDocument(requestId, doc.id); showToast('Removed'); onChange() }
    catch (err) { showToast(err.response?.data?.message || 'Failed', 'error') }
  }

  return (
    <div>
      <div className="card-3d" style={{ padding: 14, marginBottom: 14, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <select value={type} onChange={e => setType(e.target.value)} style={{ padding: '8px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, cursor: 'pointer', textTransform: 'uppercase' }}>
          {DOC_TYPES.map(t => <option key={t} value={t}>{t.toUpperCase()}</option>)}
        </select>
        <input ref={fileRef} type="file" onChange={upload} style={{ display: 'none' }} accept=".pdf,.doc,.docx,.jpg,.jpeg,.png,.xls,.xlsx" />
        <button onClick={() => fileRef.current?.click()} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 8, border: 'none', cursor: 'pointer', fontSize: 12.5, fontWeight: 700, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: busy ? 0.6 : 1 }}><Upload size={13} /> {busy ? 'Uploading…' : 'Upload'}</button>
        <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>PDF/DOC/XLS/Image · max 10MB</span>
      </div>
      {docs.length === 0 ? <div className="card-3d" style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>No documents yet.</div> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
          {docs.map(doc => (
            <div key={doc.id} className="card-3d" style={{ padding: '12px 16px', display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 10 }}>
              <div>
                <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 6, background: 'rgba(124,58,237,0.12)', color: '#a78bfa', marginRight: 8 }}>{(doc.type || '').toUpperCase()}</span>
                <b style={{ color: 'var(--text-h)', fontSize: 13 }}>{doc.original_name}</b>
                <span style={{ fontSize: 11, color: 'var(--text-muted)', marginLeft: 8 }}>{doc.size_kb}kb · {doc.uploader?.name || doc.uploader_kind}</span>
              </div>
              <div style={{ display: 'flex', gap: 6 }}>
                <button onClick={() => download(doc)} style={miniBtn}><Download size={12} /></button>
                <button onClick={() => remove(doc)} style={{ ...miniBtn, color: '#f87171' }}><Trash2 size={12} /></button>
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  )
}
function Messages({ requestId, showToast }) {
  const [msgs, setMsgs] = useState([])
  const [body, setBody] = useState('')
  const [busy, setBusy] = useState(false)
  const load = useCallback(() => { companyPortalApi.messages(requestId).then(r => setMsgs(unwrap(r) || [])).catch(() => {}) }, [requestId])
  useEffect(() => { load() }, [load])

  const send = async () => {
    if (!body.trim()) return
    setBusy(true)
    try { await companyPortalApi.postMessage(requestId, body.trim()); setBody(''); load() }
    catch (e) { showToast(e.response?.data?.message || 'Failed to send', 'error') }
    finally { setBusy(false) }
  }
  return (
    <div className="card-3d" style={{ padding: 16 }}>
      <div style={{ maxHeight: 380, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 12 }}>
        {msgs.length === 0 ? <p style={{ color: 'var(--text-muted)', fontSize: 13, textAlign: 'center', padding: 20 }}>No messages yet. Start the conversation with your recruiter.</p> : msgs.map(m => (
          <div key={m.id} style={{ alignSelf: m.sender_kind === 'company' ? 'flex-end' : 'flex-start', maxWidth: '75%' }}>
            <div style={{ padding: '9px 13px', borderRadius: 12, fontSize: 13, color: m.sender_kind === 'company' ? '#fff' : 'var(--text-h)', background: m.sender_kind === 'company' ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)' }}>{m.body}</div>
            <p style={{ fontSize: 10, color: 'var(--text-muted)', marginTop: 3, textAlign: m.sender_kind === 'company' ? 'right' : 'left' }}>{m.sender?.name || m.sender_kind} · {m.created_at ? new Date(m.created_at).toLocaleString() : ''}</p>
          </div>
        ))}
      </div>
      <div style={{ display: 'flex', gap: 8 }}>
        <input value={body} onChange={e => setBody(e.target.value)} onKeyDown={e => e.key === 'Enter' && send()} placeholder="Type a message…" style={{ flex: 1, padding: '10px 14px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 10, color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
        <button onClick={send} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '10px 16px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', opacity: busy ? 0.6 : 1 }}><Send size={14} /> Send</button>
      </div>
    </div>
  )
}

const miniBtn = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 10px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 700 }

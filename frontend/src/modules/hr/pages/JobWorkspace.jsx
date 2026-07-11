import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, Briefcase, Users, CalendarDays, FileText, Activity, TrendingUp,
  ShieldCheck, User, Building2, FolderKanban, MapPin, ChevronRight, RefreshCw,
  Globe, ExternalLink, Copy, Check, Linkedin, Share2, UploadCloud,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { useAuth } from '@/context/AuthContext'
import AuditTimeline from '@/components/ui/AuditTimeline'
import {
  jobStatusColor, jobStatusLabel, PRIORITY_COLORS, STAGE_COLORS, DECISION_COLORS,
  INTERVIEW_STATUS_COLORS, INTERVIEW_RESULT_COLORS, canManageHrQueue,
} from '../constants'

const fmtDate = (d) => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'
const fmtDateTime = (d) => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'
const fmtSalary = (f, t) => (!f && !t) ? '—' : `₹${f ? (f / 100000).toFixed(1) : '0'}–${t ? (t / 100000).toFixed(1) : '0'}L`

const TABS = [
  { key: 'overview',   label: 'Overview',         icon: Briefcase },
  { key: 'jd',         label: 'Job Description',   icon: FileText },
  { key: 'progress',   label: 'Hiring Progress',   icon: TrendingUp },
  { key: 'candidates', label: 'Candidates',        icon: Users },
  { key: 'interviews', label: 'Interviews',        icon: CalendarDays },
  { key: 'timeline',   label: 'Activity Timeline', icon: Activity },
]

export default function JobWorkspace() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const manageHr = canManageHrQueue(user)
  const [job, setJob] = useState(null)
  const [loading, setLoading] = useState(true)
  const [tab, setTab] = useState('overview')

  const [channels, setChannels] = useState([])
  const load = useCallback(async () => {
    setLoading(true)
    try {
      const [j, ch] = await Promise.all([hrApi.jobs.get(id), hrApi.jobs.channels().catch(() => [])])
      setJob(j); setChannels(Array.isArray(ch) ? ch : [])
    } catch (e) { console.error(e); setJob(null) }
    finally { setLoading(false) }
  }, [id])
  useEffect(() => { load() }, [load])

  const publishSelected = async (keys) => {
    try {
      const r = await hrApi.jobs.publishChannels(id, keys)
      await load()
      const failed = (r?.results || []).filter(x => x.status === 'failed')
      if (failed.length) alert(`${r.published} channel(s) published. Not published: ` + failed.map(x => x.message || x.channel).join('; '))
    } catch (e) { alert(e?.response?.data?.message || 'Publish failed') }
  }
  const unpublishChannel = async (key) => {
    try { await hrApi.jobs.unpublishFrom(id, key); load() } catch (e) { alert(e?.response?.data?.message || 'Action failed') }
  }

  if (loading) return <div style={{ padding: 40, color: 'var(--text-muted)' }}>Loading workspace…</div>
  if (!job) return (
    <div style={{ padding: 40, color: 'var(--text-muted)' }}>
      Job not found. <button onClick={() => navigate('/app/hr/jobs')} style={{ color: '#a78bfa', background: 'none', border: 'none', cursor: 'pointer' }}>Back to Job Postings</button>
    </div>
  )

  const mr = job.manpower_request || {}
  const p = job.progress || {}
  const sc = jobStatusColor(job.status)
  const fullyApproved = mr.l1_approver?.name && mr.l2_approver?.name
  const candidates = job.candidates || []
  const interviews = candidates.flatMap(c => (c.interview_rounds || []).map(iv => ({ ...iv, _candidate: c.name })))

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      {/* Header */}
      <button onClick={() => navigate('/app/hr/jobs')} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, background: 'none', border: 'none', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13, marginBottom: 14 }}>
        <ArrowLeft size={15} /> Job Postings
      </button>

      <div className="card-3d" style={{ padding: 20, marginBottom: 16 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', gap: 12, flexWrap: 'wrap' }}>
          <div>
            <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
              <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>{job.title}</h1>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: sc.bg, color: sc.color, border: `1px solid ${sc.color}40` }}>{jobStatusLabel(job.status)}</span>
              {mr.priority && <span style={{ padding: '2px 9px', borderRadius: 8, fontSize: 11, fontWeight: 700, background: `${PRIORITY_COLORS[mr.priority]}20`, color: PRIORITY_COLORS[mr.priority] }}>{mr.priority}</span>}
              {fullyApproved && <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '2px 9px', borderRadius: 8, fontSize: 11, fontWeight: 700, background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}><ShieldCheck size={12} /> Approved</span>}
            </div>
            <div style={{ display: 'flex', gap: 16, marginTop: 8, flexWrap: 'wrap', color: 'var(--text-muted)', fontSize: 13 }}>
              <span>{job.department}</span>
              <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4 }}><MapPin size={13} /> {job.location}</span>
              <span>{job.job_type}</span>
              <span>{job.number_of_openings} opening{job.number_of_openings > 1 ? 's' : ''}</span>
            </div>
          </div>
          <button onClick={load} title="Refresh" style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 12px', borderRadius: 9, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12 }}><RefreshCw size={13} /> Refresh</button>
        </div>

        {/* mini stat strip */}
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(90px,1fr))', gap: 8, marginTop: 16 }}>
          {[['Hiring', `${p.hiring_pct ?? 0}%`], ['Filled', `${p.filled ?? 0}/${p.required ?? 0}`], ['Applications', p.applications], ['Shortlisted', p.shortlisted], ['Interviews', p.interviews], ['Offers', p.offers], ['Joined', p.joined]].map(([l, v]) => (
            <div key={l} style={{ textAlign: 'center', padding: '8px 4px', background: 'var(--bg-input)', borderRadius: 8 }}>
              <div style={{ fontSize: 16, fontWeight: 900, color: 'var(--text-h)' }}>{v ?? 0}</div>
              <div style={{ fontSize: 9.5, color: 'var(--text-muted)', fontWeight: 600 }}>{l}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 6, marginBottom: 18, flexWrap: 'wrap' }}>
        {TABS.map(t => {
          const active = tab === t.key
          const badge = t.key === 'candidates' ? candidates.length : t.key === 'interviews' ? interviews.length : null
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 9, fontSize: 13, fontWeight: 600, cursor: 'pointer',
                border: `1px solid ${active ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`, background: active ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)', color: active ? '#a78bfa' : 'var(--text-muted)' }}>
              <t.icon size={14} /> {t.label}
              {badge !== null && badge > 0 && <span style={{ minWidth: 18, height: 18, padding: '0 5px', borderRadius: 9, background: active ? '#7C3AED' : 'var(--bg-input)', color: active ? '#fff' : 'var(--text-muted)', fontSize: 10, fontWeight: 800, display: 'inline-flex', alignItems: 'center', justifyContent: 'center' }}>{badge}</span>}
            </button>
          )
        })}
      </div>

      {/* Tab content */}
      <div className="card-3d" style={{ padding: 22 }}>
        {tab === 'overview'   && <Overview job={job} mr={mr} fullyApproved={fullyApproved} manageHr={manageHr} channels={channels} onPublish={publishSelected} onUnpublish={unpublishChannel} />}
        {tab === 'jd'         && <JobDescription job={job} />}
        {tab === 'progress'   && <HiringProgress p={p} />}
        {tab === 'candidates' && <CandidatesTab candidates={candidates} navigate={navigate} />}
        {tab === 'interviews' && <InterviewsTab interviews={interviews} />}
        {tab === 'timeline'   && <AuditTimeline entries={job.audit_logs} />}
      </div>
    </div>
  )
}

const Label = ({ children }) => <label style={{ display: 'block', fontSize: 11, fontWeight: 700, color: 'var(--text-muted)', marginBottom: 8, textTransform: 'uppercase', letterSpacing: '0.05em' }}>{children}</label>
const KV = ({ rows }) => (
  <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: '8px 20px' }}>
    {rows.filter(([, v]) => v !== null && v !== undefined && v !== '').map(([k, v]) => (
      <div key={k} style={{ display: 'flex', justifyContent: 'space-between', gap: 12, borderBottom: '1px solid var(--border)', paddingBottom: 6 }}>
        <span style={{ color: 'var(--text-muted)', fontSize: 12.5 }}>{k}</span>
        <span style={{ color: 'var(--text-h)', fontSize: 12.5, fontWeight: 600, textAlign: 'right' }}>{v}</span>
      </div>
    ))}
  </div>
)

// ── Overview ─────────────────────────────────────────────────────────────────
function Overview({ job, mr, fullyApproved, manageHr, channels, onPublish, onUnpublish }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 22 }}>
      <PublishChannels job={job} channels={channels} manageHr={manageHr} onPublish={onPublish} onUnpublish={onUnpublish} />
      {/* Request source */}
      <div>
        <Label>Request Source</Label>
        {mr.id ? (
          <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap', marginBottom: 12 }}>
            {[['User', 'Requested By', mr.requester?.name], ['Building2', 'Business Unit', mr.business_unit], ['Briefcase', 'Department', mr.department], ['FolderKanban', 'Project', mr.project]].filter(([, , v]) => v).map(([ic, k, v]) => {
              const I = { User, Building2, Briefcase, FolderKanban }[ic]
              return (
                <div key={k} style={{ flex: '1 1 160px', padding: '10px 12px', background: 'var(--bg-input)', borderRadius: 9, border: '1px solid var(--border)' }}>
                  <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 3 }}><I size={12} /> {k}</div>
                  <div style={{ color: 'var(--text-h)', fontSize: 13, fontWeight: 600 }}>{v}</div>
                </div>
              )
            })}
          </div>
        ) : <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: '0 0 12px' }}>Created directly (not linked to a manpower request).</p>}

        {/* Approval status */}
        {mr.id && (
          <div style={{ display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' }}>
            <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Approval:</span>
            {fullyApproved
              ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 10px', borderRadius: 8, fontSize: 12, fontWeight: 700, background: 'rgba(16,185,129,0.12)', color: '#10b981', border: '1px solid rgba(16,185,129,0.3)' }}><ShieldCheck size={13} /> Approved (L1 &amp; L2)</span>
              : <>
                  <ApprovalPill level="L1 · Dept Head" name={mr.l1_approver?.name} />
                  <ApprovalPill level="L2 · Management" name={mr.l2_approver?.name} />
                </>}
            {mr.l1_approver?.name && <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>L1: {mr.l1_approver.name}{mr.l2_approver?.name ? ` · L2: ${mr.l2_approver.name}` : ''}</span>}
          </div>
        )}
      </div>

      {/* Key dates */}
      <div>
        <Label>Important Dates</Label>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          {[['Created', job.created_at], ['Published', job.published_at], ['Closing', job.closing_date]].map(([k, v]) => (
            <div key={k} style={{ flex: '1 1 140px', padding: '10px 12px', background: 'var(--bg-input)', borderRadius: 9, border: '1px solid var(--border)' }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 5, fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 700, textTransform: 'uppercase', marginBottom: 3 }}><CalendarDays size={12} /> {k}</div>
              <div style={{ color: 'var(--text-h)', fontSize: 13, fontWeight: 600 }}>{fmtDate(v)}</div>
            </div>
          ))}
        </div>
      </div>

      {/* Job facts */}
      <div>
        <Label>Job Details</Label>
        <KV rows={[
          ['Employment Type', job.job_type], ['Posting Type', job.posting_type],
          ['Openings', job.number_of_openings], ['Location', job.location],
          ['Salary Range', fmtSalary(job.salary_from, job.salary_to)],
          ['Sources', (job.sources || []).join(', ')],
        ]} />
      </div>
    </div>
  )
}
const ApprovalPill = ({ level, name }) => {
  const ok = !!name
  return <span style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '3px 9px', borderRadius: 8, fontSize: 11.5, fontWeight: 700, background: ok ? 'rgba(16,185,129,0.12)' : 'var(--bg-input)', color: ok ? '#10b981' : 'var(--text-muted)', border: `1px solid ${ok ? 'rgba(16,185,129,0.3)' : 'var(--border)'}` }}><ShieldCheck size={12} /> {level}{ok ? ' ✓' : ' —'}</span>
}

// ── Publish Channels ─────────────────────────────────────────────────────────
const CHANNEL_ICON = { careers: Globe, linkedin: Linkedin, naukri: Share2, indeed: Share2, trulytalents: Share2 }
const CHANNEL_COLOR = { careers: '#0ea5e9', linkedin: '#0a66c2', naukri: '#f97316', indeed: '#2557a7', trulytalents: '#7C3AED' }
const PUB_STATUS = {
  published:     { label: 'Published',     color: '#10b981' },
  pending:       { label: 'Pending',       color: '#f59e0b' },
  failed:        { label: 'Failed',        color: '#ef4444' },
  not_published: { label: 'Not Published', color: '#64748b' },
  not_connected: { label: 'Not Connected', color: '#94a3b8' },
}
const fmtSync = (d) => d ? new Date(d).toLocaleString('en-IN', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'

function PublishChannels({ job, channels, manageHr, onPublish, onUnpublish }) {
  const [selected, setSelected] = useState(new Set())
  const [copied, setCopied] = useState(null)
  const live = ['Published', 'Hiring', 'Partially_Filled'].includes(job.status)
  const pubs = job.publications || []

  // Merge the channel catalog with this job's publication records.
  const catalog = (channels?.length ? channels : [
    { key: 'careers', label: 'Career Portal', integrated: true },
    { key: 'linkedin', label: 'LinkedIn', integrated: false }, { key: 'naukri', label: 'Naukri', integrated: false },
    { key: 'indeed', label: 'Indeed', integrated: false }, { key: 'trulytalents', label: 'TrulyTalents', integrated: false },
  ])
  const rows = catalog.map(c => {
    const pub = pubs.find(p => p.channel === c.key)
    let status = 'not_published'
    if (!c.integrated) status = 'not_connected'
    else if (pub?.status === 'published') status = 'published'
    else if (pub?.status === 'failed') status = 'failed'
    else if (pub?.status === 'pending') status = 'pending'
    return { ...c, pub, status }
  })

  const selectable = (r) => manageHr && r.integrated && r.status !== 'published'
  const toggle = (key) => setSelected(s => { const n = new Set(s); n.has(key) ? n.delete(key) : n.add(key); return n })
  const copy = (url) => { navigator.clipboard?.writeText(url); setCopied(url); setTimeout(() => setCopied(null), 1500) }
  const publish = async () => { await onPublish([...selected]); setSelected(new Set()) }

  return (
    <div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 8, flexWrap: 'wrap', gap: 8 }}>
        <Label>Publish Channels</Label>
        {manageHr && (
          <button onClick={publish} disabled={selected.size === 0 || !live}
            title={!live ? 'Make the job live first' : selected.size === 0 ? 'Select one or more channels' : ''}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '6px 14px', borderRadius: 9, border: 'none', fontSize: 12.5, fontWeight: 700, cursor: (selected.size && live) ? 'pointer' : 'not-allowed', background: (selected.size && live) ? 'linear-gradient(135deg,#7C3AED,#6d28d9)' : 'var(--bg-input)', color: (selected.size && live) ? '#fff' : 'var(--text-muted)', opacity: (selected.size && live) ? 1 : 0.7 }}>
            <UploadCloud size={14} /> Publish to Selected{selected.size ? ` (${selected.size})` : ''}
          </button>
        )}
      </div>

      <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
        {rows.map(r => {
          const Icon = CHANNEL_ICON[r.key] || Share2
          const color = CHANNEL_COLOR[r.key] || '#7C3AED'
          const st = PUB_STATUS[r.status]
          const canSelect = selectable(r)
          return (
            <div key={r.key} style={{ padding: '12px 14px', background: 'var(--bg-input)', border: `1px solid ${selected.has(r.key) ? color : 'var(--border)'}`, borderRadius: 10, opacity: r.integrated ? 1 : 0.75 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                {manageHr && <input type="checkbox" disabled={!canSelect} checked={selected.has(r.key)} onChange={() => toggle(r.key)} style={{ cursor: canSelect ? 'pointer' : 'not-allowed', width: 15, height: 15 }} />}
                <span style={{ width: 26, height: 26, borderRadius: 7, display: 'flex', alignItems: 'center', justifyContent: 'center', background: `${color}18` }}><Icon size={14} style={{ color }} /></span>
                <span style={{ fontWeight: 700, fontSize: 13.5, color: 'var(--text-h)' }}>{r.label}</span>
                <span style={{ padding: '2px 9px', borderRadius: 8, fontSize: 10.5, fontWeight: 700, background: `${st.color}1a`, color: st.color, border: `1px solid ${st.color}40` }}>{st.label}</span>
                {!r.integrated && <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>Coming Soon</span>}

                {r.status === 'published' && r.key !== 'careers' && r.pub?.external_url && (
                  <a href={r.pub.external_url} target="_blank" rel="noreferrer" style={{ display: 'inline-flex', alignItems: 'center', gap: 4, fontSize: 12, color, textDecoration: 'none' }}>View <ExternalLink size={12} /></a>
                )}
                {manageHr && r.status === 'published' && (
                  <button onClick={() => onUnpublish(r.key)} style={{ marginLeft: 'auto', background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.3)', borderRadius: 8, padding: '3px 10px', cursor: 'pointer', color: '#ef4444', fontSize: 11.5, fontWeight: 600 }}>Remove</button>
                )}
              </div>

              {/* Reserved per-channel fields */}
              {(r.status === 'published' || r.status === 'failed') && (
                <div style={{ display: 'flex', gap: 18, flexWrap: 'wrap', marginTop: 8, paddingLeft: manageHr ? 61 : 36, fontSize: 11, color: 'var(--text-muted)' }}>
                  <span>External ID: <strong style={{ color: 'var(--text-h)' }}>{r.pub?.external_ref || '—'}</strong></span>
                  <span>Published: <strong style={{ color: 'var(--text-h)' }}>{fmtSync(r.pub?.published_at)}</strong></span>
                  <span>Last Sync: <strong style={{ color: 'var(--text-h)' }}>{fmtSync(r.pub?.last_synced_at)}</strong></span>
                  {r.pub?.error_message && <span style={{ color: '#ef4444' }}>⚠ {r.pub.error_message}</span>}
                </div>
              )}

              {/* Public Career Portal URLs (only when Career Portal is Published) */}
              {r.key === 'careers' && r.status === 'published' && (() => {
                const jobUrl = r.pub?.external_url || r.pub?.meta?.job_url
                const homeUrl = r.pub?.meta?.portal_home_url || (jobUrl ? jobUrl.replace(/\/jobs\/\d+$/, '') : null)
                return <PortalUrls home={homeUrl} job={jobUrl} color={color} indent={manageHr ? 61 : 36} />
              })()}
            </div>
          )
        })}
      </div>
    </div>
  )
}

// ── Public Career Portal URLs (Open / Copy / Share) ─────────────────────────
function PortalUrls({ home, job, color, indent }) {
  const [copied, setCopied] = useState(null)
  const doCopy = (url) => { navigator.clipboard?.writeText(url); setCopied(url); setTimeout(() => setCopied(null), 1500) }
  const doShare = async (url, title) => {
    if (navigator.share) { try { await navigator.share({ title, url }) } catch (e) { /* dismissed */ } }
    else { doCopy(url) } // graceful fallback until native share / integrations land
  }
  const Row = ({ label, url }) => !url ? null : (
    <div style={{ marginTop: 8 }}>
      <div style={{ fontSize: 10, fontWeight: 700, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', marginBottom: 4 }}>{label}</div>
      <div style={{ display: 'flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
        <code style={{ fontSize: 12, color: 'var(--text-h)', background: 'var(--bg-card)', border: '1px solid var(--border)', borderRadius: 6, padding: '5px 9px', maxWidth: 380, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{url}</code>
        <UrlBtn icon={ExternalLink} label="Open" color={color} onClick={() => window.open(url, '_blank', 'noopener')} />
        <UrlBtn icon={copied === url ? Check : Copy} label={copied === url ? 'Copied' : 'Copy'} onClick={() => doCopy(url)} />
        <UrlBtn icon={Share2} label="Share" onClick={() => doShare(url, label)} />
      </div>
    </div>
  )
  return (
    <div style={{ marginTop: 10, paddingLeft: indent, borderTop: '1px dashed var(--border)', paddingTop: 8 }}>
      <Row label="Portal Home" url={home} />
      <Row label="Public Job URL" url={job} />
    </div>
  )
}
const UrlBtn = ({ icon: Icon, label, onClick, color }) => (
  <button onClick={onClick} title={label} style={{ display: 'inline-flex', alignItems: 'center', gap: 4, padding: '4px 10px', borderRadius: 7, border: `1px solid ${color ? color + '40' : 'var(--border)'}`, background: color ? `${color}12` : 'var(--bg-card)', color: color || 'var(--text-muted)', cursor: 'pointer', fontSize: 11.5, fontWeight: 600 }}><Icon size={12} /> {label}</button>
)

// ── Job Description ──────────────────────────────────────────────────────────
function JobDescription({ job }) {
  if (!job.description && !job.requirements) return <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No job description added yet.</p>
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      {job.description && <div><Label>Description</Label><p style={{ color: 'var(--text-h)', fontSize: 13.5, lineHeight: 1.6, margin: 0, whiteSpace: 'pre-wrap' }}>{job.description}</p></div>}
      {job.requirements && <div><Label>Requirements</Label><p style={{ color: 'var(--text-h)', fontSize: 13.5, lineHeight: 1.6, margin: 0, whiteSpace: 'pre-wrap' }}>{job.requirements}</p></div>}
    </div>
  )
}

// ── Hiring Progress ──────────────────────────────────────────────────────────
function HiringProgress({ p }) {
  const stages = [
    ['Applications', p.applications, '#3b82f6'], ['Shortlisted', p.shortlisted, '#a855f7'],
    ['Interviews', p.interviews, '#6366f1'], ['Offers', p.offers, '#10b981'],
    ['Offers Accepted', p.offers_accepted, '#14b8a6'], ['Joined', p.joined, '#059669'],
  ]
  const max = Math.max(p.applications || 0, 1)
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 20 }}>
      <div>
        <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 13, color: 'var(--text-muted)', marginBottom: 6 }}>
          <span>Positions Filled · <strong style={{ color: 'var(--text-h)' }}>{p.hiring_pct ?? 0}%</strong></span>
          <span>Filled {p.filled ?? 0}/{p.required ?? 0} · {p.remaining ?? 0} remaining</span>
        </div>
        <div style={{ height: 10, borderRadius: 6, background: 'var(--bg-input)', overflow: 'hidden' }}>
          <div style={{ width: `${p.hiring_pct ?? 0}%`, height: '100%', borderRadius: 6, background: 'linear-gradient(90deg,#10b981,#22c55e)' }} />
        </div>
      </div>
      <div>
        <Label>Recruitment Funnel</Label>
        <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
          {stages.map(([l, v, c]) => {
            const pct = Math.round(((v || 0) / max) * 100)
            return (
              <div key={l} style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                <span style={{ width: 120, fontSize: 12, fontWeight: 600, color: 'var(--text-muted)', textAlign: 'right', flexShrink: 0 }}>{l}</span>
                <div style={{ flex: 1, height: 24, borderRadius: 7, background: 'var(--bg-input)', overflow: 'hidden', position: 'relative' }}>
                  <div style={{ width: `${Math.max(pct, v ? 6 : 0)}%`, height: '100%', borderRadius: 7, background: `linear-gradient(90deg,${c}cc,${c})`, display: 'flex', alignItems: 'center', paddingLeft: 8 }}>
                    <span style={{ fontSize: 11, fontWeight: 800, color: '#fff' }}>{v ?? 0}</span>
                  </div>
                </div>
                <span style={{ width: 34, fontSize: 11, fontWeight: 700, color: c, flexShrink: 0 }}>{pct}%</span>
              </div>
            )
          })}
        </div>
        <p style={{ fontSize: 11, color: 'var(--text-muted)', marginTop: 10 }}>Funnel is validated: Interviews ≤ Shortlisted, Offers ≤ Selected, Joined ≤ Offers Accepted.</p>
      </div>
    </div>
  )
}

// ── Candidates ───────────────────────────────────────────────────────────────
function CandidatesTab({ candidates, navigate }) {
  if (!candidates.length) return <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No candidates for this job yet.</p>
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {candidates.map(c => (
        <div key={c.id} onClick={() => navigate(`/app/hr/candidates/${c.id}`)}
          style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px', background: 'var(--bg-input)', borderRadius: 10, border: '1px solid var(--border)', cursor: 'pointer' }}>
          <span style={{ width: 34, height: 34, borderRadius: '50%', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)', color: '#fff', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, fontSize: 12, flexShrink: 0 }}>
            {(c.name || '?').split(' ').map(n => n[0]).join('').slice(0, 2).toUpperCase()}
          </span>
          <div style={{ flex: 1, minWidth: 0 }}>
            <div style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 13.5 }}>{c.name}</div>
            <div style={{ color: 'var(--text-muted)', fontSize: 11.5 }}>{c.source || '—'}{c.email ? ` · ${c.email}` : ''}</div>
          </div>
          {c.ai_score != null && <span style={{ fontSize: 11, fontWeight: 700, color: '#a78bfa', padding: '2px 8px', borderRadius: 8, background: 'rgba(124,58,237,0.1)' }}>AI {c.ai_score}</span>}
          {c.final_decision && c.final_decision !== 'Pending' && <Badge color={DECISION_COLORS[c.final_decision]}>{c.final_decision}</Badge>}
          <Badge color={STAGE_COLORS[c.stage] || '#6b7280'}>{c.stage}</Badge>
          <ChevronRight size={16} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
        </div>
      ))}
    </div>
  )
}

// ── Interviews ───────────────────────────────────────────────────────────────
function InterviewsTab({ interviews }) {
  if (!interviews.length) return <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No interviews scheduled for this job's candidates yet.</p>
  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
      {interviews.map(iv => (
        <div key={iv.id} style={{ display: 'flex', alignItems: 'center', gap: 12, padding: '10px 14px', background: 'var(--bg-input)', borderRadius: 10, border: '1px solid var(--border)', flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 160 }}>
            <div style={{ color: 'var(--text-h)', fontWeight: 700, fontSize: 13.5 }}>{iv._candidate}</div>
            <div style={{ color: 'var(--text-muted)', fontSize: 11.5 }}>
              {iv.round_name}
              <span style={{ opacity: 0.8 }}> · {iv.mode === 'offline' ? '📍 Offline' : '🎥 Online'}</span>
              {iv.interviewer_name ? ` · ${iv.interviewer_name}` : ''}
            </div>
          </div>
          <span style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{fmtDateTime(iv.scheduled_at)}</span>
          {iv.recommendation && <Badge color="#a78bfa">{iv.recommendation}</Badge>}
          {iv.result && iv.result !== 'Pending' && <Badge color={INTERVIEW_RESULT_COLORS[iv.result]}>{iv.result}</Badge>}
          <Badge color={INTERVIEW_STATUS_COLORS[iv.status] || '#6b7280'}>{iv.status}</Badge>
        </div>
      ))}
    </div>
  )
}

const Badge = ({ color, children }) => (
  <span style={{ padding: '2px 9px', borderRadius: 8, fontSize: 11, fontWeight: 700, background: `${color}1f`, color, border: `1px solid ${color}40`, flexShrink: 0 }}>{children}</span>
)

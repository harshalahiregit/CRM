import { useState, useEffect, useCallback } from 'react'
import {
  Search, LayoutGrid, List as ListIcon, X, Star, Download, Eye, Send,
  ChevronLeft, ChevronRight, Users, MapPin, Briefcase, Building2, Check, ThumbsDown, Clock, Layers,
} from 'lucide-react'
import { companyPortalApi } from '@/services/companyPortalApi'

const unwrap = r => r?.data ?? r
const SUB_STATUS = {
  'Pending Client Review': { c: '#f59e0b', bg: 'rgba(245,158,11,0.12)' },
  Shortlisted: { c: '#3b82f6', bg: 'rgba(59,130,246,0.12)' },
  Accepted: { c: '#10b981', bg: 'rgba(16,185,129,0.14)' },
  Rejected: { c: '#ef4444', bg: 'rgba(239,68,68,0.1)' },
  'Need More Profiles': { c: '#a78bfa', bg: 'rgba(124,58,237,0.14)' },
  Hold: { c: '#64748b', bg: 'rgba(100,116,139,0.14)' },
}
const REC_COLOR = { 'Strongly Recommended': '#10b981', Recommended: '#3b82f6', Consider: '#f59e0b', 'Not Recommended': '#ef4444' }
const money = v => v ? `₹${(v / 100000).toFixed(1)}L` : '—'
const initials = n => (n || '?').split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase()
const ACTIONS = [
  ['shortlist', 'Shortlist', '#3b82f6', Layers], ['accept', 'Accept', '#10b981', Check],
  ['reject', 'Reject', '#ef4444', ThumbsDown], ['more', 'Need More', '#a78bfa', Users], ['hold', 'Hold', '#64748b', Clock],
]

export default function CompanyCandidates({ requestId, showToast }) {
  const [page, setPage] = useState({ data: [], total: 0, current_page: 1, last_page: 1 })
  const [loading, setLoading] = useState(true)
  const [view, setView] = useState('card')
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('All')
  const [sort, setSort] = useState('submitted_at')
  const [pg, setPg] = useState(1)
  const [openSid, setOpenSid] = useState(null)

  const load = useCallback(async () => {
    setLoading(true)
    try { setPage(unwrap(await companyPortalApi.candidates(requestId, { search, status, sort, dir: 'desc', page: pg, per_page: 12 }))) }
    catch (e) { showToast(e.response?.data?.message || 'Failed to load', 'error') }
    finally { setLoading(false) }
  }, [requestId, search, status, sort, pg, showToast])
  useEffect(() => { load() }, [load])
  useEffect(() => { setPg(1) }, [search, status, sort])

  const rows = page.data || []

  return (
    <div>
      <div className="card-3d" style={{ padding: '12px 16px', marginBottom: 14, display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
        <div style={{ position: 'relative', flex: 1, minWidth: 160 }}>
          <Search size={14} style={{ position: 'absolute', left: 10, top: '50%', transform: 'translateY(-50%)', color: 'var(--text-muted)' }} />
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search candidate…" style={{ width: '100%', padding: '9px 12px 9px 32px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, outline: 'none', boxSizing: 'border-box' }} />
        </div>
        <select value={status} onChange={e => setStatus(e.target.value)} style={sel}>
          <option value="All">All Status</option>
          {Object.keys(SUB_STATUS).map(s => <option key={s} value={s}>{s}</option>)}
        </select>
        <select value={sort} onChange={e => setSort(e.target.value)} style={sel}>
          <option value="submitted_at">Newest</option>
          <option value="status">Status</option>
        </select>
        <div style={{ display: 'flex', gap: 4 }}>
          {[['card', LayoutGrid], ['list', ListIcon]].map(([v, Icon]) => (
            <button key={v} onClick={() => setView(v)} style={{ padding: 8, borderRadius: 8, cursor: 'pointer', border: '1px solid var(--border)', background: view === v ? 'rgba(124,58,237,0.15)' : 'var(--bg-input)', color: view === v ? '#a78bfa' : 'var(--text-muted)' }}><Icon size={16} /></button>
          ))}
        </div>
      </div>

      {loading ? <div style={{ color: 'var(--text-muted)', padding: 40, textAlign: 'center' }}>Loading…</div> : (
        rows.length === 0 ? (
          <div className="card-3d" style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}><Users size={26} style={{ opacity: 0.5 }} /><p style={{ marginTop: 8 }}>No candidates delivered yet.</p></div>
        ) : view === 'card' ? (
          <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill,minmax(300px,1fr))', gap: 12 }}>
            {rows.map(c => <CandidateCard key={c.submission_id} c={c} onOpen={() => setOpenSid(c.submission_id)} />)}
          </div>
        ) : (
          <div className="card-3d" style={{ padding: 0, overflowX: 'auto' }}>
            <table style={{ width: '100%', borderCollapse: 'collapse', fontSize: 12.5 }}>
              <thead><tr style={{ background: 'var(--bg-input)' }}>{['Name', 'Company', 'Exp', 'Location', 'AI', 'Recommendation', 'Stage', 'Status'].map(h => <th key={h} style={{ textAlign: 'left', padding: '10px 12px', fontWeight: 700, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{h}</th>)}</tr></thead>
              <tbody>
                {rows.map(c => {
                  const st = SUB_STATUS[c.status] || {}
                  return (
                    <tr key={c.submission_id} onClick={() => setOpenSid(c.submission_id)} style={{ borderTop: '1px solid var(--border)', cursor: 'pointer' }}>
                      <td style={{ padding: '10px 12px', color: 'var(--text-h)', fontWeight: 700 }}>{c.name}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{c.current_company || '—'}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{c.experience_years ? `${c.experience_years}y` : '—'}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{c.location || '—'}</td>
                      <td style={{ padding: '10px 12px', color: '#a78bfa', fontWeight: 800 }}>{c.ai_score ?? '—'}</td>
                      <td style={{ padding: '10px 12px', color: REC_COLOR[c.recommendation] || 'var(--text-muted)', fontWeight: 600 }}>{c.recommendation}</td>
                      <td style={{ padding: '10px 12px', color: 'var(--text-muted)' }}>{c.stage}</td>
                      <td style={{ padding: '10px 12px' }}><span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, background: st.bg, color: st.c }}>{c.status}</span></td>
                    </tr>
                  )
                })}
              </tbody>
            </table>
          </div>
        )
      )}

      {page.last_page > 1 && (
        <div style={{ display: 'flex', justifyContent: 'center', alignItems: 'center', gap: 12, marginTop: 16 }}>
          <button disabled={pg <= 1} onClick={() => setPg(p => p - 1)} style={{ ...mini, opacity: pg <= 1 ? 0.4 : 1 }}><ChevronLeft size={14} /></button>
          <span style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>Page {page.current_page} of {page.last_page} · {page.total}</span>
          <button disabled={pg >= page.last_page} onClick={() => setPg(p => p + 1)} style={{ ...mini, opacity: pg >= page.last_page ? 0.4 : 1 }}><ChevronRight size={14} /></button>
        </div>
      )}

      {openSid && <CandidateWorkspace requestId={requestId} sid={openSid} onClose={() => setOpenSid(null)} onChanged={load} showToast={showToast} />}
    </div>
  )
}

function CandidateCard({ c, onOpen }) {
  const st = SUB_STATUS[c.status] || {}
  return (
    <div className="card-3d" style={{ padding: 16, cursor: 'pointer' }} onClick={onOpen}>
      <div style={{ display: 'flex', gap: 12 }}>
        <div style={{ width: 44, height: 44, borderRadius: 12, flexShrink: 0, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, color: '#fff', fontSize: 15, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(c.name)}</div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ display: 'flex', justifyContent: 'space-between', gap: 8 }}>
            <p style={{ fontWeight: 800, color: 'var(--text-h)', fontSize: 14.5 }}>{c.name}</p>
            <span style={{ fontSize: 10, fontWeight: 700, padding: '2px 8px', borderRadius: 8, height: 'fit-content', background: st.bg, color: st.c, whiteSpace: 'nowrap' }}>{c.status === 'Pending Client Review' ? 'Pending' : c.status}</span>
          </div>
          <p style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 2 }}><Building2 size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {c.current_company || '—'}</p>
        </div>
      </div>
      <div style={{ display: 'flex', gap: 12, marginTop: 10, fontSize: 11.5, color: 'var(--text-muted)', flexWrap: 'wrap' }}>
        <span><Briefcase size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {c.experience_years ? `${c.experience_years}y` : '—'}</span>
        <span><MapPin size={11} style={{ display: 'inline', verticalAlign: -1 }} /> {c.location || '—'}</span>
        <span>{money(c.expected_ctc)}</span>
      </div>
      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginTop: 10 }}>
        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, fontSize: 12, fontWeight: 800, color: '#a78bfa' }}><Star size={13} fill="#a78bfa" /> AI {c.ai_score ?? '—'}</span>
        <span style={{ fontSize: 11, fontWeight: 700, color: REC_COLOR[c.recommendation] || 'var(--text-muted)' }}>{c.recommendation}</span>
      </div>
    </div>
  )
}

function Bar({ label, value }) {
  return (
    <div style={{ marginBottom: 8 }}>
      <div style={{ display: 'flex', justifyContent: 'space-between', fontSize: 11.5, color: 'var(--text-muted)', marginBottom: 3 }}><span>{label}</span><b style={{ color: 'var(--text-h)' }}>{value != null ? `${value}%` : '—'}</b></div>
      <div style={{ height: 6, borderRadius: 4, background: 'var(--bg-input)', overflow: 'hidden' }}><div style={{ height: '100%', width: `${value || 0}%`, background: 'linear-gradient(90deg,#a78bfa,#7C3AED)' }} /></div>
    </div>
  )
}

function CandidateWorkspace({ requestId, sid, onClose, onChanged, showToast }) {
  const [d, setD] = useState(null)
  const [tab, setTab] = useState('summary')
  const [busy, setBusy] = useState(false)
  const [comments, setComments] = useState([])
  const [cbody, setCbody] = useState('')

  const load = useCallback(async () => {
    try { setD(unwrap(await companyPortalApi.candidate(requestId, sid))) } catch { showToast('Failed to load candidate', 'error') }
  }, [requestId, sid, showToast])
  const loadComments = useCallback(() => { companyPortalApi.candidateComments(requestId, sid).then(r => setComments(unwrap(r) || [])).catch(() => {}) }, [requestId, sid])
  useEffect(() => { load(); loadComments() }, [load, loadComments])

  const review = async (decision) => {
    let comment = ''
    if (decision === 'reject' || decision === 'more') { comment = window.prompt('Add a comment (optional):') ?? ''; }
    setBusy(true)
    try { await companyPortalApi.reviewCandidate(requestId, sid, decision, comment); showToast('Feedback recorded'); await load(); onChanged?.() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
    finally { setBusy(false) }
  }
  const resume = async (open) => {
    try {
      const blob = await companyPortalApi.candidateResumeBlob(requestId, sid)
      const url = URL.createObjectURL(blob)
      if (open) window.open(url, '_blank')
      else { const a = document.createElement('a'); a.href = url; a.download = `${d?.summary?.name || 'resume'}.pdf`; a.click() }
      setTimeout(() => URL.revokeObjectURL(url), 30000); load()
    } catch { showToast('Resume unavailable', 'error') }
  }
  const sendComment = async () => {
    if (!cbody.trim()) return
    try { await companyPortalApi.postCandidateComment(requestId, sid, cbody.trim()); setCbody(''); loadComments() }
    catch (e) { showToast(e.response?.data?.message || 'Failed', 'error') }
  }

  const TABS = ['summary', 'ai', 'journey', 'interviews', 'offer', 'timeline', 'comments']

  return (
    <div style={{ position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.72)', zIndex: 1100, display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20 }} onClick={e => e.target === e.currentTarget && onClose()}>
      <div className="card-3d" style={{ width: '100%', maxWidth: 780, maxHeight: '92vh', overflowY: 'auto', padding: 22 }}>
        {!d ? <div style={{ padding: 30, textAlign: 'center', color: 'var(--text-muted)' }}>Loading…</div> : (
          <>
            <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, marginBottom: 14 }}>
              <div style={{ display: 'flex', gap: 12 }}>
                <div style={{ width: 48, height: 48, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 800, color: '#fff', fontSize: 17, background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>{initials(d.summary?.name)}</div>
                <div>
                  <h2 style={{ margin: 0, fontSize: 18, fontWeight: 800, color: 'var(--text-h)' }}>{d.summary?.name}</h2>
                  <p style={{ fontSize: 12, color: 'var(--text-muted)', marginTop: 2 }}>{d.summary?.current_company || '—'} · {d.summary?.location || '—'} · Recruiter: {d.recruiter || '—'}</p>
                </div>
              </div>
              <button onClick={onClose} style={{ ...mini, padding: 6, height: 'fit-content' }}><X size={14} /></button>
            </div>

            {/* Review actions */}
            <div style={{ display: 'flex', gap: 6, flexWrap: 'wrap', marginBottom: 8 }}>
              <span style={{ fontSize: 11, fontWeight: 700, padding: '5px 10px', borderRadius: 8, background: (SUB_STATUS[d.submission?.status] || {}).bg, color: (SUB_STATUS[d.submission?.status] || {}).c, alignSelf: 'center' }}>{d.submission?.status}</span>
              {ACTIONS.map(([key, lab, color, Icon]) => (
                <button key={key} onClick={() => review(key)} disabled={busy} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, border: `1px solid ${color}44`, background: `${color}18`, color, cursor: 'pointer', fontSize: 12, fontWeight: 700 }}><Icon size={12} /> {lab}</button>
              ))}
            </div>
            {/* Resume actions */}
            <div style={{ display: 'flex', gap: 6, marginBottom: 14 }}>
              <button onClick={() => resume(true)} disabled={!d.resume_available} style={{ ...mini, opacity: d.resume_available ? 1 : 0.4 }}><Eye size={12} /> Preview</button>
              <button onClick={() => resume(false)} disabled={!d.resume_available} style={{ ...mini, opacity: d.resume_available ? 1 : 0.4 }}><Download size={12} /> Download</button>
            </div>

            {/* Tabs */}
            <div style={{ display: 'flex', gap: 4, marginBottom: 14, flexWrap: 'wrap', borderBottom: '1px solid var(--border)', paddingBottom: 8 }}>
              {TABS.map(t => <button key={t} onClick={() => setTab(t)} style={{ padding: '6px 11px', borderRadius: 8, fontSize: 12, fontWeight: 700, cursor: 'pointer', textTransform: 'capitalize', border: 'none', background: tab === t ? 'rgba(124,58,237,0.15)' : 'transparent', color: tab === t ? '#a78bfa' : 'var(--text-muted)' }}>{t}</button>)}
            </div>

            {tab === 'summary' && (
              <div style={{ fontSize: 13, color: 'var(--text-h)', display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(240px,1fr))', gap: '0 24px' }}>
                <div>
                  <KV k="Experience" v={d.summary?.experience_years ? `${d.summary.experience_years} years` : '—'} />
                  <KV k="Expected CTC" v={money(d.summary?.expected_ctc)} />
                  <KV k="Notice Period" v={d.summary?.notice_period} />
                  <KV k="Current Stage" v={d.summary?.stage} />
                </div>
                <div>
                  <KV k="Source" v={d.summary?.source} />
                  <KV k="Education" v={arr(d.summary?.education)} />
                  <KV k="Skills" v={arr(d.summary?.skills)} />
                  <KV k="Languages" v={arr(d.summary?.languages)} />
                </div>
              </div>
            )}
            {tab === 'ai' && d.ai && (
              <div>
                <div style={{ display: 'flex', alignItems: 'center', gap: 14, marginBottom: 14 }}>
                  <div style={{ width: 64, height: 64, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center', flexDirection: 'column', background: 'rgba(124,58,237,0.12)', border: '2px solid #7C3AED' }}>
                    <span style={{ fontSize: 20, fontWeight: 900, color: '#a78bfa' }}>{d.ai.overall_score ?? '—'}</span>
                  </div>
                  <div><p style={{ fontSize: 11, color: 'var(--text-muted)', textTransform: 'uppercase', fontWeight: 700 }}>AI Recommendation</p><p style={{ fontSize: 15, fontWeight: 800, color: REC_COLOR[d.ai.recommendation] || 'var(--text-h)' }}>{d.ai.recommendation}</p></div>
                </div>
                <Bar label="Skill Match" value={d.ai.skill_match} />
                <Bar label="Experience Match" value={d.ai.experience_match} />
                <Bar label="Education Match" value={d.ai.education_match} />
                <Bar label="Location Match" value={d.ai.location_match} />
                <Bar label="Overall Fit" value={d.ai.overall_fit} />
                {d.ai.question_score != null && <Bar label="Question Score" value={d.ai.question_score} />}
                {d.ai.reasons && <p style={{ fontSize: 12.5, color: 'var(--text-muted)', marginTop: 10 }}>{d.ai.reasons}</p>}
              </div>
            )}
            {tab === 'journey' && <Steps items={d.journey} />}
            {tab === 'interviews' && <Simple items={d.interviews} empty="No interviews yet." render={i => <><b style={{ color: 'var(--text-h)' }}>{i.round_name}</b> <span style={{ color: 'var(--text-muted)' }}>· {i.mode} · {i.scheduled_at ? new Date(i.scheduled_at).toLocaleDateString() : '—'} · {i.status}{i.result ? ` · ${i.result}` : ''}</span></>} />}
            {tab === 'offer' && (d.offer ? <div className="card-3d" style={{ padding: 14, fontSize: 13, color: 'var(--text-h)' }}><KV k="Position" v={d.offer.position} /><KV k="CTC" v={d.offer.offered_ctc} /><KV k="Joining" v={d.offer.joining_date} /><KV k="Status" v={d.offer.status} /></div> : <Empty t="No offer released yet." />)}
            {tab === 'timeline' && <TL items={d.timeline} />}
            {tab === 'comments' && (
              <div>
                <div style={{ maxHeight: 260, overflowY: 'auto', display: 'flex', flexDirection: 'column', gap: 8, marginBottom: 10 }}>
                  {comments.length === 0 ? <Empty t="No comments yet." /> : comments.map(m => (
                    <div key={m.id} style={{ alignSelf: m.sender_kind === 'company' ? 'flex-end' : 'flex-start', maxWidth: '78%' }}>
                      <div style={{ padding: '8px 12px', borderRadius: 12, fontSize: 12.5, color: m.sender_kind === 'company' ? '#fff' : 'var(--text-h)', background: m.sender_kind === 'company' ? 'linear-gradient(135deg,#7C3AED,#5b21b6)' : 'var(--bg-input)' }}>{m.body}</div>
                      <p style={{ fontSize: 9.5, color: 'var(--text-muted)', marginTop: 2, textAlign: m.sender_kind === 'company' ? 'right' : 'left' }}>{m.sender?.name || m.sender_kind}</p>
                    </div>
                  ))}
                </div>
                <div style={{ display: 'flex', gap: 8 }}>
                  <input value={cbody} onChange={e => setCbody(e.target.value)} onKeyDown={e => e.key === 'Enter' && sendComment()} placeholder="Write candidate feedback…" style={{ flex: 1, padding: '9px 13px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 10, color: 'var(--text-h)', fontSize: 13, outline: 'none' }} />
                  <button onClick={sendComment} style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '9px 14px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}><Send size={13} /></button>
                </div>
              </div>
            )}
          </>
        )}
      </div>
    </div>
  )
}

const KV = ({ k, v }) => <div style={{ display: 'flex', gap: 10, padding: '6px 0', borderBottom: '1px solid var(--border)' }}><span style={{ color: 'var(--text-muted)', width: 120, flexShrink: 0, fontSize: 12 }}>{k}</span><span style={{ fontWeight: 600 }}>{v || '—'}</span></div>
const arr = a => Array.isArray(a) ? (a.length ? a.join(', ') : '—') : (a || '—')
const Empty = ({ t }) => <div className="card-3d" style={{ padding: 24, textAlign: 'center', color: 'var(--text-muted)', fontSize: 13 }}>{t}</div>
const Simple = ({ items, empty, render }) => (!items || !items.length) ? <Empty t={empty} /> : <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>{items.map((it, i) => <div key={i} className="card-3d" style={{ padding: '10px 14px', fontSize: 12.5 }}>{render(it)}</div>)}</div>
function Steps({ items }) {
  if (!items || !items.length) return <Empty t="No journey data." />
  return <div className="card-3d" style={{ padding: 16 }}>{items.map((s, i) => (
    <div key={i} style={{ display: 'flex', gap: 10, alignItems: 'center', padding: '6px 0' }}>
      <span style={{ width: 8, height: 8, borderRadius: '50%', background: s.status === 'completed' ? '#10b981' : 'var(--border)' }} />
      <span style={{ fontSize: 12.5, color: s.status === 'completed' ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: s.status === 'completed' ? 600 : 400 }}>{s.label}</span>
      {s.at && <span style={{ fontSize: 10.5, color: 'var(--text-muted)', marginLeft: 'auto' }}>{new Date(s.at).toLocaleDateString()}</span>}
    </div>
  ))}</div>
}
function TL({ items }) {
  if (!items || !items.length) return <Empty t="No activity yet." />
  return <div className="card-3d" style={{ padding: 16 }}>{items.map((t, i) => (
    <div key={i} style={{ display: 'flex', gap: 10, paddingBottom: 12 }}>
      <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center' }}><div style={{ width: 8, height: 8, borderRadius: '50%', background: '#7C3AED', marginTop: 4 }} />{i < items.length - 1 && <div style={{ width: 2, flex: 1, background: 'var(--border)', marginTop: 3 }} />}</div>
      <div><p style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{t.action}</p>{t.note && <p style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{t.note}</p>}<p style={{ fontSize: 10, color: 'var(--text-muted)' }}>{t.at ? new Date(t.at).toLocaleString() : ''}</p></div>
    </div>
  ))}</div>
}

const sel = { padding: '9px 12px', background: 'var(--bg-input)', border: '1px solid var(--border)', borderRadius: 8, color: 'var(--text-h)', fontSize: 13, cursor: 'pointer' }
const mini = { display: 'inline-flex', alignItems: 'center', gap: 5, padding: '6px 11px', borderRadius: 8, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 12, fontWeight: 700 }

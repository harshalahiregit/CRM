import { useState, useEffect, useCallback } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import {
  ClipboardCheck, FileStack, Plus, RefreshCw, AlertTriangle, Clock, PenLine,
  ShieldAlert, Link2, Copy, Check, Loader2, Rocket, Archive, Layers,
} from 'lucide-react'
import { complianceApi } from '@/services/complianceApi'
import {
  CL_STATUS, clStatusCfg, tplStatusCfg, riskCfg, RISK_BANDS, SUBJECTS,
  isTemplateIssuable, fmtDate, fmtDateTime,
} from '../constants'
import { KIT3D_STYLE, Overlay, ModalFooter, Field, TextInput, SelectInput, labelStyle } from '@/components/ui/kit3d'
import { useAuth } from '@/context/AuthContext'

/**
 * The compliance workspace — checklist ledger and template library.
 *
 * Mounted under /app/tpv/compliance because TPV is the first consumer, but the
 * engine itself is generic: HR's exit checklists will mount the same pages with
 * a different subject filter.
 */
export default function ComplianceWorkspace() {
  const [params, setParams] = useSearchParams()
  const tab = params.get('tab') === 'templates' ? 'templates' : 'checklists'

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ marginBottom: 18 }}>
        <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>HSSE &amp; ASSURANCE</p>
        <h1 style={{ color: 'var(--text-h)', fontSize: 24, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>Compliance</h1>
        <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Scored checklists, issued against any record and signed off by two tiers.</p>
      </div>

      {/* Tabs */}
      <div style={{ display: 'inline-flex', gap: 4, padding: 4, borderRadius: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', marginBottom: 18 }}>
        {[['checklists', 'Checklists', ClipboardCheck], ['templates', 'Templates', FileStack]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setParams(k === 'checklists' ? {} : { tab: k })}
            style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 15px', borderRadius: 9, cursor: 'pointer', fontSize: 13, fontWeight: 700, border: 'none',
              background: tab === k ? 'linear-gradient(145deg,#a78bfa,#7C3AED)' : 'transparent',
              color: tab === k ? '#fff' : 'var(--text-muted)',
              boxShadow: tab === k ? '0 6px 16px -6px rgba(124,58,237,.7)' : 'none' }}>
            <Icon size={14} /> {label}
          </button>
        ))}
      </div>

      {tab === 'checklists' ? <ChecklistsTab /> : <TemplatesTab />}
    </div>
  )
}

/* ── Checklists ───────────────────────────────────────────────────────────── */
function ChecklistsTab() {
  const navigate = useNavigate()
  const [rows, setRows]   = useState([])
  const [stats, setStats] = useState({})
  const [filters, setF]   = useState({ status: 'All', risk_band: 'All' })
  const [loading, setLoad] = useState(true)
  const [issuing, setIssuing] = useState(false)

  const load = useCallback(() => {
    setLoad(true)
    Promise.all([complianceApi.checklists.list(filters), complianceApi.checklists.stats()])
      .then(([r, s]) => { setRows(r?.data ?? r ?? []); setStats(s?.data ?? s ?? {}); setLoad(false) })
      .catch(() => setLoad(false))
  }, [filters])
  useEffect(() => { load() }, [load])

  return (
    <>
      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 14, marginBottom: 16 }}>
        <Kpi label="Open" value={stats.open ?? 0} sub={`${stats.total ?? 0} all time`} icon={ClipboardCheck} grad="linear-gradient(145deg,#38bdf8,#0ea5e9)" glow="#0ea5e9" />
        <Kpi label="Awaiting signature" value={stats.awaiting ?? 0} sub="submitted or with the manager" icon={PenLine} grad="linear-gradient(145deg,#fbbf24,#f59e0b)" glow="#f59e0b" />
        <Kpi label="High risk" value={stats.high_risk ?? 0} sub="excluding rejected" icon={ShieldAlert} grad="linear-gradient(145deg,#f87171,#ef4444)" glow="#ef4444" />
        <Kpi label="Overdue" value={stats.overdue ?? 0} sub="past due, not submitted" icon={Clock} grad="linear-gradient(145deg,#a78bfa,#7C3AED)" glow="#7C3AED" />
      </div>

      <div className="pr-glass" style={{ padding: 20 }}>
        <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14, flexWrap: 'wrap' }}>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Checklist Ledger</h2>
          <div style={{ marginLeft: 'auto', display: 'flex', gap: 8, alignItems: 'center' }}>
            <select value={filters.status} onChange={e => setF(f => ({ ...f, status: e.target.value }))} style={filterStyle}>
              {['All', ...Object.values(CL_STATUS)].map(s => <option key={s} value={s}>{s === 'All' ? 'All statuses' : clStatusCfg(s).label}</option>)}
            </select>
            <select value={filters.risk_band} onChange={e => setF(f => ({ ...f, risk_band: e.target.value }))} style={filterStyle}>
              {['All', ...RISK_BANDS].map(b => <option key={b} value={b}>{b === 'All' ? 'All risk' : `${b} risk`}</option>)}
            </select>
            <button onClick={load} style={ghostBtn}><RefreshCw size={14} /></button>
            <button onClick={() => setIssuing(true)} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.7)' }}>
              <Plus size={14} /> Issue checklist
            </button>
          </div>
        </div>

        {loading ? (
          <div className="skeleton" style={{ height: 120, borderRadius: 12, background: 'var(--border)' }} />
        ) : rows.length === 0 ? (
          <Empty icon={ClipboardCheck} title="No checklists yet"
            body="Issue one against a vendor or worker — they'll get a link they can fill in on their phone, no login needed." />
        ) : (
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr>{['Checklist', 'Subject', 'Assignee', 'Due', 'Risk', 'Status'].map((h, i) => (
              <th key={h} style={{ textAlign: i === 4 ? 'right' : 'left', padding: '6px 8px', fontSize: 10, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', borderBottom: '1px solid var(--border)' }}>{h}</th>
            ))}</tr></thead>
            <tbody>
              {rows.map(r => {
                const st = clStatusCfg(r.status)
                const rk = riskCfg(r.risk_band)

                return (
                  <tr key={r.id} className="pr-li-row" onClick={() => navigate(`/app/tpv/compliance/checklists/${r.id}`)} style={{ cursor: 'pointer' }}>
                    <td style={td}>
                      <div style={{ fontSize: 12.5, fontWeight: 700, color: 'var(--text-h)' }}>{r.title}</div>
                      <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>{r.template?.code} · {r.template?.name}</div>
                    </td>
                    <td style={td}>
                      {r.subject ? (
                        <>
                          <div style={{ fontSize: 12, color: 'var(--text-h)' }}>{r.subject.name || `#${r.subject.id}`}</div>
                          <div style={{ fontSize: 10.5, color: 'var(--text-muted)' }}>{r.subject.label}</div>
                        </>
                      ) : <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>Standalone</span>}
                    </td>
                    <td style={{ ...td, fontSize: 12, color: 'var(--text-h)' }}>{r.assignee?.name || r.assignee_name || '—'}</td>
                    <td style={{ ...td, fontSize: 12 }}>
                      <span style={{ color: r.is_overdue ? '#ef4444' : 'var(--text-muted)', fontWeight: r.is_overdue ? 700 : 400 }}>
                        {fmtDate(r.due_date)}
                      </span>
                      {r.is_overdue && <AlertTriangle size={11} style={{ color: '#ef4444', marginLeft: 5, verticalAlign: 'middle' }} />}
                    </td>
                    <td style={{ ...td, textAlign: 'right' }}>
                      {r.risk_band ? (
                        // Band + the raw score: the word carries the meaning, the
                        // colour only reinforces it.
                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6 }}>
                          <span style={{ padding: '2px 8px', borderRadius: 999, background: rk.bg, color: rk.color, fontSize: 11, fontWeight: 800 }}>{rk.label}</span>
                          <span style={{ fontSize: 11, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{r.score}/{r.max_score}</span>
                        </span>
                      ) : <span style={{ fontSize: 11, color: 'var(--text-muted)' }}>—</span>}
                    </td>
                    <td style={td}>
                      <span style={{ padding: '3px 9px', borderRadius: 999, background: st.bg, color: st.color, fontSize: 11, fontWeight: 800 }}>{st.label}</span>
                    </td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        )}
      </div>

      {issuing && <IssueModal onClose={() => setIssuing(false)} onDone={() => { setIssuing(false); load() }} />}
    </>
  )
}

/* ── Issue modal ──────────────────────────────────────────────────────────── */
function IssueModal({ onClose, onDone }) {
  const [templates, setTemplates] = useState([])
  const [subjects, setSubjects]   = useState([])
  const [f, setF] = useState({ template_id: '', subject_type: 'vendor', subject_id: '', assignee_name: '', assignee_email: '', due_date: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr]   = useState(null)
  const [issued, setIssued] = useState(null)

  useEffect(() => { complianceApi.templates.list({ status: 'Active' }).then(r => setTemplates(r?.data ?? r ?? [])) }, [])
  useEffect(() => {
    setSubjects([]); setF(x => ({ ...x, subject_id: '' }))
    complianceApi.subjects[f.subject_type]?.().then(r => setSubjects(r?.data ?? r ?? [])).catch(() => setSubjects([]))
  }, [f.subject_type])

  const submit = async () => {
    setBusy(true); setErr(null)
    try {
      const res = await complianceApi.checklists.issue({
        ...f,
        subject_id: f.subject_id || null,
        subject_type: f.subject_id ? f.subject_type : null,
        due_date: f.due_date || null,
      })
      setIssued(res)
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not issue this checklist.')
      setBusy(false)
    }
  }

  // The token is disclosed exactly once, on this response. If they close without
  // copying it, the link is unrecoverable and the checklist must be reopened —
  // so the confirmation step exists to hand it over, not just to say "done".
  if (issued) return <FillLinkPanel issued={issued} onClose={onDone} />

  const activeCount = templates.length

  return (
    <Overlay onClose={onClose} width={720}>
      <h3 style={{ margin: '0 0 4px', fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Issue a checklist</h3>
      <p style={{ margin: '0 0 16px', fontSize: 12.5, color: 'var(--text-muted)' }}>The assignee gets a link they can open on a phone without logging in.</p>

      {activeCount === 0 ? (
        <Empty icon={FileStack} title="No active templates"
          body="A checklist can only be issued from an active template. Build one and activate it first." />
      ) : (
        <>
          <Field label="Template" full>
            <SelectInput value={f.template_id} onChange={e => setF(x => ({ ...x, template_id: e.target.value }))}
              options={[['', 'Choose a template…'], ...templates.map(t => [String(t.id), `${t.code} — ${t.name}`])]} pairs />
          </Field>

          <div style={{ display: 'grid', gridTemplateColumns: '140px 1fr', gap: 12 }}>
            <Field label="About">
              <SelectInput value={f.subject_type} onChange={e => setF(x => ({ ...x, subject_type: e.target.value }))} options={SUBJECTS} pairs />
            </Field>
            <Field label="Record">
              <SelectInput value={f.subject_id} onChange={e => setF(x => ({ ...x, subject_id: e.target.value }))}
                options={[['', 'None — a standalone walk'], ...subjects.map(s => [String(s.id), s.company_name || s.name || `#${s.id}`])]} pairs />
            </Field>
          </div>

          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 12 }}>
            <Field label="Assignee name">
              <TextInput value={f.assignee_name} onChange={e => setF(x => ({ ...x, assignee_name: e.target.value }))} placeholder="Site supervisor" />
            </Field>
            <Field label="Assignee email">
              <TextInput type="email" value={f.assignee_email} onChange={e => setF(x => ({ ...x, assignee_email: e.target.value }))} placeholder="optional" />
            </Field>
          </div>
          <Field label="Due date" full>
            <TextInput type="date" value={f.due_date} onChange={e => setF(x => ({ ...x, due_date: e.target.value }))} />
          </Field>

          {err && <ErrBox>{err}</ErrBox>}

          <ModalFooter onClose={onClose} onConfirm={submit} loading={busy} disabled={!f.template_id} confirmLabel="Issue" />
        </>
      )}
    </Overlay>
  )
}

function FillLinkPanel({ issued, onClose }) {
  const [copied, setCopied] = useState(false)
  // The backend returns the token, not a URL — the API host and the browser
  // origin are different things, so the link is composed here (same as the
  // worker badge's /scan/ QR).
  const url = `${window.location.origin}/checklist/${issued.fill_token}`

  const copy = () => {
    navigator.clipboard?.writeText(url)
    setCopied(true); setTimeout(() => setCopied(false), 2000)
  }

  return (
    <Overlay onClose={onClose} width={720}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <span style={{ width: 34, height: 34, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(16,185,129,0.15)' }}>
          <Check size={17} style={{ color: '#10b981' }} />
        </span>
        <h3 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>Checklist issued</h3>
      </div>
      <p style={{ margin: '0 0 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        Send this link to the assignee. <strong style={{ color: 'var(--text-h)' }}>It is shown only once</strong> — anyone holding it can fill the checklist in.
      </p>

      <div style={{ display: 'flex', gap: 8, alignItems: 'stretch' }}>
        <div style={{ flex: 1, minWidth: 0, display: 'flex', alignItems: 'center', gap: 8, padding: '10px 12px', borderRadius: 10, background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
          <Link2 size={14} style={{ color: '#a78bfa', flexShrink: 0 }} />
          <span style={{ fontSize: 11.5, color: 'var(--text-h)', whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', fontFamily: 'monospace' }}>{url}</span>
        </div>
        <button onClick={copy} style={{ display: 'inline-flex', alignItems: 'center', gap: 6, padding: '0 14px', borderRadius: 10, cursor: 'pointer', fontSize: 12.5, fontWeight: 700, border: 'none', color: '#fff', background: copied ? '#10b981' : 'linear-gradient(145deg,#a78bfa,#7C3AED)' }}>
          {copied ? <Check size={14} /> : <Copy size={14} />} {copied ? 'Copied' : 'Copy'}
        </button>
      </div>

      <div style={{ display: 'flex', justifyContent: 'flex-end', marginTop: 18 }}>
        <button onClick={onClose} style={{ padding: '9px 18px', borderRadius: 10, cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 13, fontWeight: 700 }}>Done</button>
      </div>
    </Overlay>
  )
}

/* ── Templates ────────────────────────────────────────────────────────────── */
function TemplatesTab() {
  const navigate = useNavigate()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'
  const [rows, setRows] = useState([])
  const [loading, setLoad] = useState(true)

  const load = useCallback(() => {
    setLoad(true)
    complianceApi.templates.list().then(r => { setRows(r?.data ?? r ?? []); setLoad(false) }).catch(() => setLoad(false))
  }, [])
  useEffect(() => { load() }, [load])

  const act = async (fn, id) => { await fn(id); load() }

  return (
    <div className="pr-glass" style={{ padding: 20 }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 14 }}>
        <div>
          <h2 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Template Library</h2>
          <p style={{ margin: '2px 0 0', fontSize: 11.5, color: 'var(--text-muted)' }}>Questions freeze once a template goes active — clone to revise</p>
        </div>
        {isAdmin && (
          <button onClick={() => navigate('/app/tpv/compliance/templates/new')}
            style={{ marginLeft: 'auto', display: 'inline-flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: 'none', cursor: 'pointer', fontSize: 13, fontWeight: 700, color: '#fff', background: 'linear-gradient(145deg,#a78bfa,#7C3AED)', boxShadow: '0 8px 20px -6px rgba(124,58,237,.7)' }}>
            <Plus size={14} /> New template
          </button>
        )}
      </div>

      {loading ? (
        <div className="skeleton" style={{ height: 100, borderRadius: 12, background: 'var(--border)' }} />
      ) : rows.length === 0 ? (
        <Empty icon={FileStack} title="No templates yet"
          body={isAdmin ? 'Build one to define the questions and the risk thresholds a checklist scores against.' : 'An admin needs to author a template before checklists can be issued.'} />
      ) : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 9 }}>
          {rows.map(t => {
            const cfg = tplStatusCfg(t.status)

            return (
              <div key={t.id} className="pr-lift" onClick={() => navigate(`/app/tpv/compliance/templates/${t.id}`)}
                style={{ display: 'flex', alignItems: 'center', gap: 13, padding: '13px 15px', borderRadius: 14, cursor: 'pointer', background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <span style={{ width: 36, height: 36, borderRadius: 11, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0, background: `${cfg.color}1f` }}>
                  <Layers size={16} style={{ color: cfg.color }} />
                </span>
                <div style={{ flex: 1, minWidth: 0 }}>
                  <div style={{ fontSize: 13, fontWeight: 800, color: 'var(--text-h)' }}>{t.name}</div>
                  <div style={{ fontSize: 11, color: 'var(--text-muted)' }}>
                    {t.code}{t.category ? ` · ${t.category}` : ''} · {t.question_count} question{t.question_count === 1 ? '' : 's'}
                    {t.checklists_count > 0 && ` · ${t.checklists_count} issued`}
                  </div>
                </div>
                <span style={{ padding: '3px 9px', borderRadius: 999, background: cfg.bg, color: cfg.color, fontSize: 11, fontWeight: 800, flexShrink: 0 }}>{cfg.label}</span>
                {isAdmin && (
                  <div style={{ display: 'flex', gap: 6, flexShrink: 0 }} onClick={e => e.stopPropagation()}>
                    {t.status === 'Draft' && (
                      <button onClick={() => act(complianceApi.templates.activate, t.id)} title="Activate" style={miniBtn('#10b981')}><Rocket size={13} /></button>
                    )}
                    {isTemplateIssuable(t.status) && (
                      <button onClick={() => act(complianceApi.templates.archive, t.id)} title="Archive" style={miniBtn('#f59e0b')}><Archive size={13} /></button>
                    )}
                  </div>
                )}
              </div>
            )
          })}
        </div>
      )}
    </div>
  )
}

/* ── bits ─────────────────────────────────────────────────────────────────── */
const td = { padding: '10px 8px', borderBottom: '1px solid var(--border)' }
const filterStyle = { padding: '7px 10px', borderRadius: 9, background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-h)', fontSize: 12.5, outline: 'none', cursor: 'pointer' }
const ghostBtn = { display: 'flex', alignItems: 'center', padding: '8px 10px', borderRadius: 9, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', cursor: 'pointer' }
const miniBtn = (c) => ({ width: 28, height: 28, borderRadius: 8, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: `${c}16`, border: `1px solid ${c}44`, color: c })

function Kpi({ label, value, sub, icon: Icon, grad, glow }) {
  return (
    <div className="pr-kpi" style={{ padding: 18, cursor: 'default' }}>
      <div style={{ position: 'absolute', top: -14, right: -14, width: 72, height: 72, borderRadius: '50%', opacity: 0.1, background: grad }} />
      <div style={{ width: 42, height: 42, borderRadius: 14, display: 'flex', alignItems: 'center', justifyContent: 'center', background: grad, boxShadow: `0 8px 20px -4px ${glow}88, inset 0 1px 0 rgba(255,255,255,.3)` }}>
        <Icon size={20} color="#fff" />
      </div>
      <div style={{ fontSize: 22, fontWeight: 900, color: 'var(--text-h)', marginTop: 12 }}>{value}</div>
      <div style={{ fontSize: 12.5, fontWeight: 600, color: 'var(--text-muted)', marginTop: 1 }}>{label}</div>
      {sub && <div style={{ fontSize: 10.5, color: 'var(--text-faint, var(--text-muted))', marginTop: 3 }}>{sub}</div>}
    </div>
  )
}

export const Empty = ({ icon: Icon, title, body }) => (
  <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', textAlign: 'center', padding: '30px 20px', gap: 8 }}>
    <div style={{ width: 46, height: 46, borderRadius: 15, display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'rgba(124,58,237,0.1)' }}>
      <Icon size={21} style={{ color: '#a78bfa' }} />
    </div>
    <div style={{ fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>{title}</div>
    <p style={{ fontSize: 12.5, color: 'var(--text-muted)', margin: 0, maxWidth: 380, lineHeight: 1.5 }}>{body}</p>
  </div>
)

export const ErrBox = ({ children }) => (
  <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px 12px', borderRadius: 10, marginTop: 12,
    background: 'rgba(239,68,68,0.1)', border: '1px solid rgba(239,68,68,0.4)' }}>
    <AlertTriangle size={14} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
    <span style={{ fontSize: 12.5, color: 'var(--text-h)', lineHeight: 1.45 }}>{children}</span>
  </div>
)

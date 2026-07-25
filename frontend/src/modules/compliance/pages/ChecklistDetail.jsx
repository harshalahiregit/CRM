import { useState, useEffect, useCallback } from 'react'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ArrowLeft, ShieldAlert, MapPin, Globe, Camera, CheckCircle2, XCircle, PenLine,
  RotateCcw, Loader2, Clock, User, AlertTriangle, ShieldCheck, Ban,
} from 'lucide-react'
import { complianceApi } from '@/services/complianceApi'
import {
  clStatusCfg, riskCfg, tierLabel, CL_STATUS, fmtDateTime, fmtDate,
} from '../constants'
import { KIT3D_STYLE, Overlay, ModalFooter, labelStyle, inputStyle } from '@/components/ui/kit3d'
import { Empty, ErrBox } from './ComplianceWorkspace'
import { useAuth } from '@/context/AuthContext'

/**
 * One checklist: what was answered, what it scored, and who signed it off.
 */
export default function ChecklistDetail() {
  const { id } = useParams()
  const navigate = useNavigate()
  const { user } = useAuth()
  const isAdmin = user?.role === 'admin'

  const [data, setData]   = useState(null)
  const [loading, setLoad] = useState(true)
  const [signing, setSigning] = useState(null)   // { tier, action }

  const load = useCallback(() => {
    complianceApi.checklists.get(id)
      .then(d => { setData(d?.data ?? d); setLoad(false) })
      .catch(() => setLoad(false))
  }, [id])
  useEffect(() => { load() }, [load])

  if (loading) return <div style={{ padding: 24 }}><style>{KIT3D_STYLE}</style><div className="skeleton" style={{ height: 40, width: 260, borderRadius: 12, background: 'var(--border)' }} /></div>
  if (!data) return <div style={{ padding: 24, color: 'var(--text-muted)' }}>Checklist not found.</div>

  const c = data.checklist
  const st = clStatusCfg(c.status)
  const rk = riskCfg(c.risk_band)
  const questions = (c.template?.definition?.sections || []).flatMap(s => (s.questions || []).map(q => ({ ...q, section: s.title })))

  // Which tier can act now is the server's rule (SignatureTier::ACTS_ON) —
  // mirrored here only to decide what to render.
  const canManager = c.status === CL_STATUS.SUBMITTED
  const canHead    = c.status === CL_STATUS.MANAGER_APPROVED && isAdmin
  const canReopen  = c.status === CL_STATUS.REJECTED && isAdmin
  const isSelfIssued = c.issued_by === user?.id

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      {/* Header */}
      <div style={{ display: 'flex', alignItems: 'flex-start', justifyContent: 'space-between', gap: 12, marginBottom: 18, flexWrap: 'wrap' }}>
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
          <button onClick={() => navigate('/app/tpv/compliance')}
            style={{ width: 34, height: 34, borderRadius: 10, display: 'flex', alignItems: 'center', justifyContent: 'center', cursor: 'pointer', background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-muted)', marginTop: 3 }}>
            <ArrowLeft size={16} />
          </button>
          <div>
            <p className="label-caps" style={{ color: '#a78bfa', margin: 0, fontSize: 11, fontWeight: 800, letterSpacing: '0.08em' }}>{c.template?.code}</p>
            <h1 style={{ color: 'var(--text-h)', fontSize: 23, fontWeight: 900, margin: '2px 0 0', letterSpacing: '-0.02em' }}>{c.title}</h1>
            <div style={{ display: 'flex', gap: 8, marginTop: 7, flexWrap: 'wrap', alignItems: 'center' }}>
              <span style={{ padding: '3px 9px', borderRadius: 999, background: st.bg, color: st.color, fontSize: 11, fontWeight: 800 }}>{st.label}</span>
              {data.form?.subject && <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>{data.form.subject.label}: <strong style={{ color: 'var(--text-h)' }}>{data.form.subject.name}</strong></span>}
              {c.due_date && <span style={{ fontSize: 12, color: c.is_overdue ? '#ef4444' : 'var(--text-muted)' }}>Due {fmtDate(c.due_date)}</span>}
            </div>
          </div>
        </div>

        <div style={{ display: 'flex', gap: 9 }}>
          {canManager && (
            <>
              <button onClick={() => setSigning({ tier: 'manager', action: 'reject' })} style={actBtn('#ef4444')}><XCircle size={14} /> Reject</button>
              <button onClick={() => setSigning({ tier: 'manager', action: 'approve' })} style={actBtn('#10b981', true)}><PenLine size={14} /> Manager sign-off</button>
            </>
          )}
          {canHead && (
            <>
              <button onClick={() => setSigning({ tier: 'head', action: 'reject' })} style={actBtn('#ef4444')}><XCircle size={14} /> Reject</button>
              <button onClick={() => setSigning({ tier: 'head', action: 'approve' })} style={actBtn('#10b981', true)}><ShieldCheck size={14} /> Head approval</button>
            </>
          )}
          {canReopen && (
            <button onClick={async () => { await complianceApi.checklists.reopen(c.id); load() }} style={actBtn('#7C3AED', true)}>
              <RotateCcw size={14} /> Reopen for rework
            </button>
          )}
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: '1fr 340px', gap: 16, alignItems: 'start' }}>
        {/* ── Left: answers ── */}
        <div>
          {c.status === CL_STATUS.ASSIGNED ? (
            <div className="pr-glass" style={{ padding: 20 }}>
              <Empty icon={Clock} title="Not submitted yet"
                body={`Waiting on ${c.assignee?.name || c.assignee_name || 'the assignee'} to complete and submit the checklist.`} />
            </div>
          ) : (
            <div className="pr-glass" style={{ padding: 20 }}>
              <h2 style={{ margin: '0 0 14px', fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>Answers</h2>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 11 }}>
                {questions.map(q => {
                  const a = (c.responses || {})[q.key] || {}
                  const critical = (c.critical_failures || []).includes(q.key)

                  return (
                    <div key={q.key} style={{ padding: '12px 14px', borderRadius: 12,
                      background: critical ? 'rgba(239,68,68,0.07)' : 'var(--bg-input)',
                      border: `1px solid ${critical ? 'rgba(239,68,68,0.35)' : 'var(--border)'}` }}>
                      <div style={{ display: 'flex', justifyContent: 'space-between', gap: 12, alignItems: 'flex-start' }}>
                        <div style={{ minWidth: 0 }}>
                          <div style={{ fontSize: 10, fontWeight: 800, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>{q.section}</div>
                          <div style={{ fontSize: 13, fontWeight: 700, color: 'var(--text-h)', marginTop: 2 }}>{q.label}</div>
                        </div>
                        <div style={{ flexShrink: 0, textAlign: 'right' }}>
                          <AnswerValue q={q} a={a} />
                          {critical && (
                            <div style={{ display: 'inline-flex', alignItems: 'center', gap: 4, marginTop: 4, padding: '2px 7px', borderRadius: 999, background: 'rgba(239,68,68,0.16)' }}>
                              <ShieldAlert size={10} style={{ color: '#ef4444' }} />
                              <span style={{ fontSize: 9.5, fontWeight: 800, color: '#ef4444' }}>CRITICAL</span>
                            </div>
                          )}
                        </div>
                      </div>
                      {a.remark && (
                        <p style={{ margin: '8px 0 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.5, paddingLeft: 10, borderLeft: '2px solid var(--border)' }}>{a.remark}</p>
                      )}
                    </div>
                  )
                })}
              </div>
            </div>
          )}
        </div>

        {/* ── Right: score, capture, chain ── */}
        <div style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
          {c.risk_band && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <h2 style={{ margin: '0 0 12px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Risk assessment</h2>
              <div style={{ display: 'flex', alignItems: 'center', gap: 14 }}>
                <div style={{ width: 62, height: 62, borderRadius: '50%', flexShrink: 0, display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center',
                  background: rk.bg, border: `2px solid ${rk.color}66`, boxShadow: `0 10px 26px -10px ${rk.color}99` }}>
                  <span style={{ fontSize: 17, fontWeight: 900, color: rk.color, lineHeight: 1 }}>{Math.round(c.risk_percent)}%</span>
                </div>
                <div style={{ minWidth: 0 }}>
                  {/* The word, not just the colour. */}
                  <div style={{ fontSize: 15, fontWeight: 900, color: rk.color }}>{rk.label} risk</div>
                  <div style={{ fontSize: 12, color: 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{c.score} of {c.max_score} risk points</div>
                </div>
              </div>
              {(c.critical_failures || []).length > 0 && (
                <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, padding: '10px 12px', borderRadius: 11, marginTop: 13, background: 'rgba(239,68,68,0.08)', border: '1px solid rgba(239,68,68,0.3)' }}>
                  <ShieldAlert size={14} style={{ color: '#ef4444', flexShrink: 0, marginTop: 1 }} />
                  <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
                    Banded <strong style={{ color: '#ef4444' }}>High</strong> on a critical control, not on the score.
                  </p>
                </div>
              )}
            </div>
          )}

          {c.submitted_at && (
            <div className="pr-glass" style={{ padding: 20 }}>
              <h2 style={{ margin: '0 0 4px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Submission</h2>
              <p style={{ margin: '0 0 12px', fontSize: 11.5, color: 'var(--text-muted)' }}>{fmtDateTime(c.submitted_at)}</p>
              <div style={{ display: 'flex', flexDirection: 'column', gap: 8 }}>
                <CaptureRow icon={MapPin} label="Location"
                  value={c.latitude ? `${Number(c.latitude).toFixed(5)}, ${Number(c.longitude).toFixed(5)}` : 'Not provided'}
                  ok={!!c.latitude} />
                <CaptureRow icon={Globe} label="IP address" value={c.submitted_ip || '—'} ok={!!c.submitted_ip} />
                <CaptureRow icon={Camera} label="Photo" value={c.selfie_path ? 'Captured' : 'Not provided'} ok={!!c.selfie_path} />
              </div>
              {/* Say what this evidence is worth. Coordinates come from the
                  filler's own device and are self-reported, so they corroborate
                  a submission — they do not prove presence. */}
              <p style={{ margin: '12px 0 0', fontSize: 10.5, color: 'var(--text-muted)', lineHeight: 1.5 }}>
                Location and photo are reported by the submitter's device. The IP is recorded by the server.
              </p>
            </div>
          )}

          <div className="pr-glass" style={{ padding: 20 }}>
            <h2 style={{ margin: '0 0 14px', fontSize: 14, fontWeight: 800, color: 'var(--text-h)' }}>Signature chain</h2>
            <Chain signatures={c.signatures || []} status={c.status} />
          </div>
        </div>
      </div>

      {signing && (
        <SignModal action={signing} checklist={c} isAdmin={isAdmin} isSelfIssued={isSelfIssued}
          onClose={() => setSigning(null)} onDone={() => { setSigning(null); load() }} />
      )}
    </div>
  )
}

/* ── Signature chain ──────────────────────────────────────────────────────── */
function Chain({ signatures, status }) {
  const tiers = ['issuer', 'manager', 'head']
  const byTier = Object.fromEntries(signatures.map(s => [s.tier, s]))

  return (
    <div style={{ display: 'flex', flexDirection: 'column', gap: 0 }}>
      {tiers.map((tier, i) => {
        const s = byTier[tier]
        const rejected = s?.action === 'reject'
        const tone = !s ? 'var(--border)' : rejected ? '#ef4444' : '#10b981'
        const pending = !s && !(status === CL_STATUS.REJECTED)

        return (
          <div key={tier} style={{ display: 'flex', gap: 11 }}>
            <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', flexShrink: 0 }}>
              <span style={{ width: 26, height: 26, borderRadius: '50%', display: 'flex', alignItems: 'center', justifyContent: 'center',
                background: s ? `${tone}22` : 'var(--bg-input)', border: `1.5px solid ${s ? tone : 'var(--border)'}` }}>
                {s ? (rejected ? <Ban size={12} style={{ color: tone }} /> : <CheckCircle2 size={12} style={{ color: tone }} />)
                   : <Clock size={11} style={{ color: 'var(--text-muted)' }} />}
              </span>
              {i < tiers.length - 1 && <span style={{ width: 1.5, flex: 1, minHeight: 26, background: 'var(--border)' }} />}
            </div>
            <div style={{ paddingBottom: i < tiers.length - 1 ? 16 : 0, minWidth: 0, flex: 1 }}>
              <div style={{ display: 'flex', alignItems: 'center', gap: 7, flexWrap: 'wrap' }}>
                <span style={{ fontSize: 12.5, fontWeight: 800, color: s ? 'var(--text-h)' : 'var(--text-muted)' }}>{tierLabel(tier)}</span>
                {s?.segregation_overridden && (
                  // The whole reason this is a column and not a hidden flag.
                  <span title="This person issued the checklist and signed it off themselves"
                    style={{ display: 'inline-flex', alignItems: 'center', gap: 3, padding: '1px 6px', borderRadius: 999, background: 'rgba(245,158,11,0.16)', border: '1px solid rgba(245,158,11,0.4)' }}>
                    <AlertTriangle size={9} style={{ color: '#f59e0b' }} />
                    <span style={{ fontSize: 9, fontWeight: 800, color: '#f59e0b' }}>SELF-APPROVED</span>
                  </span>
                )}
              </div>
              {s ? (
                <>
                  <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>
                    {s.user?.name || 'Public link'} · {fmtDateTime(s.signed_at)}
                  </div>
                  {s.remarks && <p style={{ margin: '5px 0 0', fontSize: 11.5, color: 'var(--text-h)', lineHeight: 1.5, paddingLeft: 9, borderLeft: `2px solid ${tone}55` }}>{s.remarks}</p>}
                </>
              ) : (
                <div style={{ fontSize: 11.5, color: 'var(--text-muted)' }}>{pending ? 'Awaiting sign-off' : 'Not reached'}</div>
              )}
            </div>
          </div>
        )
      })}
    </div>
  )
}

/* ── Sign modal ───────────────────────────────────────────────────────────── */
function SignModal({ action, checklist, isAdmin, isSelfIssued, onClose, onDone }) {
  const { tier, action: verb } = action
  const isReject = verb === 'reject'
  const [remarks, setRemarks] = useState('')
  const [override, setOverride] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(null)

  // Approving your own issue is blocked unless an admin knowingly overrides.
  // Rejecting is always allowed — being stricter with your own work is no
  // conflict of interest.
  const needsOverride = tier === 'manager' && !isReject && isSelfIssued

  const go = async () => {
    setBusy(true); setErr(null)
    try {
      const fn = tier === 'manager' ? complianceApi.checklists.managerSign : complianceApi.checklists.headSign
      await fn(checklist.id, { action: verb, remarks, override_segregation: needsOverride && override })
      onDone()
    } catch (e) {
      setErr(e?.response?.data?.message || 'Could not record this signature.')
      setBusy(false)
    }
  }

  const blocked = needsOverride && (!isAdmin || !override)

  return (
    <Overlay onClose={onClose} width={480}>
      <h3 style={{ margin: '0 0 4px', fontSize: 17, fontWeight: 900, color: 'var(--text-h)' }}>
        {isReject ? `Reject as ${tierLabel(tier).toLowerCase()}` : `${tierLabel(tier)} sign-off`}
      </h3>
      <p style={{ margin: '0 0 14px', fontSize: 12.5, color: 'var(--text-muted)' }}>
        {isReject
          ? 'This goes back to the assignee. Say what has to change.'
          : tier === 'head' ? 'This closes the checklist. The fill-in link stops working.' : 'This passes the checklist to the head for final approval.'}
      </p>

      {needsOverride && (
        <div style={{ padding: '12px 14px', borderRadius: 12, marginBottom: 14,
          background: 'rgba(245,158,11,0.08)', border: '1px solid rgba(245,158,11,0.4)' }}>
          <div style={{ display: 'flex', alignItems: 'flex-start', gap: 9 }}>
            <AlertTriangle size={15} style={{ color: '#f59e0b', flexShrink: 0, marginTop: 1 }} />
            <div style={{ minWidth: 0 }}>
              <div style={{ fontSize: 12.5, fontWeight: 800, color: '#f59e0b', marginBottom: 3 }}>You issued this checklist</div>
              <p style={{ fontSize: 11.5, color: 'var(--text-muted)', margin: 0, lineHeight: 1.5 }}>
                {isAdmin
                  ? 'Signing it off yourself means nobody else reviewed it. That is recorded on the record.'
                  : 'Someone other than the issuer has to sign this off. Ask another manager to review it.'}
              </p>
              {isAdmin && (
                <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginTop: 9, cursor: 'pointer' }}>
                  <input type="checkbox" checked={override} onChange={e => setOverride(e.target.checked)} style={{ width: 15, height: 15, accentColor: '#f59e0b' }} />
                  <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-h)' }}>Override — no second reviewer is available</span>
                </label>
              )}
            </div>
          </div>
        </div>
      )}

      <label style={labelStyle}>
        {isReject || (needsOverride && override) ? 'Reason *' : 'Remarks'}
      </label>
      <textarea rows={3} value={remarks} onChange={e => setRemarks(e.target.value)}
        placeholder={isReject ? 'What has to change before this can be accepted?'
          : needsOverride && override ? 'Why was no second reviewer available?' : 'Optional'}
        style={{ ...inputStyle, minHeight: 76, resize: 'vertical' }} />

      {err && <ErrBox>{err}</ErrBox>}

      <ModalFooter onClose={onClose} onConfirm={go} loading={busy} disabled={blocked}
        confirmLabel={isReject ? 'Reject' : 'Sign off'} color={isReject ? '#ef4444' : '#10b981'} />
    </Overlay>
  )
}

/* ── bits ─────────────────────────────────────────────────────────────────── */
function AnswerValue({ q, a }) {
  if (a?.na) return <span style={{ fontSize: 12, fontWeight: 700, color: 'var(--text-muted)' }}>N/A</span>
  if (a?.value === undefined || a?.value === null || a?.value === '') return <span style={{ fontSize: 12, color: 'var(--text-muted)' }}>—</span>

  if (q.type === 'boolean') {
    const risky = a.value === (q.risk_when ?? false)
    return <span style={{ fontSize: 12.5, fontWeight: 800, color: risky ? '#ef4444' : '#10b981' }}>{a.value ? 'Yes' : 'No'}</span>
  }
  if (q.type === 'choice') {
    const o = (q.options || []).find(x => x.value === a.value)
    const risky = Number(o?.risk || 0) > 0
    return <span style={{ fontSize: 12.5, fontWeight: 700, color: risky ? (o?.critical ? '#ef4444' : '#f59e0b') : '#10b981' }}>{o?.label || a.value}</span>
  }

  return <span style={{ fontSize: 12.5, color: 'var(--text-h)', maxWidth: 220, display: 'inline-block', wordBreak: 'break-word' }}>{String(a.value)}</span>
}

const CaptureRow = ({ icon: Icon, label, value, ok }) => (
  <div style={{ display: 'flex', alignItems: 'center', gap: 9 }}>
    <Icon size={13} style={{ color: ok ? '#10b981' : 'var(--text-muted)', flexShrink: 0 }} />
    <span style={{ fontSize: 11.5, color: 'var(--text-muted)', flex: 1 }}>{label}</span>
    <span style={{ fontSize: 11.5, fontWeight: 700, color: ok ? 'var(--text-h)' : 'var(--text-muted)', fontVariantNumeric: 'tabular-nums' }}>{value}</span>
  </div>
)

const actBtn = (color, solid = false) => ({
  display: 'inline-flex', alignItems: 'center', gap: 6, padding: '9px 14px', borderRadius: 10, cursor: 'pointer', fontSize: 13, fontWeight: 700,
  background: solid ? `linear-gradient(145deg, ${color}dd, ${color})` : 'var(--bg-card)',
  border: solid ? 'none' : `1px solid ${color}55`,
  color: solid ? '#fff' : color,
  boxShadow: solid ? `0 8px 20px -6px ${color}88` : 'none',
})

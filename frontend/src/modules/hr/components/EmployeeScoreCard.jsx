import { useState, useEffect, useCallback } from 'react'
import {
  Gauge, RefreshCw, Sparkles, ThumbsUp, TrendingUp, AlertTriangle,
  Loader2, Info, History,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'

/**
 * Review comments #39 (employee overall score) and #40 (positive / improvement /
 * risk factors).
 *
 * The display follows the engine's honesty rules rather than papering over them:
 * an employee with too little data shows WHY there is no score and what to
 * record, instead of a grey zero. Every insight shows the evidence behind it, so
 * a manager can check the claim rather than take it on trust.
 */

const BAND_COLOUR = {
  Excellent: '#10b981', Strong: '#22c55e', Steady: '#f59e0b',
  'Needs Support': '#f97316', 'At Risk': '#f87171',
}
const SEVERITY = { high: '#f87171', medium: '#f59e0b', low: '#94a3b8' }

export default function EmployeeScoreCard({ employeeId, canManage }) {
  const [data, setData]       = useState(null)
  const [loading, setLoading] = useState(true)
  const [busy, setBusy]       = useState(null)   // 'score' | 'insights'
  const [error, setError]     = useState(null)
  const [showHistory, setShowHistory] = useState(false)

  const load = useCallback(async () => {
    setLoading(true)
    try { setData(await hrApi.employees.score(employeeId)); setError(null) }
    catch (e) {
      // 403 is a permission answer, not a broken card — say so plainly.
      setError(e?.response?.status === 403
        ? 'You are not authorised to view employee scores.'
        : (e?.response?.data?.message || 'Could not load the score'))
    }
    finally { setLoading(false) }
  }, [employeeId])

  useEffect(() => { load() }, [load])

  const run = async (kind, fn) => {
    setBusy(kind)
    try { await fn(); await load() }
    catch (e) { setError(e?.response?.data?.message || 'Action failed') }
    finally { setBusy(null) }
  }

  if (loading) return <p className="text-xs" style={{ color:'var(--text-muted)' }}>Loading score…</p>
  if (error) return <p className="text-xs" style={{ color:'#f87171' }}>{error}</p>

  const s = data?.score
  const band = s?.band
  const colour = BAND_COLOUR[band] || 'var(--text-muted)'
  const history = data?.history || []

  return (
    <div className="space-y-4">
      {/* ── #39 — the headline number ─────────────────────────────────── */}
      <div className="card-3d" style={{ padding:'18px 20px' }}>
        <div className="flex items-start justify-between gap-3 flex-wrap mb-3">
          <p className="text-xs font-black flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
            <Gauge size={14} style={{ color:'#a78bfa' }}/> Employee Score
          </p>
          {canManage && (
            <div className="flex items-center gap-1.5">
              <button onClick={()=>run('score', ()=>hrApi.employees.recalculateScore(employeeId, 'manual'))}
                disabled={busy} className="rounded-lg px-2.5 py-1 text-[11px] font-bold inline-flex items-center gap-1.5"
                style={{ background:'var(--bg-input)', border:'1px solid var(--border)', color:'#a78bfa' }}>
                {busy === 'score' ? <Loader2 size={11} className="animate-spin"/> : <RefreshCw size={11}/>}
                {s?.scored_at ? 'Recalculate' : 'Calculate'}
              </button>
              {s?.scored_at && (
                <button onClick={()=>run('insights', ()=>hrApi.employees.generateInsights(employeeId, true))}
                  disabled={busy} className="rounded-lg px-2.5 py-1 text-[11px] font-bold inline-flex items-center gap-1.5"
                  style={{ background:'rgba(124,58,237,0.12)', border:'1px solid rgba(124,58,237,0.4)', color:'#a78bfa' }}>
                  {busy === 'insights' ? <Loader2 size={11} className="animate-spin"/> : <Sparkles size={11}/>}
                  {s?.insights_generated_at ? 'Regenerate Insights' : 'Generate Insights'}
                </button>
              )}
            </div>
          )}
        </div>

        {!data?.scored ? (
          <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>
            No score yet. {canManage ? 'Calculate one from this employee’s performance, attendance and training records.' : 'HR has not calculated a score for this employee.'}
          </p>
        ) : s.overall_score === null ? (
          // The engine suppressed the score. Say why, and what would fix it.
          <div className="rounded-xl p-3 flex items-start gap-2" style={{ background:'rgba(251,191,36,0.08)', border:'1px solid rgba(251,191,36,0.25)' }}>
            <Info size={13} style={{ color:'#fbbf24', flexShrink:0, marginTop:1 }}/>
            <p className="text-[11px]" style={{ color:'#fbbf24' }}>{s.summary}</p>
          </div>
        ) : (
          <>
            <div className="flex items-end gap-4 flex-wrap">
              <div>
                <p className="font-black leading-none" style={{ fontSize:42, color:colour }}>{s.overall_score}<span style={{ fontSize:18 }}>%</span></p>
                <p className="text-xs font-bold mt-1" style={{ color:colour }}>{band}</p>
              </div>
              <div className="flex-1 min-w-[160px]">
                <div className="rounded-full overflow-hidden" style={{ height:8, background:'var(--bg-input)' }}>
                  <div style={{ width:`${s.overall_score}%`, height:'100%', background:colour }}/>
                </div>
                {/* Confidence is shown next to the score, never hidden: a 90% at
                    40% confidence means something different from a 90% at 95%. */}
                <p className="text-[10px] mt-1.5" style={{ color:'var(--text-muted)' }}>
                  {s.confidence}% confidence — the share of the scoring model that could actually be measured.
                </p>
              </div>
            </div>
            <p className="text-[11px] mt-3" style={{ color:'var(--text-muted)' }}>{s.summary}</p>
          </>
        )}

        {/* Per-dimension breakdown — the score is never a black box. */}
        {s?.dimensions?.length > 0 && (
          <div className="mt-4 pt-3 space-y-1.5" style={{ borderTop:'1px solid var(--border)' }}>
            {s.dimensions.map(d => (
              <div key={d.key} className="flex items-center gap-2.5">
                <span className="text-[11px] w-44 flex-shrink-0" style={{ color:'var(--text-h)' }}>{d.name}</span>
                {d.score === null ? (
                  <span className="text-[10px] flex-1" style={{ color:'var(--text-muted)', fontStyle:'italic' }}>
                    {d.reason}
                  </span>
                ) : (
                  <>
                    <span className="flex-1 rounded-full overflow-hidden" style={{ height:5, background:'var(--bg-input)' }}>
                      <span style={{ display:'block', width:`${d.score}%`, height:'100%',
                        background: d.score >= 75 ? '#10b981' : d.score >= 55 ? '#f59e0b' : '#f87171' }}/>
                    </span>
                    <span className="text-[10px] font-bold w-8 text-right" style={{ color:'var(--text-h)' }}>{d.score}</span>
                    <span className="text-[9px] w-8 text-right" style={{ color:'var(--text-muted)' }}>
                      {s.applied_weights?.[d.key] ? `${s.applied_weights[d.key]}%` : '—'}
                    </span>
                  </>
                )}
              </div>
            ))}
            <p className="text-[9px] pt-1" style={{ color:'var(--text-muted)' }}>
              Italic rows had nothing to measure and were excluded from the average — they are not zeros.
            </p>
          </div>
        )}

        {history.length > 1 && (
          <div className="mt-3 pt-3" style={{ borderTop:'1px solid var(--border)' }}>
            <button onClick={()=>setShowHistory(v=>!v)} className="text-[11px] font-bold inline-flex items-center gap-1.5" style={{ color:'#a78bfa' }}>
              <History size={11}/> {showHistory ? 'Hide' : 'Show'} score history ({history.length})
            </button>
            {showHistory && (
              <div className="mt-2 space-y-1">
                {history.map(h => (
                  <div key={h.id} className="flex items-center gap-2 text-[10px]" style={{ color:'var(--text-muted)' }}>
                    <span style={{ width:120 }}>{h.scored_at ? new Date(h.scored_at).toLocaleString('en-IN') : '—'}</span>
                    <span className="font-bold" style={{ color:'var(--text-h)' }}>{h.overall_score ?? '—'}</span>
                    {h.delta !== null && (
                      <span style={{ color: h.delta >= 0 ? '#10b981' : '#f87171' }}>
                        {h.delta >= 0 ? '+' : ''}{h.delta}
                      </span>
                    )}
                    <span>· {h.trigger}</span>
                  </div>
                ))}
              </div>
            )}
          </div>
        )}
      </div>

      {/* ── #40 — positive / improvement / risk ───────────────────────── */}
      {s?.insights_generated_at && (
        <>
          {s.insight_narrative && (
            <div className="card-3d" style={{ padding:'14px 16px' }}>
              <p className="text-[11px] font-bold uppercase mb-1.5 flex items-center gap-1.5" style={{ color:'#a78bfa', letterSpacing:'0.04em' }}>
                <Sparkles size={12}/> Summary
              </p>
              <p className="text-xs" style={{ color:'var(--text-h)' }}>{s.insight_narrative}</p>
              {/* Provenance, so nobody wonders whether a person or a model wrote it. */}
              <p className="text-[9px] mt-2" style={{ color:'var(--text-muted)' }}>
                Written by {s.insight_meta?.provider || 'the system'}
                {s.insight_meta?.model ? ` (${s.insight_meta.model})` : ''} from the facts below ·
                {' '}{new Date(s.insights_generated_at).toLocaleString('en-IN')}
              </p>
            </div>
          )}

          <div className="grid md:grid-cols-3 gap-3">
            <InsightGroup title="Positive Factors" icon={ThumbsUp} colour="#10b981"
              items={s.positives} empty="No standout strengths in the measured areas yet." />
            <InsightGroup title="Areas for Improvement" icon={TrendingUp} colour="#f59e0b"
              items={s.improvements} empty="No areas fell below the improvement threshold." />
            <InsightGroup title="Risk Factors" icon={AlertTriangle} colour="#f87171"
              items={s.risks} empty="No risk indicators found in the available data." severity />
          </div>
        </>
      )}
    </div>
  )
}

/** One of the three groups. Every item carries the fact that produced it. */
function InsightGroup({ title, icon: Icon, colour, items, empty, severity }) {
  const rows = items || []

  return (
    <div className="card-3d" style={{ padding:'14px 16px' }}>
      <p className="text-xs font-black flex items-center gap-1.5 mb-2" style={{ color:'var(--text-h)' }}>
        <Icon size={13} style={{ color:colour }}/> {title}
        <span className="px-1.5 py-0.5 rounded text-[9px]" style={{ background:`${colour}1a`, color:colour }}>{rows.length}</span>
      </p>

      {rows.length === 0 ? (
        <p className="text-[11px]" style={{ color:'var(--text-muted)' }}>{empty}</p>
      ) : (
        <div className="space-y-2">
          {rows.map((r, i) => (
            <div key={r.key || i} className="rounded-lg px-2.5 py-2" style={{ background:'var(--bg-input)' }}>
              <p className="text-[11px] font-bold flex items-center gap-1.5" style={{ color:'var(--text-h)' }}>
                {severity && r.severity && (
                  <span style={{ width:6, height:6, borderRadius:99, background:SEVERITY[r.severity] || colour }}/>
                )}
                {r.title}
              </p>
              <p className="text-[10px] mt-0.5" style={{ color:'var(--text-muted)' }}>{r.detail}</p>
              {r.action && (
                <p className="text-[10px] mt-1 font-semibold" style={{ color: colour }}>{r.action}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  )
}

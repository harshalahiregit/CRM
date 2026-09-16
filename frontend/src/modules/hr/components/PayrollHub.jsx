import { useState, useEffect, useCallback } from 'react'
import {
  PlayCircle, Users, Banknote, Receipt, ReceiptText, ArrowRight,
  AlertTriangle, CheckCircle2, Circle, Clock,
} from 'lucide-react'
import { hrApi } from '@/services/hrApi'
import { HrLoading } from '@/components/ui/HrState'
import { GRAD } from '@/components/ui/brand'

/*  ────────────────────────────────────────────────────────────────────────
    The payroll landing screen.

    The module opened on Salary Components — a master data table — which is
    the one screen nobody needs on a Tuesday. What somebody opening payroll
    actually wants to know is: which month is in flight, how far through it is,
    what it will cost, and is anything blocking it.

    Everything here is READ from the latest run. The hub computes nothing and
    decides nothing; it is a view onto state the server already holds, so it
    cannot disagree with the screen that does the work.
    ──────────────────────────────────────────────────────────────────────── */

const money = v => v === null || v === undefined || v === ''
  ? '—'
  : `₹${Number(v).toLocaleString('en-IN', { maximumFractionDigits: 0 })}`

const STAGES = ['Pre-check', 'Inputs', 'Calculate', 'Approve', 'Disburse']

const stageIndex = (stage) => {
  if (stage === 'Paid') return 5
  const i = STAGES.indexOf(stage)
  return i < 0 ? 0 : i
}

export default function PayrollHub({ onOpenRun, onGoTo, showToast }) {
  const [runs, setRuns] = useState([])
  const [detail, setDetail] = useState(null)
  const [loading, setLoading] = useState(true)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const list = await hrApi.payroll.runs.list()
      setRuns(list)
      // The run in flight, else the most recent one.
      const live = list.find(r => r.stage && r.stage !== 'Paid' && r.status !== 'Cancelled') || list[0]
      setDetail(live ? await hrApi.payroll.runs.get(live.id) : null)
    } catch {
      showToast?.('Could not load payroll', 'error')
    } finally { setLoading(false) }
  }, [showToast])

  useEffect(() => { load() }, [load])

  if (loading) return <HrLoading label="Loading payroll…" />

  const at = detail ? stageIndex(detail.stage) : 0
  const done = detail?.stage === 'Paid'

  return (
    <div className="space-y-4">
      {/* ── The cycle in flight ─────────────────────────────────────── */}
      {detail ? (
        <div className="card-3d" style={{ padding: 20 }}>
          <div className="flex items-start justify-between gap-4 flex-wrap">
            <div style={{ minWidth: 240 }}>
              <p className="label-caps mb-1">Current cycle</p>
              <h2 className="font-black" style={{ fontSize: '1.5rem', color: 'var(--text-h)' }}>
                {done ? 'Payroll paid' : 'Payroll in progress'}
              </h2>
              <p className="text-[11px] mt-1" style={{ color: 'var(--text-muted)' }}>
                {detail.period_label} · Step {Math.min(at + 1, 5)} of 5 · {done ? 'Complete' : (STAGES[at] || 'Pre-check')}
                {detail.total_employees ? ` · ${detail.total_employees} employee(s)` : ''}
              </p>
            </div>
            <div className="text-right">
              <p className="label-caps mb-1">Net payout</p>
              <p className="font-black" style={{ fontSize: '1.7rem', color: '#0ea5e9' }}>{money(detail.total_payable)}</p>
              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>
                Gross {money(detail.total_gross)} · Deductions {money(detail.total_deductions)}
              </p>
            </div>
          </div>

          {/* Stage rail */}
          <div className="flex items-center gap-1 mt-4 overflow-x-auto pb-1">
            {STAGES.map((s, i) => (
              <div key={s} className="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg whitespace-nowrap"
                style={{ background: i === at && !done ? 'rgba(124,58,237,0.12)' : 'transparent' }}>
                {i < at || done
                  ? <CheckCircle2 size={13} style={{ color: '#10b981', flexShrink: 0 }} />
                  : <Circle size={13} style={{ color: i === at ? '#7C3AED' : 'var(--text-muted)', flexShrink: 0 }} />}
                <span className="text-[10px] font-bold"
                  style={{ color: i <= at ? 'var(--text-h)' : 'var(--text-muted)' }}>{s}</span>
              </div>
            ))}
          </div>

          {detail.statutory?.unresolved_work_state > 0 && (
            <div className="rounded-xl p-2.5 mt-3 flex items-start gap-2"
              style={{ background: 'rgba(245,158,11,0.08)', border: '1px dashed rgba(245,158,11,0.4)' }}>
              <AlertTriangle size={14} style={{ color: '#f59e0b', marginTop: 1, flexShrink: 0 }} />
              <p className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
                <b style={{ color: 'var(--text-h)' }}>{detail.statutory.unresolved_work_state} employee(s) have no work state.</b>{' '}
                Professional Tax computes as zero for them — which reads as &ldquo;no PT due&rdquo; and actually means
                &ldquo;no PT calculated&rdquo;. Set it under Compliance.
              </p>
            </div>
          )}

          <button onClick={() => onOpenRun?.(detail)}
            className="mt-4 px-4 py-2.5 rounded-xl text-sm font-bold text-white inline-flex items-center gap-2"
            style={{ background: GRAD }}>
            {done ? 'Open run' : 'Continue run'} <ArrowRight size={14} />
          </button>
        </div>
      ) : (
        <div className="card-3d text-center" style={{ padding: '36px 20px' }}>
          <PlayCircle size={30} style={{ color: '#a78bfa', margin: '0 auto 10px' }} />
          <p className="text-sm font-black" style={{ color: 'var(--text-h)' }}>No payroll run yet</p>
          <p className="text-[11px] mt-1 mb-4" style={{ color: 'var(--text-muted)' }}>
            Start one and the pre-check will show who can be paid and what is missing for the rest.
          </p>
          <button onClick={() => onGoTo?.('run')}
            className="px-5 py-2.5 rounded-xl text-sm font-bold text-white" style={{ background: GRAD }}>
            Go to Run Payroll
          </button>
        </div>
      )}

      {/* ── Headline figures ────────────────────────────────────────── */}
      {/* Empty is drawn as empty. A large accent-coloured em-dash reads as a
          broken value rather than an absent one, so with no run the figures go
          muted and say so. */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-3">
        <Tile label="Employees" value={detail?.total_employees} colour="#7C3AED" icon={Users} empty={!detail} />
        <Tile label="Gross pay" value={detail && money(detail.total_gross)} colour="#10b981" icon={Banknote} small empty={!detail} />
        <Tile label="Statutory" value={detail && money(detail.statutory?.total_deductions)} colour="#f87171" icon={Receipt} small empty={!detail} />
        <Tile label="Payslips released" value={detail?.payments?.released} colour="#0ea5e9" icon={ReceiptText} empty={!detail} />
      </div>

      {/* ── Where to go next ────────────────────────────────────────── */}
      <div className="card-3d" style={{ padding: 16 }}>
        <p className="label-caps mb-3">Quick actions</p>
        <div className="grid grid-cols-2 md:grid-cols-4 gap-2.5">
          {[
            ['run', PlayCircle, 'Run payroll', 'Pre-check to payout'],
            ['people', Users, 'People & salaries', 'Assign and revise'],
            ['payout', ReceiptText, 'Payout', 'Payslips and bank advice'],
            ['compliance', Receipt, 'Compliance', 'PF, ESIC, PT, LWF'],
          ].map(([key, Icon, label, hint]) => (
            <button key={key} onClick={() => onGoTo?.(key)}
              className="text-left rounded-xl p-3" style={{ background: 'var(--bg-input)' }}>
              <Icon size={16} style={{ color: '#a78bfa' }} />
              <p className="text-[12px] font-bold mt-1.5" style={{ color: 'var(--text-h)' }}>{label}</p>
              <p className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{hint}</p>
            </button>
          ))}
        </div>
      </div>

      {/* ── Recent runs ─────────────────────────────────────────────── */}
      {runs.length > 0 && (
        <div className="card-3d" style={{ padding: 16 }}>
          <p className="label-caps mb-3">Recent runs</p>
          <div className="space-y-1.5">
            {runs.slice(0, 5).map(r => (
              <button key={r.id} onClick={() => onOpenRun?.(r)}
                className="w-full flex items-center justify-between gap-3 rounded-xl p-2.5 text-left"
                style={{ background: 'var(--bg-input)' }}>
                <span className="flex items-center gap-2" style={{ minWidth: 0 }}>
                  <Clock size={13} style={{ color: 'var(--text-muted)', flexShrink: 0 }} />
                  <span className="text-[12px] font-bold" style={{ color: 'var(--text-h)' }}>{r.period_label}</span>
                  <span className="text-[10px] font-bold px-2 py-0.5 rounded-lg"
                    style={{ background: 'rgba(124,58,237,0.1)', color: '#a78bfa' }}>{r.stage || 'Pre-check'}</span>
                </span>
                <span className="text-[12px] font-black" style={{ color: '#0ea5e9' }}>{money(r.total_payable)}</span>
              </button>
            ))}
          </div>
        </div>
      )}
    </div>
  )
}

function Tile({ label, value, colour, icon: Icon, small, empty }) {
  const shown = empty || value === null || value === undefined ? 'No data' : value
  const muted = shown === 'No data'

  return (
    <div className="kpi-3d">
      <Icon size={15} style={{ color: muted ? 'var(--text-muted)' : colour, marginBottom: 4, opacity: muted ? 0.5 : 1 }} />
      <p className={muted ? 'text-sm font-bold' : (small ? 'text-xl font-black' : 'text-3xl font-black')}
        style={{ color: muted ? 'var(--text-muted)' : colour }}>{shown}</p>
      <p className="text-xs font-medium mt-1" style={{ color: 'var(--text-muted)' }}>{label}</p>
    </div>
  )
}

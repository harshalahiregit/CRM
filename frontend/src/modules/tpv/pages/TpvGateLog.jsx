import { useState, useEffect, useCallback } from 'react'
import {
  RefreshCw, Users, ScanLine, CheckCircle, AlertTriangle, XCircle, Clock, LogIn, LogOut,
} from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { GATE_DECISION, GATE_DECISION_CONFIG, gateDecisionCfg, fmtTime, fmtDateTime } from '../constants'
import { KIT3D_STYLE, inputStyle, StatusBadge as StatusPill } from '@/components/ui/kit3d'

const TABS = [
  { key: 'roster', label: 'On Site Today', icon: Users },
  { key: 'log',    label: 'Scan Log',      icon: ScanLine },
]

export default function TpvGateLog() {
  const [tab, setTab]       = useState('roster')
  const [stats, setStats]   = useState({})
  const [roster, setRoster] = useState(null)
  const [log, setLog]       = useState([])
  const [loading, setLoad]  = useState(true)
  const [date, setDate]     = useState(new Date().toISOString().slice(0, 10))
  const [decision, setDecision] = useState('All')

  const fetchAll = useCallback(async () => {
    setLoad(true)
    try {
      const [s, r, l] = await Promise.all([
        tpvApi.gate.stats(),
        tpvApi.gate.roster(date),
        tpvApi.gate.log({ date, decision: decision === 'All' ? undefined : decision }),
      ])
      setStats(s?.data ?? s ?? {})
      setRoster(r?.data ?? r ?? null)
      setLog(Array.isArray(l?.data ?? l) ? (l.data ?? l) : [])
    } catch (e) { console.error('Failed to load gate data', e) }
    finally { setLoad(false) }
  }, [date, decision])
  useEffect(() => { fetchAll() }, [fetchAll])

  const statCards = [
    { label: 'On Site Now',      value: stats.on_site_now,      color: '#10b981' },
    { label: 'Checked In Today', value: stats.checked_in_today,  color: '#0ea5e9' },
    { label: 'Scans Today',      value: stats.scans_today,       color: '#7C3AED' },
    { label: 'Refused Today',    value: stats.denied_today,      color: '#ef4444' },
  ]

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Gate Log</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Site attendance and every badge presented at the gate.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <input type="date" value={date} onChange={e => setDate(e.target.value)} style={{ ...inputStyle, width: 'auto' }} />
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(4,1fr)', gap: 12, marginBottom: 20 }}>
        {statCards.map(s => (
          <div key={s.label} className="pr-kpi" style={{ textAlign: 'center', cursor: 'default' }}>
            <div style={{ fontSize: 24, fontWeight: 900, color: s.color }}>{s.value || 0}</div>
            <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 600, marginTop: 3 }}>{s.label}</div>
          </div>
        ))}
      </div>

      {/* Tabs */}
      <div style={{ display: 'flex', gap: 8, marginBottom: 16 }}>
        {TABS.map(t => {
          const on = tab === t.key
          return (
            <button key={t.key} onClick={() => setTab(t.key)}
              style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '8px 16px', borderRadius: 10, fontSize: 13, fontWeight: 700, cursor: 'pointer',
                border: `1px solid ${on ? 'rgba(124,58,237,0.5)' : 'var(--border)'}`,
                background: on ? 'rgba(124,58,237,0.15)' : 'var(--bg-card)',
                color: on ? '#a78bfa' : 'var(--text-muted)' }}>
              <t.icon size={14} /> {t.label}
            </button>
          )
        })}
        {tab === 'log' && (
          <select value={decision} onChange={e => setDecision(e.target.value)} style={{ ...inputStyle, width: 'auto', marginLeft: 'auto', cursor: 'pointer' }}>
            <option value="All">All decisions</option>
            {Object.values(GATE_DECISION).map(d => <option key={d} value={d}>{GATE_DECISION_CONFIG[d].label}</option>)}
          </select>
        )}
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading gate data…</div>
      ) : tab === 'roster' ? <Roster roster={roster} /> : <ScanLog rows={log} />}
    </div>
  )
}

// ── Attendance roster ────────────────────────────────────────────────────────
function Roster({ roster }) {
  const rows = roster?.rows || []
  const s = roster?.summary || {}
  if (rows.length === 0) {
    return <Empty icon={Users} title="Nobody on site" hint="No worker has checked in on this date." />
  }
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <div style={{ display: 'flex', gap: 16, padding: '12px 16px', borderBottom: '1px solid var(--border)', fontSize: 12 }}>
        <span style={{ color: 'var(--text-muted)' }}>Total <strong style={{ color: 'var(--text-h)' }}>{s.total || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>On site <strong style={{ color: '#10b981' }}>{s.on_site || 0}</strong></span>
        <span style={{ color: 'var(--text-muted)' }}>Departed <strong style={{ color: 'var(--text-h)' }}>{s.departed || 0}</strong></span>
        {/* Only completed sessions carry minutes — a worker still on site has no
            recorded duration until they scan out, so don't call this "total". */}
        <span style={{ marginLeft: 'auto', color: 'var(--text-muted)' }} title="Sum of completed sessions — workers still on site are not counted until they check out">
          Completed time <strong style={{ color: 'var(--text-h)' }}>{Math.floor((s.total_minutes || 0) / 60)}h {(s.total_minutes || 0) % 60}m</strong>
        </span>
      </div>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>{['Worker', 'Vendor', 'In', 'Out', 'Duration', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.map(r => (
            <tr key={r.id} className="pr-li-row">
              <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                {r.worker?.name}
                <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11, marginLeft: 7 }}>{r.worker?.worker_code}</span>
                {r.worker?.designation && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.worker.designation}</div>}
              </td>
              <td style={{ ...td, color: 'var(--text-muted)' }}>{r.worker?.vendor?.company_name || '—'}</td>
              <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogIn size={12} style={{ color: '#10b981' }} /> {fmtTime(r.check_in_at)}</span>
                {r.check_in_gate && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 500 }}>{r.check_in_gate}</div>}
              </td>
              <td style={{ ...td, color: r.check_out_at ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600 }}>
                {r.check_out_at
                  ? <><span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogOut size={12} style={{ color: '#f59e0b' }} /> {fmtTime(r.check_out_at)}</span>
                      {r.check_out_gate && <div style={{ fontSize: 10.5, color: 'var(--text-muted)', fontWeight: 500 }}>{r.check_out_gate}</div>}</>
                  : '—'}
              </td>
              <td style={{ ...td, color: 'var(--text-muted)' }}>{r.duration_label || '—'}</td>
              <td style={td}>
                {r.on_site
                  ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'rgba(16,185,129,0.15)', color: '#10b981', border: '1px solid rgba(16,185,129,0.4)' }}>
                      <Clock size={10} /> On site
                    </span>
                  : <span style={{ padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Departed</span>}
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  )
}

// ── Scan log ─────────────────────────────────────────────────────────────────
const ACTION_LABEL = { scan: 'Verify', check_in: 'Check In', check_out: 'Check Out' }

function ScanLog({ rows }) {
  if (rows.length === 0) {
    return <Empty icon={ScanLine} title="No scans recorded" hint="Badges presented at the gate appear here — admitted and refused." />
  }
  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }
  const ICONS = { [GATE_DECISION.ADMIT]: CheckCircle, [GATE_DECISION.WARN]: AlertTriangle, [GATE_DECISION.DENY]: XCircle }

  return (
    <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
      <table style={{ width: '100%', borderCollapse: 'collapse' }}>
        <thead><tr>{['Time', 'Worker', 'Action', 'Decision', 'Reason', 'Gate'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
        <tbody>
          {rows.map(r => {
            const cfg = gateDecisionCfg(r.decision)
            const Icon = ICONS[r.decision] || XCircle
            return (
              <tr key={r.id} className="pr-li-row">
                <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap', fontVariantNumeric: 'tabular-nums' }}>{fmtDateTime(r.scanned_at)}</td>
                <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)', whiteSpace: 'nowrap' }}>
                  {r.worker?.name || '—'}
                  <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11, marginLeft: 7 }}>{r.worker?.worker_code}</span>
                </td>
                <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{ACTION_LABEL[r.action] || r.action}</td>
                <td style={td}>
                  <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: cfg.bg, color: cfg.color, border: `1px solid ${cfg.color}44`, whiteSpace: 'nowrap' }}>
                    <Icon size={11} /> {cfg.label}
                  </span>
                </td>
                <td style={{ ...td, color: r.decision === GATE_DECISION.DENY ? '#ef4444' : 'var(--text-muted)', maxWidth: 320 }}>
                  {(r.reasons || []).join(' ') || '—'}
                </td>
                <td style={{ ...td, color: 'var(--text-muted)', whiteSpace: 'nowrap' }}>{r.gate || '—'}</td>
              </tr>
            )
          })}
        </tbody>
      </table>
      <div style={{ padding: '10px 14px', borderTop: '1px solid var(--border)', fontSize: 11.5, color: 'var(--text-muted)' }}>
        Showing the {rows.length} most recent scans. The log records every badge presented and the gate's verdict;
        actual entry/exit is the roster.
      </div>
    </div>
  )
}

const Empty = ({ icon: Icon, title, hint }) => (
  <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
    <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
      <Icon size={26} color="#fff" />
    </div>
    <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>{title}</h3>
    <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>{hint}</p>
  </div>
)

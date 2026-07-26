import { useState, useEffect, useCallback } from 'react'
import { useParams } from 'react-router-dom'
import { RefreshCw, Users, Clock, LogIn, LogOut } from 'lucide-react'
import { tpvApi } from '@/services/tpvApi'
import { portalApi } from '@/services/portalApi'
import { useAuth } from '@/context/AuthContext'
import { KIT3D_STYLE, inputStyle } from '@/components/ui/kit3d'
import { fmtTime } from '../constants'

// Vendor-scoped daily attendance. gate.roster is tenant-wide, so we filter its
// rows client-side to this vendor's worker set (matched by worker_code). Global
// gate roster/log behaviour elsewhere is untouched.
export default function WorkforceAttendance() {
  const [codes, setCodes]   = useState(null)   // Set<worker_code> | null
  const [roster, setRoster] = useState(null)
  const [loading, setLoad]  = useState(true)
  const [date, setDate]     = useState(new Date().toISOString().slice(0, 10))
  const { vendorId } = useParams()
  const { user } = useAuth()
  const isPortal = user?.role === 'third_party_vendor'
  const api = isPortal ? portalApi : tpvApi

  const fetchAll = useCallback(async () => {
    setLoad(true)
    try {
      const [wRes, rRes] = await Promise.all([
        isPortal ? api.workers.list({}) : api.workers.list({ vendor_id: vendorId }),
        api.gate.roster(date),
      ])
      const workers = Array.isArray(wRes?.data ?? wRes) ? (wRes.data ?? wRes) : []
      setCodes(new Set(workers.map(w => w.worker_code)))
      setRoster(rRes?.data ?? rRes ?? null)
    } catch (e) { console.error('Failed to load attendance', e) }
    finally { setLoad(false) }
  }, [vendorId, date, isPortal, api])
  useEffect(() => { fetchAll() }, [fetchAll])

  const rows = (roster?.rows || []).filter(r => codes?.has(r.worker?.worker_code))
  const onSite   = rows.filter(r => r.on_site).length
  const departed = rows.length - onSite

  const th = { textAlign: 'left', padding: '10px 12px', fontSize: 10.5, fontWeight: 800, color: 'var(--text-muted)', textTransform: 'uppercase', letterSpacing: '0.04em', whiteSpace: 'nowrap' }
  const td = { padding: '11px 12px', borderBottom: '1px solid var(--border)', fontSize: 12.5, verticalAlign: 'middle' }

  return (
    <div style={{ padding: 24, minHeight: '100vh', background: 'var(--bg-global)' }}>
      <style>{KIT3D_STYLE}</style>

      <div style={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', marginBottom: 16, flexWrap: 'wrap', gap: 12 }}>
        <div>
          <h1 style={{ color: 'var(--text-h)', fontSize: 22, fontWeight: 800, margin: 0 }}>Attendance</h1>
          <p style={{ color: 'var(--text-muted)', fontSize: 12.5, margin: '4px 0 0' }}>Daily site attendance for this vendor's workforce.</p>
        </div>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center' }}>
          <input type="date" value={date} onChange={e => setDate(e.target.value)} style={{ ...inputStyle, width: 'auto' }} />
          <button onClick={fetchAll} style={{ display: 'flex', alignItems: 'center', gap: 6, padding: '8px 14px', borderRadius: 10, border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer', fontSize: 13 }}>
            <RefreshCw size={14} /> Refresh
          </button>
        </div>
      </div>

      {loading ? (
        <div style={{ textAlign: 'center', padding: 60, color: 'var(--text-muted)' }}>Loading attendance…</div>
      ) : rows.length === 0 ? (
        <div className="pr-glass" style={{ padding: 60, textAlign: 'center' }}>
          <div style={{ width: 60, height: 60, borderRadius: 18, margin: '0 auto 16px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: 'linear-gradient(145deg,#7C3AED,#5b21b6)', boxShadow: '0 10px 24px -6px rgba(124,58,237,.6)' }}>
            <Users size={26} color="#fff" />
          </div>
          <h3 style={{ color: 'var(--text-h)', fontSize: 16, fontWeight: 800, margin: '0 0 6px' }}>Nobody on site</h3>
          <p style={{ color: 'var(--text-muted)', fontSize: 13, margin: 0 }}>No worker from this vendor checked in on this date.</p>
        </div>
      ) : (
        <div className="pr-glass" style={{ padding: 0, overflow: 'hidden' }}>
          <div style={{ display: 'flex', gap: 16, padding: '12px 16px', borderBottom: '1px solid var(--border)', fontSize: 12 }}>
            <span style={{ color: 'var(--text-muted)' }}>Total <strong style={{ color: 'var(--text-h)' }}>{rows.length}</strong></span>
            <span style={{ color: 'var(--text-muted)' }}>On site <strong style={{ color: '#10b981' }}>{onSite}</strong></span>
            <span style={{ color: 'var(--text-muted)' }}>Departed <strong style={{ color: 'var(--text-h)' }}>{departed}</strong></span>
          </div>
          <table style={{ width: '100%', borderCollapse: 'collapse' }}>
            <thead><tr>{['Worker', 'In', 'Out', 'Duration', 'Status'].map(h => <th key={h} style={th}>{h}</th>)}</tr></thead>
            <tbody>
              {rows.map(r => (
                <tr key={r.id} className="pr-li-row">
                  <td style={{ ...td, fontWeight: 700, color: 'var(--text-h)' }}>
                    {r.worker?.name}
                    <span style={{ color: '#a78bfa', fontWeight: 700, fontSize: 11, marginLeft: 7 }}>{r.worker?.worker_code}</span>
                    {r.worker?.designation && <div style={{ fontSize: 11, color: 'var(--text-muted)', fontWeight: 500 }}>{r.worker.designation}</div>}
                  </td>
                  <td style={{ ...td, color: 'var(--text-h)', fontWeight: 600 }}>
                    <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogIn size={12} style={{ color: '#10b981' }} /> {fmtTime(r.check_in_at)}</span>
                  </td>
                  <td style={{ ...td, color: r.check_out_at ? 'var(--text-h)' : 'var(--text-muted)', fontWeight: 600 }}>
                    {r.check_out_at
                      ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5 }}><LogOut size={12} style={{ color: '#f59e0b' }} /> {fmtTime(r.check_out_at)}</span>
                      : '—'}
                  </td>
                  <td style={{ ...td, color: 'var(--text-muted)' }}>{r.duration_label || '—'}</td>
                  <td style={td}>
                    {r.on_site
                      ? <span style={{ display: 'inline-flex', alignItems: 'center', gap: 5, padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'rgba(16,185,129,0.15)', color: '#10b981', border: '1px solid rgba(16,185,129,0.4)' }}><Clock size={10} /> On site</span>
                      : <span style={{ padding: '3px 10px', borderRadius: 20, fontSize: 11, fontWeight: 700, background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Departed</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

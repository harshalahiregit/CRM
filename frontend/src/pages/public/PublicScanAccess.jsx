import { useState, useEffect } from 'react'
import { useParams } from 'react-router-dom'
import { tpvApi } from '@/services/tpvApi'
import { ShieldCheck, ShieldAlert, AlertTriangle, XCircle, User, Building2 } from 'lucide-react'

export default function PublicScanAccess() {
  const { token } = useParams()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    if (!token) return
    tpvApi.workers.scanAccess(token)
      .then(res => setData(res))
      .catch(() => setData({ found: false, message: 'Server communication error.' }))
      .finally(() => setLoading(false))
  }, [token])

  if (loading) {
    return (
      <div style={bgWrap}>
        <div style={cardBox}>
          <div style={{ fontSize: 36, color: '#a78bfa', animation: 'spin 1s linear infinite', margin: '0 auto 16px' }}>⏳</div>
          <p style={{ color: 'rgba(255,255,255,0.7)', fontSize: 14 }}>Verifying HSSE Pass Token...</p>
        </div>
      </div>
    )
  }

  if (!data?.found) {
    return (
      <div style={bgWrap}>
        <div style={cardBox}>
          <div style={{ fontSize: 56, marginBottom: 16 }}>❓</div>
          <h2 style={{ color: 'rgba(255,255,255,0.9)', fontSize: 24, fontWeight: 800, margin: '0 0 8px' }}>Invalid QR Code</h2>
          <p style={{ color: 'rgba(255,255,255,0.5)', fontSize: 13, margin: '0 0 20px' }}>
            This QR code is not linked to any registered worker in the platform.
          </p>
          <span style={{ ...pillStyle, background: 'rgba(255,255,255,0.08)', color: 'rgba(255,255,255,0.5)', border: '1px solid rgba(255,255,255,0.15)' }}>
            UNRECOGNISED
          </span>
        </div>
      </div>
    )
  }

  const { worker, state_label, state_color, is_terminated, scanned_at } = data
  const pc = worker.punch_count || 0

  return (
    <div style={bgWrap}>
      <div style={cardBox}>
        {/* State Icon & Banner */}
        {is_terminated ? (
          <>
            <div style={pulseRedRing}>
              <XCircle size={44} color="#ef4444" />
            </div>
            <h2 style={{ color: '#ef4444', fontSize: 26, fontWeight: 900, margin: '0 0 6px' }}>Access Restricted</h2>
            <p style={{ color: '#fca5a5', fontSize: 13, margin: '0 0 18px', lineHeight: 1.5 }}>
              This worker's site access has been<br />
              <strong style={{ color: '#ef4444' }}>permanently revoked due to 3rd Punch Violation.</strong>
            </p>
            <span style={{ ...pillStyle, background: 'rgba(239,68,68,0.18)', color: '#ef4444', border: '1px solid #ef4444' }}>
              PERMANENTLY BLOCKED
            </span>
          </>
        ) : (
          <>
            <div style={{ fontSize: 54, marginBottom: 12 }}>
              {pc === 0 ? '✅' : pc === 1 ? '⚠️' : '🚨'}
            </div>
            <h2 style={{ color: pc === 0 ? '#10b981' : pc === 1 ? '#f59e0b' : '#f97316', fontSize: 24, fontWeight: 900, margin: '0 0 4px' }}>
              {pc === 0 ? 'Access Verified' : pc === 1 ? 'Warning Active' : 'Final Warning'}
            </h2>
            <p style={{ color: 'rgba(255,255,255,0.85)', fontSize: 15, fontWeight: 700, margin: '0 0 16px' }}>
              {worker.name}
            </p>
            <span style={{
              ...pillStyle,
              background: pc === 0 ? 'rgba(16,185,129,0.16)' : pc === 1 ? 'rgba(245,158,11,0.16)' : 'rgba(249,115,22,0.16)',
              color: pc === 0 ? '#10b981' : pc === 1 ? '#f59e0b' : '#f97316',
              border: `1px solid ${pc === 0 ? '#10b981' : pc === 1 ? '#f59e0b' : '#f97316'}`
            }}>
              {state_label}
            </span>
          </>
        )}

        {/* 3 Punch Dots */}
        <div style={{ display: 'flex', gap: 12, justifyContent: 'center', margin: '20px 0' }}>
          {[1, 2, 3].map(n => {
            const used = n <= pc
            let dotBg = 'rgba(255,255,255,0.06)'
            let dotBorder = 'rgba(255,255,255,0.2)'
            let dotColor = 'rgba(255,255,255,0.3)'
            if (used) {
              if (n === 1) { dotBg = '#f59e0b'; dotBorder = '#f59e0b'; dotColor = '#000' }
              if (n === 2) { dotBg = '#f97316'; dotBorder = '#f97316'; dotColor = '#fff' }
              if (n === 3) { dotBg = '#ef4444'; dotBorder = '#ef4444'; dotColor = '#fff' }
            }
            return (
              <div key={n} style={{
                width: 34, height: 34, borderRadius: 999, background: dotBg, border: `2px solid ${dotBorder}`,
                color: dotColor, display: 'flex', alignItems: 'center', justifyContent: 'center',
                fontWeight: 900, fontSize: 13, boxShadow: used ? `0 0 12px ${dotBg}` : 'none'
              }}>
                {used ? (n === 3 ? '✕' : '!') : n}
              </div>
            )
          })}
        </div>

        {/* Details Table */}
        <table style={{ width: '100%', borderCollapse: 'collapse', textAlign: 'left', marginTop: 12 }}>
          <tbody>
            <tr style={rowBorder}>
              <td style={lblCol}>Worker Code</td>
              <td style={valCol}><strong>{worker.worker_code}</strong></td>
            </tr>
            <tr style={rowBorder}>
              <td style={lblCol}>Full Name</td>
              <td style={valCol}>{worker.name}</td>
            </tr>
            <tr style={rowBorder}>
              <td style={lblCol}>Designation</td>
              <td style={valCol}>{worker.designation || '—'}</td>
            </tr>
            <tr style={rowBorder}>
              <td style={lblCol}>Blood Group</td>
              <td style={valCol}>{worker.blood_group || '—'}</td>
            </tr>
            <tr style={rowBorder}>
              <td style={lblCol}>Employing Vendor</td>
              <td style={valCol}>{worker.company_name} ({worker.vendor_code})</td>
            </tr>
            <tr>
              <td style={lblCol}>Punch Count</td>
              <td style={{ ...valCol, color: pc >= 3 ? '#ef4444' : pc === 2 ? '#f97316' : pc === 1 ? '#f59e0b' : '#10b981', fontWeight: 900 }}>
                {pc} / 3 {is_terminated ? '— BANNED' : ''}
              </td>
            </tr>
          </tbody>
        </table>

        {/* Audit Punch Log */}
        {worker.punch_log && worker.punch_log.length > 0 && (
          <div style={{ marginTop: 20, textAlign: 'left', background: 'rgba(0,0,0,0.25)', padding: 12, borderRadius: 10, border: '1px solid rgba(255,255,255,0.08)' }}>
            <h4 style={{ margin: '0 0 8px', fontSize: 11, fontWeight: 800, color: 'rgba(255,255,255,0.6)', textTransform: 'uppercase', letterSpacing: '0.04em' }}>Punch Violation Log</h4>
            {worker.punch_log.map((pl, idx) => (
              <div key={idx} style={{ fontSize: 11.5, color: 'rgba(255,255,255,0.8)', marginBottom: 4, paddingBottom: 4, borderBottom: '1px dashed rgba(255,255,255,0.1)' }}>
                <strong style={{ color: pl.num === 3 ? '#ef4444' : '#f59e0b' }}>Punch #{pl.num}:</strong> {pl.reason} <span style={{ opacity: 0.6 }}>({pl.at})</span>
              </div>
            ))}
          </div>
        )}

        <div style={{ fontSize: 10.5, color: 'rgba(255,255,255,0.3)', marginTop: 24, letterSpacing: '0.04em' }}>
          Scanned: {scanned_at}
        </div>
      </div>
    </div>
  )
}

const bgWrap = {
  minHeight: '100vh', background: 'linear-gradient(145deg, #07101a 0%, #0f1e2e 50%, #09141f 100%)',
  display: 'flex', alignItems: 'center', justifyContent: 'center', padding: 20, fontFamily: 'Inter, system-ui, sans-serif'
}

const cardBox = {
  background: 'rgba(255,255,255,0.04)', border: '1px solid rgba(255,255,255,0.09)',
  borderRadius: 24, padding: '36px 28px 28px', textAlign: 'center', maxWidth: 380, width: '100%',
  boxShadow: '0 30px 80px rgba(0,0,0,0.55)', backdropFilter: 'blur(12px)'
}

const pillStyle = {
  display: 'inline-block', padding: '6px 22px', borderRadius: 999,
  fontSize: 10.5, fontWeight: 900, letterSpacing: '0.15em', textTransform: 'uppercase'
}

const pulseRedRing = {
  width: 76, height: 76, borderRadius: 999, border: '4px solid #ef4444',
  display: 'flex', alignItems: 'center', justifyContent: 'center', margin: '0 auto 16px',
  boxShadow: '0 0 30px rgba(239,68,68,0.45)'
}

const rowBorder = { borderBottom: '1px solid rgba(255,255,255,0.06)' }
const lblCol = { padding: '8px 0', fontSize: 12.5, color: 'rgba(255,255,255,0.4)', width: '42%' }
const valCol = { padding: '8px 0', fontSize: 13, color: '#fff', fontWeight: 600 }

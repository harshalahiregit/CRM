import { useState } from 'react'
import { Rocket, X, UserCircle, FileUp, ShieldCheck, ArrowRight } from 'lucide-react'

/**
 * First-login getting-started guide for a newly-registered vendor. Dismissible,
 * with the dismissal remembered per-browser (localStorage) so it doesn't nag after
 * the vendor has read it. The caller decides when it's relevant (typically while
 * the account is not yet Active). Purely a self-service hint — no admin actions.
 */
const STEPS = [
  { icon: UserCircle, title: 'Complete your company profile', desc: 'Fill in your company, contact and address details.', to: '/vendor-portal/registration' },
  { icon: FileUp, title: 'Submit your required documents', desc: 'Upload the compliance documents your account team needs.', to: '/vendor-portal/documents' },
  { icon: ShieldCheck, title: 'Track approval here', desc: 'Watch your registration status — you’ll be activated once approved.', to: null },
]

export default function GettingStartedGuide({ vendorName, storageKey = 'vp_getting_started', onGo }) {
  const key = `${storageKey}_dismissed`
  const [dismissed, setDismissed] = useState(() => {
    try { return localStorage.getItem(key) === '1' } catch { return false }
  })
  if (dismissed) return null

  const dismiss = () => {
    try { localStorage.setItem(key, '1') } catch { /* private mode — just hide for this session */ }
    setDismissed(true)
  }

  return (
    <div style={{
      position: 'relative', marginBottom: 24, borderRadius: 16, padding: '18px 20px',
      background: 'linear-gradient(135deg, rgba(124,58,237,0.12), rgba(14,165,233,0.10))',
      border: '1px solid var(--border)',
    }}>
      <button onClick={dismiss} title="Dismiss" style={{
        position: 'absolute', top: 12, right: 12, display: 'inline-flex', padding: 5, borderRadius: 8,
        border: '1px solid var(--border)', background: 'var(--bg-card)', color: 'var(--text-muted)', cursor: 'pointer',
      }}><X size={14} /></button>

      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 4 }}>
        <span style={{ display: 'inline-flex', padding: 8, borderRadius: 10, background: 'rgba(124,58,237,0.18)' }}>
          <Rocket size={16} style={{ color: '#a78bfa' }} />
        </span>
        <h3 style={{ margin: 0, fontSize: 15, fontWeight: 800, color: 'var(--text-h)' }}>
          Welcome{vendorName ? `, ${vendorName}` : ''} — let’s get you set up
        </h3>
      </div>
      <p style={{ margin: '0 0 14px 0', fontSize: 12.5, color: 'var(--text-muted)' }}>
        A few quick steps to get your account ready for activation.
      </p>

      <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit,minmax(220px,1fr))', gap: 12 }}>
        {STEPS.map((s, i) => {
          const Icon = s.icon
          const clickable = s.to && onGo
          return (
            <div
              key={i}
              onClick={clickable ? () => onGo(s.to) : undefined}
              style={{
                display: 'flex', gap: 10, padding: 12, borderRadius: 12,
                background: 'var(--bg-card)', border: '1px solid var(--border)',
                cursor: clickable ? 'pointer' : 'default',
              }}
            >
              <span style={{ display: 'inline-flex', alignItems: 'flex-start' }}>
                <span style={{
                  width: 22, height: 22, borderRadius: 999, display: 'inline-flex', alignItems: 'center', justifyContent: 'center',
                  background: 'rgba(124,58,237,0.14)', color: '#a78bfa', fontSize: 11, fontWeight: 800,
                }}>{i + 1}</span>
              </span>
              <div style={{ minWidth: 0 }}>
                <div style={{ display: 'flex', alignItems: 'center', gap: 6, color: 'var(--text-h)', fontWeight: 700, fontSize: 12.5 }}>
                  <Icon size={13} style={{ color: '#a78bfa' }} /> {s.title}
                  {clickable && <ArrowRight size={12} style={{ color: 'var(--text-muted)', marginLeft: 'auto' }} />}
                </div>
                <div style={{ fontSize: 11.5, color: 'var(--text-muted)', marginTop: 3 }}>{s.desc}</div>
              </div>
            </div>
          )
        })}
      </div>
    </div>
  )
}

import { useEffect, useState } from 'react'
import { Loader2, FolderKanban, ListChecks, LifeBuoy, Building2 } from 'lucide-react'
import { portalApi } from '@/services/portalApi'

/**
 * TPV portal — General › Overview. A quick at-a-glance for the vendor: account
 * status, key work counts, and the company header. Read-only; the deeper pages
 * live behind the nav.
 */
const STATUS_TONE = { active: '#22c55e', pending_approval: '#f59e0b', on_hold: '#f59e0b', inactive: '#94a3b8', rejected: '#ef4444', blacklisted: '#ef4444', draft: '#94a3b8' }

export default function MyOverview() {
  const [vendor, setVendor] = useState(null)
  const [summary, setSummary] = useState(null)
  useEffect(() => {
    portalApi.vendors.get().then(setVendor).catch(() => setVendor(null))
    portalApi.myWork.summary().then(setSummary).catch(() => setSummary({}))
  }, [])

  if (vendor === null && summary === null) return <div style={{ display: 'flex', justifyContent: 'center', padding: 60 }}><Loader2 className="ov-spin" size={22} /></div>

  const status = vendor?.status || '—'
  const tone = STATUS_TONE[String(status).toLowerCase()] || '#94a3b8'
  const cards = [
    { label: 'Projects', value: summary?.projects ?? 0, icon: FolderKanban },
    { label: 'Tasks', value: summary?.tasks ?? 0, icon: ListChecks },
    { label: 'Open Tasks', value: summary?.open_tasks ?? 0, icon: ListChecks },
    { label: 'Tickets', value: summary?.tickets ?? 0, icon: LifeBuoy },
  ]

  return (
    <div style={{ maxWidth: 900, margin: '0 auto' }}>
      <style>{CSS}</style>
      <div className="ov-hero">
        <div className="ov-logo"><Building2 size={22} color="#fff" /></div>
        <div style={{ flex: 1, minWidth: 0 }}>
          <div style={{ fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>{vendor?.company_name || 'My Company'}</div>
          <div style={{ fontSize: 12.5, color: 'var(--text-muted)' }}>{[vendor?.email, vendor?.city, vendor?.state].filter(Boolean).join(' · ') || '—'}</div>
        </div>
        <span style={{ padding: '4px 12px', borderRadius: 999, fontSize: 12, fontWeight: 800, textTransform: 'capitalize', background: 'rgba(148,163,184,0.15)', color: tone }}>{String(status).replace(/_/g, ' ')}</span>
      </div>

      <div className="ov-grid">
        {cards.map(c => (
          <div key={c.label} className="ov-card">
            <c.icon size={18} style={{ color: 'var(--portal-purple,#7c3aed)' }} />
            <div style={{ fontSize: 26, fontWeight: 800, color: 'var(--text-h)', marginTop: 8 }}>{c.value}</div>
            <div style={{ fontSize: 11.5, textTransform: 'uppercase', letterSpacing: '0.05em', color: 'var(--text-muted)' }}>{c.label}</div>
          </div>
        ))}
      </div>
    </div>
  )
}

const CSS = `
.ov-hero { display: flex; align-items: center; gap: 14px; background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 18px; margin-bottom: 16px; }
.ov-logo { width: 48px; height: 48px; border-radius: 12px; background: linear-gradient(135deg,#7c3aed,#a78bfa); display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.ov-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; }
.ov-card { background: var(--bg-card, rgba(255,255,255,0.02)); border: 1px solid var(--border, rgba(255,255,255,0.08)); border-radius: 14px; padding: 16px; }
.ov-spin { animation: ov-spin 0.9s linear infinite; }
@keyframes ov-spin { to { transform: rotate(360deg); } }
`

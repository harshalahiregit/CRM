import { useState } from 'react'
import { FileText, Download, Eye, PartyPopper, AlertTriangle } from 'lucide-react'

/**
 * The HSSE Work Start Letter — the thing an approved vendor actually needs.
 *
 * "Approved" is a status. The work start letter is the DOCUMENT: the formal HSSE
 * clearance to commence work, the one a site will ask to see before letting
 * anybody through the gate. So it leads the approval screen rather than sitting
 * under it as a secondary link.
 *
 * It was TPV-only, behind a hardcoded `engagement === 'tpv'` check whose comment
 * claimed "Purchase issues its own". Purchase did not: the letter was generated
 * and stored, exposed to administrators, and reachable from no vendor screen at
 * all. One component now serves both engines, driven by the api it is handed.
 *
 * @param {object}   api        the engine's onboarding client (portal or admin)
 * @param {number}   onboardingId
 * @param {string}   company    shown in the greeting when known
 */
export default function WorkStartLetterCard({ api, onboardingId, company }) {
  const [busy, setBusy] = useState(null)
  const [err, setErr] = useState(null)

  // The letter is streamed, so it is fetched as a blob rather than linked — a
  // plain <a href> would drop the bearer token and answer 401.
  const fetchLetter = async () => {
    const blob = await api.onboarding.workStartLetter(onboardingId)

    return URL.createObjectURL(blob)
  }

  const run = async (what, fn) => {
    setBusy(what); setErr(null)
    try {
      await fn()
    } catch (e) {
      // The letter is issued on approval. Before that there is genuinely nothing
      // to open, and saying so beats a button that appears to do nothing.
      setErr(e?.response?.data?.message
        || 'The work start letter has not been issued yet. It is available once your onboarding is approved.')
    } finally {
      setBusy(null)
    }
  }

  const view = () => run('view', async () => {
    const url = await fetchLetter()
    window.open(url, '_blank', 'noopener')
    setTimeout(() => URL.revokeObjectURL(url), 30000)
  })

  const download = () => run('download', async () => {
    const url = await fetchLetter()
    const a = document.createElement('a')
    a.href = url
    a.download = `Work-Start-Letter-${onboardingId}.html`
    document.body.appendChild(a); a.click(); a.remove()
    setTimeout(() => URL.revokeObjectURL(url), 30000)
  })

  return (
    <div style={{
      borderRadius: 16, padding: 20, marginBottom: 16,
      background: 'linear-gradient(135deg, rgba(16,185,129,0.10), rgba(5,150,105,0.04))',
      border: '1.5px solid rgba(16,185,129,0.45)',
    }}>
      <div style={{ display: 'flex', alignItems: 'center', gap: 10, marginBottom: 6 }}>
        <PartyPopper size={22} style={{ color: '#059669', flexShrink: 0 }} />
        <h3 style={{ margin: 0, fontSize: 17, fontWeight: 900, color: 'var(--text-h)', letterSpacing: '-0.01em' }}>
          You are cleared to start work
        </h3>
      </div>

      <p style={{ margin: '0 0 14px', fontSize: 13, color: 'var(--text-muted)', lineHeight: 1.6, maxWidth: 640 }}>
        {company ? <><strong style={{ color: 'var(--text-h)' }}>{company}</strong> has been approved. </> : null}
        Your <strong style={{ color: 'var(--text-h)' }}>HSSE Work Start Letter</strong> is the formal clearance to
        commence work on site. Keep a copy — the site will ask to see it before your people are admitted through
        the gate.
      </p>

      <div style={{ display: 'flex', gap: 10, flexWrap: 'wrap' }}>
        <button onClick={view} disabled={!!busy} style={primary}>
          <Eye size={15} /> {busy === 'view' ? 'Opening…' : 'View Work Start Letter'}
        </button>
        <button onClick={download} disabled={!!busy} style={ghost}>
          <Download size={15} /> {busy === 'download' ? 'Preparing…' : 'Download'}
        </button>
      </div>

      {err && (
        <div style={{ display: 'flex', alignItems: 'flex-start', gap: 8, marginTop: 12, fontSize: 12.5, color: '#b45309' }}>
          <AlertTriangle size={14} style={{ flexShrink: 0, marginTop: 2 }} />
          <span>{err}</span>
        </div>
      )}

      <div style={{ display: 'flex', alignItems: 'center', gap: 6, marginTop: 12, fontSize: 11.5, color: 'var(--text-muted)' }}>
        <FileText size={12} />
        <span>Issued on approval · keep it with your site paperwork</span>
      </div>
    </div>
  )
}

const primary = {
  display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 18px', borderRadius: 10,
  border: 'none', background: 'linear-gradient(135deg,#10b981,#059669)', color: '#fff',
  fontWeight: 800, fontSize: 13, cursor: 'pointer',
}

const ghost = {
  display: 'inline-flex', alignItems: 'center', gap: 7, padding: '10px 16px', borderRadius: 10,
  border: '1px solid rgba(16,185,129,0.5)', background: 'var(--bg-card)', color: '#047857',
  fontWeight: 700, fontSize: 13, cursor: 'pointer',
}

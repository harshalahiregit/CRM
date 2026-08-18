import { useState, useEffect } from 'react'
import { Navigate } from 'react-router-dom'
import { portalApi } from '@/services/portalApi'

/**
 * `/vendor-portal/onboarding` — resolves the signed-in vendor's own onboarding
 * and hands off to the wizard at `/vendor-portal/onboarding/:id`.
 *
 * The portal deliberately has no onboarding LIST: a vendor has exactly one, and
 * its id never appears in a menu. GET /portal/onboarding resolves it from the
 * token (creating it on first visit), so the id stays a server-side detail and
 * the vendor cannot reach anyone else's by editing the URL — assertOwned() 404s
 * a foreign id regardless.
 */
export default function PortalOnboardingEntry() {
  const [id, setId] = useState(null)
  const [err, setErr] = useState(null)

  useEffect(() => {
    let alive = true

    portalApi.onboarding.list()
      .then((r) => {
        if (!alive) return
        const ob = (r?.data ?? [])[0]
        if (ob?.id) setId(ob.id)
        else setErr('No onboarding has been started for your company yet.')
      })
      .catch((e) => {
        if (!alive) return
        setErr(e?.response?.data?.message || 'Could not load your onboarding.')
      })

    return () => { alive = false }
  }, [])

  if (id) return <Navigate to={`/vendor-portal/onboarding/${id}`} replace />

  if (err) {
    return (
      <div style={{ padding: 24 }}>
        <h1 style={{ margin: 0, fontSize: 20, fontWeight: 800, color: 'var(--text-h)' }}>Onboarding</h1>
        <p style={{ margin: '8px 0 0', fontSize: 13.5, color: 'var(--text-muted)', lineHeight: 1.6 }}>{err}</p>
      </div>
    )
  }

  return <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>Loading your onboarding…</div>
}

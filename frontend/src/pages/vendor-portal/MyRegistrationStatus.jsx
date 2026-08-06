import { useState, useEffect, useMemo } from 'react'
import { portalApi } from '@/services/portalApi'
import MyCompanyCard from '@/components/vendor/MyCompanyCard'
import RegistrationStatusCard from '@/components/vendor/RegistrationStatusCard'

/**
 * My Registration Status — read-only.
 *
 * Replaces the onboarding LIST the portal used to show. That list was an admin
 * screen: it could render several onboarding records and invited the vendor to
 * "continue" a workflow whose decisions are not theirs. A vendor has exactly one
 * registration, and their only question is "where has it got to?".
 *
 * Strictly informational — no buttons, no wizard, no navigation into one. Documents
 * are the one thing the vendor actually supplies, so that is the single pointer out.
 */
export default function MyRegistrationStatus() {
  const [vendor, setVendor] = useState(null)
  const [onboarding, setOnboarding] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let alive = true
    Promise.all([
      portalApi.me().catch(() => null),
      portalApi.onboarding.list().catch(() => []),
    ]).then(([me, list]) => {
      if (!alive) return
      setVendor(me?.vendor ?? me ?? null)
      // A vendor owns ONE registration; take the first and ignore any others rather
      // than exposing a list the portal has no business showing.
      const rows = Array.isArray(list) ? list : (list?.data ?? [])
      setOnboarding(rows[0] ?? null)
      setLoading(false)
    })
    return () => { alive = false }
  }, [])

  const steps = useMemo(() => {
    const step = onboarding?.current_step ?? 0
    const approved = onboarding?.status === 'Approved'
    const active = (vendor?.status || '').toLowerCase() === 'active'
    // `done` once the wizard has moved past that step, or once approved outright.
    const at = (n) => ({ done: approved || step > n, current: !approved && step === n })

    return [
      { key: 'company',    label: 'Company Details', ...at(2) },
      { key: 'documents',  label: 'Documents',       ...at(3) },
      { key: 'workforce',  label: 'Workforce',       ...at(4) },
      { key: 'compliance', label: 'Compliance',      ...at(5) },
      { key: 'approval',   label: 'Final Approval',  done: approved, current: !approved && step >= 6 },
      { key: 'activated',  label: 'Portal Activated', done: active,  current: approved && !active },
    ]
  }, [onboarding, vendor])

  if (loading) {
    return <div style={{ padding: 24, color: 'var(--text-muted)', fontSize: 13 }}>Loading your registration…</div>
  }

  return (
    <div style={{ padding: 24 }}>
      <div style={{ marginBottom: 18 }}>
        <h1 style={{ margin: 0, fontSize: 21, fontWeight: 800, color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
          My Registration Status
        </h1>
        <p style={{ margin: '5px 0 0', fontSize: 13, color: 'var(--text-muted)' }}>
          Where your company’s registration currently stands. Your account team moves it forward.
        </p>
      </div>

      <MyCompanyCard vendor={vendor} accent="#0ea5e9" />

      <RegistrationStatusCard
        steps={steps}
        title="Progress"
        note="This page is for information only — there is nothing to action here."
      />

      <p style={{ margin: '14px 2px 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
        The one step you control is paperwork — upload anything outstanding under{' '}
        <strong style={{ color: 'var(--text-h)' }}>Documents</strong>. Everything else is reviewed and
        approved by your account team.
      </p>
    </div>
  )
}

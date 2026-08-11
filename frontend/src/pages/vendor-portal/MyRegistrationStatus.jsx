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
 * A summary, not the workflow: the six steps themselves are worked through under
 * My Onboarding. The step list here is whatever /progress returns, so this page
 * and the wizard can never disagree about which stage is current.
 */
export default function MyRegistrationStatus() {
  const [vendor, setVendor] = useState(null)
  const [onboarding, setOnboarding] = useState(null)
  const [progress, setProgress] = useState(null)
  const [loading, setLoading] = useState(true)

  useEffect(() => {
    let alive = true
    Promise.all([
      portalApi.me().catch(() => null),
      portalApi.onboarding.list().catch(() => []),
    ]).then(async ([me, list]) => {
      if (!alive) return
      setVendor(me?.vendor ?? me ?? null)
      // A vendor owns ONE registration; take the first and ignore any others rather
      // than exposing a list the portal has no business showing.
      const rows = Array.isArray(list) ? list : (list?.data ?? [])
      const ob = rows[0] ?? null
      setOnboarding(ob)

      // The six steps come from the server, which owns the completion rules. This
      // page used to invent its own labels ("Workforce", "Compliance") and derive
      // done-ness from current_step alone, so it named stages that do not exist and
      // disagreed with the wizard about which one was current.
      if (ob?.id) {
        const pr = await portalApi.onboarding.progress(ob.id).catch(() => null)
        if (alive && pr?.steps) setProgress(pr)
      }
      if (alive) setLoading(false)
    })
    return () => { alive = false }
  }, [])

  const steps = useMemo(() => {
    const current = onboarding?.current_step ?? 0

    // Server-owned: same six steps, same completion rules, same labels the wizard
    // shows. Nothing is inferred here beyond which one is current.
    if (progress?.steps?.length) {
      return progress.steps.map((s) => ({
        key: s.key,
        label: s.label,
        done: !!s.complete,
        current: !s.complete && s.step === current,
      }))
    }

    // Progress could not be fetched — name the steps correctly anyway rather than
    // falling back to labels for stages that do not exist.
    return [
      { key: 'kickoff',      label: 'Kickoff MOM' },
      { key: 'profile',      label: 'Company Profile' },
      { key: 'documents',    label: 'Documents' },
      { key: 'review',       label: 'Under Review' },
      { key: 'confirmation', label: 'Confirmation' },
      { key: 'submission',   label: 'Admin Approval' },
    ].map((s, i) => ({ ...s, done: current > i + 1, current: current === i + 1 }))
  }, [onboarding, progress])

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
        title="Onboarding Progress"
        note="A summary of where your onboarding stands. Continue it under My Onboarding."
      />

      <p style={{ margin: '14px 2px 0', fontSize: 12, color: 'var(--text-muted)', lineHeight: 1.6 }}>
        Work through the steps under <strong style={{ color: 'var(--text-h)' }}>My Onboarding</strong>.
        Document verification and final approval are carried out by your account team.
      </p>
    </div>
  )
}

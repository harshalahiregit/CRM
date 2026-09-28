/**
 * How many steps a vendor onboarding has.
 *
 * It used to be the digit 6, typed into six different screens — two vendor
 * workspaces, an onboarding register, the vendor's own wizard and two places on
 * the portal dashboard. Adding a step meant finding all six, and the one that
 * got missed would go on saying "Step 7 of 6" to somebody halfway through.
 *
 * The server now sends `total_steps` with the step list, so prefer that. This
 * constant is the fallback for the list screens that only carry a row's
 * `current_step` and never asked for the rest. It is checked against the PHP
 * constant by scripts/onboarding-steps.check.mjs, so the two cannot drift.
 */
export const ONBOARDING_TOTAL_STEPS = 7

/** The total this payload says it has, or the fallback above. */
export function totalSteps(data) {
  const n = Number(data?.total_steps ?? data?.steps?.length)

  return Number.isFinite(n) && n > 0 ? n : ONBOARDING_TOTAL_STEPS
}

/**
 * The seven steps, for a vendor whose onboarding has not started.
 *
 * The server computes this list per onboarding RECORD, so a vendor that has
 * none yet returned nothing and the workspace showed no steps at all — on the
 * one vendor where the steps are the entire point of the screen. The header
 * still said "Onboarding: Draft", because that pill falls back to 'Draft' when
 * the record is absent, so the page claimed an onboarding was under way and
 * then showed nothing about it.
 *
 * Step 1 is ours to do and needs no record: adding a contact is an admin
 * action, and it is what the kickoff meeting's pickers read from.
 *
 * Labels and order are checked against the PHP by
 * scripts/onboarding-steps.check.mjs — a second list of step names is only safe
 * while something fails when it disagrees.
 */
export const NOT_STARTED_STEPS = [
  { step: 1, key: 'contacts',     label: 'Add Contact',     complete: false, detail: 'None yet' },
  { step: 2, key: 'kickoff',      label: 'Kickoff MOM',     complete: false, detail: 'Not started' },
  { step: 3, key: 'profile',      label: 'Company Profile', complete: false, detail: 'Not started' },
  { step: 4, key: 'documents',    label: 'Documents',       complete: false, detail: 'Not started' },
  { step: 5, key: 'review',       label: 'Under Review',    complete: false, detail: 'Not started' },
  { step: 6, key: 'confirmation', label: 'Confirmation',    complete: false, detail: 'Not started' },
  { step: 7, key: 'submission',   label: 'Admin Approval',  complete: false, detail: 'Not started' },
]

/** The real steps if the server sent any, otherwise the not-started list. */
export function stepsOrNotStarted(steps) {
  return Array.isArray(steps) && steps.length > 0 ? steps : NOT_STARTED_STEPS
}

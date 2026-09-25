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

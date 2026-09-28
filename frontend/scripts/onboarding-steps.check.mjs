/**
 * The onboarding step count is one number, in two languages.
 *
 * PHP owns it — `TOTAL_STEPS` on both onboarding status classes — and the
 * server sends it down with the step list. But the two register screens only
 * ever receive a row's `current_step`, never the total, so the frontend keeps a
 * fallback constant. A fallback that disagrees with the server is worse than no
 * fallback: it renders "Step 7 of 6" to somebody halfway through, and only on
 * the screens nobody checks after a change.
 *
 * Before this, the number was not a constant at all — the digit 6 was typed
 * into six separate screens, and adding a step meant finding every one of them.
 *
 * Also checks that Purchase and TPV agree with each other. They are one process
 * against two vendor masters and have drifted over less than this.
 *
 * Run: npm run check:onboarding
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { dirname, join } from 'node:path'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')
const BACKEND = join(ROOT, '../backend')

const { ONBOARDING_TOTAL_STEPS, NOT_STARTED_STEPS } = await import(
  pathToFileURL(join(ROOT, 'src/lib/vendors/onboardingSteps.js')).href
)

const SOURCES = [
  ['Purchase', 'app/Support/Purchase/PurchaseOnboardingStatus.php'],
  ['TPV', 'app/Support/Tpv/TpvOnboardingStatus.php'],
]

/** Where the real step list is built, per engine. */
const SERVICES = [
  ['Purchase', 'app/Services/Purchase/PurchaseOnboardingService.php'],
  ['TPV', 'app/Services/Tpv/TpvOnboardingService.php'],
]

const failures = []
const found = []

for (const [name, rel] of SOURCES) {
  let php
  try {
    php = readFileSync(join(BACKEND, rel), 'utf8')
  } catch {
    failures.push(`${name}: cannot read ${rel} — was it moved?`)
    continue
  }

  const m = php.match(/const\s+TOTAL_STEPS\s*=\s*(\d+)\s*;/)

  if (!m) {
    failures.push(`${name}: no TOTAL_STEPS constant in ${rel}`)
    continue
  }

  const value = Number(m[1])
  found.push([name, value])

  if (value !== ONBOARDING_TOTAL_STEPS) {
    failures.push(
      `${name} says TOTAL_STEPS = ${value}, the frontend fallback says `
      + `${ONBOARDING_TOTAL_STEPS} — update src/lib/vendors/onboardingSteps.js`,
    )
  }
}

if (found.length === 2 && found[0][1] !== found[1][1]) {
  failures.push(
    `Purchase (${found[0][1]}) and TPV (${found[1][1]}) disagree about how many `
    + 'steps an onboarding has — they are meant to be the same process',
  )
}

if (found.length < SOURCES.length) {
  failures.push('not every onboarding status class was read — the scan is incomplete')
}

/*
 * The frontend keeps a copy of the step names, for a vendor whose onboarding
 * has not started yet: the server builds that list per RECORD, so there is
 * nothing to ask for until one exists. A second list of names is only safe
 * while something fails when it disagrees with the first.
 */
const expected = NOT_STARTED_STEPS.map((s) => `${s.key}:${s.label}`)

for (const [name, rel] of SERVICES) {
  let php
  try {
    php = readFileSync(join(BACKEND, rel), 'utf8')
  } catch {
    failures.push(`${name}: cannot read ${rel} — was it moved?`)
    continue
  }

  const actual = [...php.matchAll(/'key'\s*=>\s*'([a-z_]+)',\s*'label'\s*=>\s*'([^']+)'/g)]
    .map((m) => `${m[1]}:${m[2].trim()}`)

  if (actual.length === 0) {
    failures.push(`${name}: found no steps in ${rel} — the scan pattern no longer matches`)
    continue
  }

  if (actual.join(' | ') !== expected.join(' | ')) {
    failures.push(
      `${name}'s step list has drifted from NOT_STARTED_STEPS in `
      + `src/lib/vendors/onboardingSteps.js — server has [${actual.join(' , ')}], `
      + `frontend has [${expected.join(' , ')}]`,
    )
  }
}

if (failures.length) {
  console.error('✗ onboarding steps\n')
  for (const f of failures) console.error(`  - ${f}`)
  console.error(`\n${failures.length} problem(s).`)
  process.exit(1)
}

console.log(
  `✓ onboarding steps (${ONBOARDING_TOTAL_STEPS} steps and their names agreed by `
  + 'Purchase, TPV and the frontend)',
)

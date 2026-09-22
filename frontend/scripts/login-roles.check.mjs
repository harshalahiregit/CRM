// Checks the login role selector — run with `npm run check:login-roles`.
//
// The selector asks "who are you?" before anybody has typed anything, so every
// entry in it is a claim that this is a kind of person who signs in here. Two
// entries were not: Doctor and Company are portals, not HR roles, and offering
// them made the first screen of the product ask a question most people could
// not answer.
//
// They were removed from the LIST ONLY. Both are real Users with real portals
// (routes/medical.php, routes/company_portal.php) and the backend still accepts
// them — LoginRequest is untouched. They sign in with the selector left on
// "Access role (optional)", which resolves because users.email is unique.
//
// This check exists because the removal is a one-line deletion that a later
// merge could silently undo, and because re-adding either would look like a
// harmless restoration rather than the reopening of a question the owner has
// already answered.
//
// The frontend has no test runner (see searchable-selects.check.mjs), so the
// source is read and asserted against directly.

import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const here = dirname(fileURLToPath(import.meta.url))
const src = readFileSync(resolve(here, '../src/pages/auth/LoginPage.jsx'), 'utf8')

let failures = 0
const check = (name, cond, extra = '') => {
  if (!cond) failures++
  console.log(`${cond ? 'ok  ' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`)
}
const group = (n) => console.log(`\n── ${n}`)

// The ROLES array only, so a `role === 'doctor'` redirect elsewhere in the file
// is not mistaken for a selectable option. Those redirects must STAY.
const rolesBlock = src.slice(src.indexOf('const ROLES = ['), src.indexOf('const schema'))
const offered = [...rolesBlock.matchAll(/value:\s*'([a-z_]+)'/g)].map(m => m[1])

group('what the selector offers')
console.log(`     offered: ${offered.join(', ')}`)

check('Doctor is not offered as a login role', !offered.includes('doctor'),
  'a doctor is a portal user, not an HR role')
check('Company is not offered as a login role', !offered.includes('company'),
  'an external hiring company is a portal user, not an HR role')

group('the roles that must remain')
for (const role of ['admin', 'staff', 'third_party_vendor', 'client', 'purchase_vendor']) {
  check(`${role} is still offered`, offered.includes(role))
}
check('the list is not empty', offered.length >= 5)

group('the portals must still be reachable')
// Removing these redirects would strand a doctor or a company on /app, which
// their route guards refuse — an infinite bounce rather than a wrong page.
check('a company still lands on its portal', /role === 'company'\s*\?\s*'\/company-portal/.test(src),
  'roleHome() must keep routing company users')
check('a doctor still lands on its portal', /role === 'doctor'\s*\?\s*'\/doctor-portal/.test(src),
  'roleHome() must keep routing doctor users')
check('the redirect reads the SERVER-returned role', src.includes('roleHome(result.role)'),
  'with no role selected, only the response says who signed in')

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)

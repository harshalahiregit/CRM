// Checks the Employee ↔ Staff identity boundary — run with `npm run check:staff-identity`.
//
// A runtime audit drove the app in a browser and found the two screens
// describing the same person differently at the same moment, and an employee
// marked Inactive in HR still holding a working login. The server-side halves of
// that fix are covered by tests/Feature/Hr/EmployeeStaffIdentityTest.php. These
// are the browser-side halves, which have no test runner to hold them.
//
// Each check names the exact defect it exists to prevent.

import { readFileSync } from 'fs'
import { fileURLToPath } from 'url'
import { dirname, resolve } from 'path'

const here = dirname(fileURLToPath(import.meta.url))
const read = (p) => readFileSync(resolve(here, p), 'utf8')

let failures = 0
const check = (name, cond, extra = '') => {
  if (cond) { console.log(`  ok   ${name}`); return }
  failures++
  console.log(`  FAIL ${name}${extra ? ` — ${extra}` : ''}`)
}
const group = (t) => console.log(`\n${t}`)

const modal = read('../src/components/admin/StaffModal.jsx')
const page  = read('../src/pages/admin/StaffManagementPage.jsx')
const panel = read('../src/modules/hr/components/DirectoryGapPanel.jsx')
const api   = read('../src/services/hrApi.js')

group('an account with no access role stays editable')

// 7 of 11 real accounts have no staff_role_id, Super Admin among them. Marking
// the field unconditionally required made every one of them permanently
// uneditable: the form could not validate, so pressing Update sent no request at
// all — no error, no network call, nothing.
check('the requirement is conditional, not hard-coded',
  /required=\{roleRequired\}/.test(modal),
  'required on the access role select must depend on roleRequired')

check('roleRequired is false for an existing account that has no role',
  /const roleRequired = !staff \|\| Boolean\(staff\.staff_role_id\)/.test(modal),
  'new accounts require a role; existing ones require it only if they already have one')

check('required is not asserted unconditionally on that select',
  !/<select value=\{formData\.staff_role_id[^>]*\srequired\s/.test(modal),
  'a bare `required` reintroduces the unsubmittable form')

// The backend rule is `sometimes|required`, so an empty value PRESENT is a 422
// while an absent key is fine. Posting internal_role: '' would replace the
// browser's silent refusal with a server one.
check('an empty internal_role is omitted from the payload',
  /\.\.\.\(formData\.internal_role \? \{ internal_role: formData\.internal_role \} : \{\}\)/.test(modal),
  'never post internal_role: ""')

group('the two "Role" concepts are named apart')

check('Staff Management calls it an Access Role', /Access Role/.test(modal),
  'HR Organization Setup has Job Roles; sharing the word "Role" is what confused people')

check('the table header says so too', /Access Role \/ Designation/.test(page))

group('the account list explains why sign-in is closed')

// The defect: an ACTIVE badge beside a login the auth gate refuses. The badge is
// the account's status and is correct; it was simply not the whole answer.
check('a blocked reason is rendered when present',
  /member\.access_blocked_reason/.test(page),
  'the row must be able to say why access is unavailable')

check('employment status is shown separately from account status',
  /member\.employment_status/.test(page),
  'employment and account are different questions and need different answers')

group('identity is read from the record that owns it')

// member_departments is an account-level routing list, NOT the employee's home
// department. Sharing the name "Departments" made an empty routing list read as
// a contradiction of the HR screen.
check('the routing column is not called Departments', /'Member Of'/.test(page),
  'the account\'s member_departments must not be confused with the employee\'s department')

check('the designation cell shows the owned department too',
  /\[member\.designation, member\.department\]/.test(page))

group('the reconciliation panel can act, not only report')

// The endpoint was routed, the controller written and the client helper defined
// — and called by nothing. The panel reported people who could not sign in and
// offered no way to give any of them a login.
check('the client helper exists', /provisionLogin: \(employeeId\) =>/.test(api))

check('the panel calls it', /hrApi\.employees\.provisionLogin\(/.test(panel),
  'a diagnostic with no remedy is a screen people scroll past')

check('rows can carry an action', /r\.action &&/.test(panel))

check('a refusal is surfaced, not swallowed',
  /showToast\?\.\(e\.response\?\.data\?\.message/.test(panel),
  'every server refusal carries its reason and the admin must see it')

// Linking the wrong account hands one person's payslips to another.
check('linking is only ever offered, never automatic',
  /r\.suggested_user/.test(panel) && !/autoLink|linkAll/.test(panel),
  'the server suggests on an exact unique email match; the admin still presses the button')

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)

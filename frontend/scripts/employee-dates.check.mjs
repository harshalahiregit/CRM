// Checks employee date fields render — run with `npm run check:employee-dates`.
//
// THE BUG. `joining_date` is a date column, but Eloquent casts it to Carbon and
// serialises the full instant: "2025-01-01T00:00:00.000000Z". An
// <input type="date"> accepts only "YYYY-MM-DD", and handed anything else it
// renders EMPTY rather than complaining. So opening any employee for editing
// showed Joining Date blank — beside its required marker, on a record whose card
// two lines above read "Joined 01 Jan 2025". Date of Birth, Probation End Date
// and Confirmation Date were blank for the same reason.
//
// The value was never lost; form state kept the ISO string and saving preserved
// the date. That is what made it dangerous rather than merely broken: it reads
// as missing data, and the obvious response is to retype a date that was already
// correct — or to conclude the record is damaged.
//
// TRUNCATION, NOT CONVERSION. These are calendar dates. Running
// "2025-01-01T00:00:00Z" through a local-time conversion moves it to 31 December
// for every reader west of Greenwich, which is the timezone bug this codebase
// already paid for once on attendance (see AttendanceTimeFormattingTest). The
// first ten characters keep the day the server sent.
//
// The frontend has no test runner (see searchable-selects.check.mjs), so the
// helper's source is lifted out of constants.js and exercised directly — a real
// behavioural check against the shipped code, not a grep for its name.

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

const constants = read('../src/modules/hr/constants.js')
const employees = read('../src/modules/hr/pages/Employees.jsx')

group('the helper exists and is exported')

// constants.js imports through the '@' alias, so it cannot simply be imported
// here. The function is pure and self-contained, so it is lifted out by name and
// evaluated — which still fails if somebody renames or deletes it.
const match = constants.match(/export const hrDateInput = \(v\) => \{[\s\S]*?\n\}/)

check('hrDateInput is defined in constants.js', Boolean(match),
  'the shared helper has moved — update this check to follow it')

if (!match) {
  console.log('\n1 FAILED')
  process.exit(1)
}

const hrDateInput = eval(`(${match[0].replace('export const hrDateInput = ', '')})`)

group('it turns what the API sends into what the input accepts')

const cases = [
  // [input, expected, why]
  ['2025-01-01T00:00:00.000000Z', '2025-01-01', 'the exact shape the API returns'],
  ['2025-01-01T00:00:00Z',        '2025-01-01', 'a shorter ISO instant'],
  ['2025-01-01 00:00:00',         '2025-01-01', 'a raw SQL datetime'],
  ['2025-01-01',                  '2025-01-01', 'already correct, passed through unchanged'],
  ['2025-12-31T18:30:00.000000Z', '2025-12-31', 'a late-evening UTC instant must NOT roll to the next day'],
  [null,                          '',           'null is empty, not "Invalid Date"'],
  [undefined,                     '',           'undefined is empty'],
  ['',                            '',           'empty stays empty'],
  ['not a date',                  '',           'junk is empty rather than rendering garbage'],
]

for (const [input, expected, why] of cases) {
  const got = hrDateInput(input)
  check(`${JSON.stringify(input)} → ${JSON.stringify(expected)}  (${why})`, got === expected,
    `got ${JSON.stringify(got)}`)
}

group('every date field on the employee form goes through it')

check('Employees.jsx imports the helper', /import \{[^}]*\bhrDateInput\b[^}]*\} from '@\/modules\/hr\/constants'/.test(employees),
  'the form must use the shared helper, not a private copy')

check('the date keys are declared', /const DATE_KEYS = new Set\(\[([^\]]*)\]\)/.test(employees))

const keys = (employees.match(/const DATE_KEYS = new Set\(\[([^\]]*)\]\)/) || [, ''])[1]

for (const field of ['dob', 'joining_date', 'probation_end_date', 'confirmation_date']) {
  check(`${field} is normalised`, keys.includes(`'${field}'`),
    'an <input type="date"> bound to a raw API value renders blank')
}

check('openEdit routes those keys through the helper', /DATE_KEYS\.has\(k\)\s*\?\s*hrDateInput\(emp\[k\]\)/.test(employees),
  'this is the line that puts the date on the screen')

group('the stored value is not rewritten')

// The fix is presentational. If somebody "helpfully" starts converting these to
// instants on the way out, the date moves a day for half the world.
check('no Date() conversion in the helper', !/new Date\(/.test(match[0]),
  'these are calendar dates — parsing them as instants shifts them by a timezone')

console.log(failures ? `\n${failures} FAILED` : '\nall passed')
process.exit(failures ? 1 : 0)

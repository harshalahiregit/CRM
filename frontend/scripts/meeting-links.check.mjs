/**
 * Every link the meeting module builds must lead back into that module —
 * and must be a route that exists.
 *
 * WHY. The same meeting screens are mounted at three paths: `/app/meetings`
 * (the company-wide module), `/app/purchase/kickoff` and `/app/tpv/kickoff`.
 * Which module the user is in decides every link on the page, and that decision
 * used to be made twice, in two files, from two tables. They drifted: the API
 * layer's resolver tested only for `/app/purchase` and fell through to
 * `/app/tpv` for everything else, so every link on `/app/meetings` — twenty of
 * them — threw the user into TPV. Meetings had its own module, its own path and
 * its own sidebar entry, and clicking anything inside it put you in somebody
 * else's module (SIR-000030).
 *
 * Nothing caught that. The build is clean, the page renders, the link is a
 * valid URL, and it goes somewhere real — just somewhere else. You only find it
 * by clicking.
 *
 * So this asserts two properties of the one table that now decides:
 *
 *   1. ROUND TRIP. A link built while in module X resolves back to module X.
 *      This is the exact invariant that was broken.
 *   2. THE ROUTE EXISTS. Every path the table can produce is declared in
 *      routes.jsx. This is what was missing for the registers: the one module
 *      that reads across every meeting had no route to the register that reads
 *      across every meeting.
 *
 * Run: npm run check:meetings
 */
import { readFileSync } from 'node:fs'
import { fileURLToPath, pathToFileURL } from 'node:url'
import { dirname, join } from 'node:path'

const ROOT = join(dirname(fileURLToPath(import.meta.url)), '..')

// pathToFileURL, not the bare path: on Windows an absolute path starts "C:",
// which the ESM loader reads as an unsupported URL scheme and refuses.
const { MEETING_MODULES, meetingModuleFor } = await import(
  pathToFileURL(join(ROOT, 'src/modules/shared/meetingModules.js')).href
)

const failures = []

// ---------------------------------------------------------------- round trip

/** The full set of links a module can build, named for the error message. */
const linksOf = (m) => [
  ['list', m.list],
  ['create', m.create],
  ['detail', m.detail(7)],
  ['edit', m.edit(7)],
  ['registers', m.registers('decisions')],
  ['registers (index)', m.registers()],
]

for (const mod of MEETING_MODULES) {
  for (const [name, path] of linksOf(mod)) {
    const landed = meetingModuleFor(path)

    if (landed.key !== mod.key) {
      failures.push(
        `${mod.key}: its "${name}" link is ${path}, which resolves to the `
        + `"${landed.key}" module — a link inside a module must stay in it`,
      )
    }
  }
}

// -------------------------------------------------------------- route exists

const routes = readFileSync(join(ROOT, 'src/app/routes.jsx'), 'utf8')

/** Every `path="..."` the router declares, however deeply nested. */
const declared = new Set(
  [...routes.matchAll(/\bpath="([^"]+)"/g)].map((m) => m[1].replace(/^\/+/, '')),
)

/**
 * A concrete URL matches a declared route if any of its tails does, once the
 * concrete bits are put back into parameter form. Tails, because routes.jsx
 * declares children relative to a parent (`kickoff/:id` under `purchase`), and
 * this check deliberately does not try to rebuild the nesting — a tail match is
 * enough to prove the route was written, which is the thing that goes missing.
 */
const REGISTER_NAMES = new Set(['decisions', 'issues', 'actions'])

const parameterise = (segments) =>
  segments
    .map((s) => (/^\d+$/.test(s) ? ':id' : REGISTER_NAMES.has(s) ? ':register' : s))
    .join('/')

for (const mod of MEETING_MODULES) {
  for (const [name, path] of linksOf(mod)) {
    const segments = path.replace(/^\/app\/?/, '').split('/').filter(Boolean)

    // Every tail of the path, longest first: "purchase/kickoff/:id", then
    // "kickoff/:id", then ":id".
    const tails = segments.map((_, i) => parameterise(segments.slice(i)))

    if (!tails.some((t) => declared.has(t))) {
      failures.push(
        `${mod.key}: its "${name}" link is ${path}, and routes.jsx declares no `
        + `route for it (tried ${tails.map((t) => `"${t}"`).join(', ')})`,
      )
    }
  }
}

// ------------------------------------------------------------------- verdict

// A check that silently passes because it found nothing to check is worse than
// no check: it reports success for a file that was renamed out from under it.
if (MEETING_MODULES.length < 3) {
  failures.push(`only ${MEETING_MODULES.length} meeting module(s) found — the table did not load`)
}

if (declared.size < 50) {
  failures.push(`only ${declared.size} routes parsed from routes.jsx — the scan found nothing`)
}

if (failures.length) {
  console.error('✗ meeting links\n')
  for (const f of failures) console.error(`  - ${f}`)
  console.error(`\n${failures.length} problem(s).`)
  process.exit(1)
}

console.log(
  `✓ meeting links (${MEETING_MODULES.length} modules × ${linksOf(MEETING_MODULES[0]).length} links, `
  + `each resolving back to its own module and matching a declared route)`,
)

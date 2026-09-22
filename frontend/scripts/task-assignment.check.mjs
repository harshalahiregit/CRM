// Checks for the task assignment + progress wiring — `npm run check:tasks`.
//
// Two things are pinned here, and both are things a build passes without.
//
//  1. STAFF AND PARTY ASSIGNEES ARE TWO LISTS. `assignees` holds users;
//     `party_assignees` holds named contacts at a client, a vendor or a TPV who
//     mostly have no login in this system at all. The moment one screen merges
//     them, a contact id gets posted where a user id is expected and the
//     assignment lands on whoever happens to own that users row.
//
//  2. THE COMPANY LINK IS GONE FROM THE PEOPLE CARD. It used to sit there
//     labelled "Related to (company)" — a link to an organisation, assigned to
//     nobody. A company cannot do a task. Putting it back beside real assignees
//     is the confusion this replaced.
//
// Plus the small invariants that a browser would catch only by being driven:
// the picker must not dismiss on a backdrop click, and the two tallies must be
// read from the server's breakdown rather than recomputed on the client.

import { readFileSync } from 'node:fs'
import { fileURLToPath } from 'node:url'
import { dirname, resolve } from 'node:path'

const here = dirname(fileURLToPath(import.meta.url))
const read = (p) => readFileSync(resolve(here, '..', p), 'utf8')

let failures = 0
const check = (name, cond, extra = '') => {
  if (!cond) failures++
  console.log(`${cond ? 'ok  ' : 'FAIL'}  ${name}${extra ? '  — ' + extra : ''}`)
}
const group = (n) => console.log(`\n── ${n}`)

const detail = read('src/modules/tasks/pages/TaskDetail.jsx')
// Shared with Projects now — it moved out of the tasks module.
const picker = read('src/components/ui/PartyPicker.jsx')
const tree   = read('src/modules/tasks/components/SubtaskTree.jsx')
const portal = read('src/modules/tasks/portal/PortalAssignedTasks.jsx')
const apiJs  = read('src/services/taskApi.js')
const routes = read('src/app/routes.jsx')

/* ── the two lists stay apart ────────────────────────────────────────────── */
group('staff assignees and party assignees are never merged')

check('the detail screen reads party_assignees',
  detail.includes('task?.party_assignees'),
  'server-sent and already resolved — no extra request per chip')

check('party chips post {party_type, party_id}, not user ids',
  /party_type: x\.party_type, party_id: x\.party_id/.test(detail),
  'a contact id in a user_id column assigns the task to a stranger')

check('the party endpoint is separate from /assignees',
  apiJs.includes('/party-assignees') && apiJs.includes('/assignees'),
  'two identifiers, two endpoints')

check('taskApi.parties exposes the full two-stage walk',
  ['kinds:', 'orgs:', 'people:', 'sync:'].every((k) => apiJs.includes(k)),
  'kind then company then person')

/* ── the company link is gone ────────────────────────────────────────────── */
group('the company link is not back in the People card')

check('no "Related to (company)" label',
  !detail.includes('Related to (company)'),
  'it assigned the task to nobody')

check('no "Link a company" button',
  !detail.includes('Link a company'))

check('the vendor-link picker is gone with it',
  !detail.includes("picker === 'vendor-link'") && !detail.includes('setVendorLink'),
  'dead once the block it opened was replaced')

check('people at other companies took its place',
  detail.includes('Assignees (client / vendor / TPV)'))

/* ── the picker ──────────────────────────────────────────────────────────── */
group('the picker behaves')

check('it does not dismiss on a backdrop click',
  !/overlay[\s\S]{0,400}onClick=\{onClose\}/.test(picker)
  && !/style=\{overlay\}\s+onClick/.test(picker),
  'three stages in, a stray click outside would throw away the whole walk')

check('it closes on the X and on Cancel',
  picker.includes('aria-label="Close"') && picker.includes('>Cancel<'))

check('somebody already on the task cannot be picked twice',
  picker.includes('takenKeys') && picker.includes('disabled={taken}'))

check('it never returns a user id',
  !picker.includes('user_id'),
  'these people mostly have no login at all')

/* ── the two tallies ─────────────────────────────────────────────────────── */
group('subtasks and checklist items are counted separately')

for (const [label, src] of [['the subtask tree', tree], ['the portal list', portal]]) {
  check(`${label} reads the server's breakdown`,
    src.includes('breakdown?.subtasks') && src.includes('breakdown?.checklist'),
    'recomputing it on the client is how two screens quote different numbers')
}

check('the tree shows both counts, not one merged number',
  tree.includes('subDone}/{subCount') && tree.includes('listDone}/{listCount'))

check('an absent tally is hidden rather than shown as 0/0',
  tree.includes('subCount > 0 &&') && tree.includes('listCount > 0 &&'),
  'an empty "0/0" down every level is noise at fifty rows')

/* ── the assignee can actually see it ────────────────────────────────────── */
group('every portal has somewhere for the assigned person to look')

check('one screen serves all three portals',
  // JSX usages only. The lazy line names it twice — once as the const, once in
  // the import path — so a bare count of the identifier is off by one.
  (routes.match(/<PortalAssignedTasks/g) || []).length === 3,
  'three portals, one component')

for (const [portalName, path] of [
  ['client',   'path="my-tasks"'],
  ['purchase', 'path="tasks"      element={<S><PortalAssignedTasks'],
  ['TPV',      'path="team-tasks"'],
]) {
  check(`the ${portalName} portal mounts it`, routes.includes(path))
}

for (const [portalName, shell, entry] of [
  ['client',   'src/pages/client-portal/ClientPortalShell.jsx',       "'/portal/my-tasks'"],
  ['purchase', 'src/pages/purchase-portal/PurchasePortalShell.jsx',   "to: 'tasks'"],
  ['TPV',      'src/pages/vendor-portal/VendorPortalShell.jsx',       "to: 'team-tasks'"],
]) {
  check(`the ${portalName} portal links to it in the nav`, read(shell).includes(entry),
    'a route with no way in is the same as no route')
}

check('the portal screen tells the two kinds of row apart',
  portal.includes('assigned_to_me'),
  'a subtask styled like its parent reads as a second assignment')

/* ── multi-select ────────────────────────────────────────────────────────── */
group('several people can be assigned in one go')

const sp = read('src/components/ui/SearchPicker.jsx')

check('the shared picker supports multi-select',
  sp.includes('multi = false') && sp.includes('onConfirm'),
  'opt-in, so the twenty-odd existing callers are unchanged')

check('Enter ticks rather than confirms in multi mode',
  sp.includes('else toggle(filtered[active])'),
  'confirming on Enter would make picking a second person impossible')

check('the confirm button is dead until something is ticked',
  sp.includes('disabled={!chosen.size}'))

check('no picker dismisses on a backdrop click',
  !sp.includes('bg-black/50" onClick={onClose}'),
  'a stray click outside would discard every tick made so far')

const flat = detail.replace(/\s+/g, ' ')
for (const [which, needle] of [
  ['staff assignees', 'multi onConfirm={picked => syncAssign'],
  ['followers',       'multi onConfirm={picked => syncFollow'],
  // The picker takes an accent now that Projects shares it.
  ['party people',    "multi accent={TASK_ACCENT} open={picker === 'party'}"],
]) {
  check(`${which} are picked several at a time`, flat.includes(needle),
    'four picks was four requests and four full refetches')
}

check('the party picker ticks instead of closing in multi mode',
  picker.includes('togglePick') && picker.includes('onPick([...picked.values()])'))

/* ── the wait ────────────────────────────────────────────────────────────── */
group('the chip does not wait for a round trip')

check('assignee writes are optimistic',
  detail.includes('pivotMut') && detail.includes('onMutate'),
  'post, then refetch twice, is why assigning felt slow')

check('a failure puts the previous list back',
  detail.includes('if (ctx?.previous) qc.setQueryData'),
  'an optimistic chip must never outlive a refusal')

check("the server's reply is written to the cache rather than refetched",
  flat.includes('onSuccess: (rows) => { setActionErr('),
  'the response already contains the new list')

check('the board is not refetched from the detail view',
  detail.includes("queryKey: ['tasks'], refetchType: 'none'"),
  'it is not on screen — refetching sixty rows makes the user wait for nothing')

/* ── two bars ────────────────────────────────────────────────────────────── */
group('checklist and subtasks get their own progress bars')

check('there is a MiniBar component', detail.includes('function MiniBar'))

check('the subtasks section has its own bar',
  detail.includes('<MiniBar done={subBar.done} total={subBar.total} />'))

check('the checklist section has its own bar',
  detail.includes('<MiniBar done={listBar.done} total={listBar.total} />'))

check('both read the server breakdown rather than recomputing',
  detail.includes('bd?.checklist') && detail.includes('bd?.subtasks'),
  'the board, the modal and the portal must never quote different figures')

check('an empty section draws no bar',
  detail.includes('if (!total) return null'),
  '"0% done" and "no work here" mean opposite things')

/* ── dead links ──────────────────────────────────────────────────────────── */
group('a link whose target was deleted says so')

check('the chip reads the server flag',
  detail.includes('task.rel_missing'),
  'deleting a vendor does not touch the tasks pointing at it')

check('it says "no longer exists" instead of offering to open it',
  detail.includes('no longer exists') && flat.includes('{task.rel_missing ? ('),
  'an "open" button to a deleted record reads as the page being broken')

check('there is a way to clear it',
  detail.includes('clearLink.mutate()') && detail.includes("rel_type: 'standalone', rel_id: null"),
  'a dangling link you cannot remove is a permanent wrong answer')

/* ── checklist owners ────────────────────────────────────────────────────── */
group('a checklist line can be on several people')

check('the checklist picker is multi-select',
  flat.includes('multi open={assignItemId !== null}'),
  'one line, one name was why "Priya and Rohit" had to be two lines')

check('it opens with the current owners ticked',
  detail.includes('preselected={assignItemOwners}'),
  'reopening it must show the set, not ask again from scratch')

check('it posts a list of ids',
  detail.includes('{ assigned_to: userIds }'))

check('the row shows every owner, not just the first',
  detail.includes('c.assignees?.length') && detail.includes("`${owners.length} people`"),
  'assignees is the truth; assigned_to is a mirror of its first row')

check('the owners list falls back to the mirror column for older rows',
  detail.includes('[c.assignee?.name || peopleById[c.assigned_to]?.name]'),
  'rows written before the pivot existed still have to render')

/* ── every place a person is picked ──────────────────────────────────────── */
group('nothing left that assigns one person at a time')

const drawer  = read('src/modules/tasks/components/TaskFormDrawer.jsx')
const cascade = read('src/modules/tasks/components/VendorEmployeeCascadePicker.jsx')
const bulkbar = read('src/modules/tasks/components/TaskBulkBar.jsx')

check('the new-task form assigns several staff at once',
  drawer.includes('multi confirmLabel="Assign"'),
  'staffing a new task is almost never one person')

check('the new-task form adds several followers at once',
  drawer.includes('multi confirmLabel="Follow"'))

check('the TPV cascade ticks several employees',
  cascade.includes('toggleEmployee') && cascade.includes('Assign ${picked.length}'),
  'two stages in, one pick per person meant walking it again for every name')

check('the cascade accumulates instead of overwriting',
  drawer.includes('setForm(p => p.assignee_ids.includes(user_id)'),
  'sf() takes a value from the render-time form — three calls in a tick would keep only the last')

check('the cascade no longer uses alert()',
  !cascade.includes('alert('),
  'a batch failure has to name who failed, which a browser alert cannot')

check('the cascade does not dismiss on a backdrop click',
  !cascade.includes('<div onClick={onClose} style={{ position: \'fixed\''),
  'two stages and several ticks in, that throws the whole walk away')

check('bulk assign takes several people',
  bulkbar.includes('multi confirmLabel="Assign"')
  && bulkbar.includes("value: picked.map(p => p.id)"),
  'the selection is the expensive thing to build — do not make them build it three times')

check('bulk assign adds rather than replaces',
  bulkbar.includes('added to whoever is already on each task'),
  'replacing would take people off work they are doing')

/* ── the deep links actually resolve ─────────────────────────────────────── */
group('every "Related to … open" link points at a route that exists')

// The one place these are built. Read from the backend source so this check
// fails when somebody adds a rel_type with a guessed URL, rather than when a
// user clicks it and gets a 404.
const svc = read('../backend/app/Services/Task/TaskService.php')

// "/app/tpv/view/{$id}" -> /app/tpv/view/:id
const templates = [...svc.matchAll(/fn \(\$id\) => "(\/app\/[^"]+)"/g)]
  .map(m => m[1].replace(/\{\$id\}/g, ':id'))

check('the link builder was found at all', templates.length >= 6,
  'if this drops to zero the checks below are vacuously true')

/*
 * routes.jsx nests, so a flat search for a segment cannot tell which parent it
 * belongs to — and that is exactly the distinction the real bug turned on.
 * "vendors/:id" exists, under PURCHASE; under TPV /vendors is only the list and
 * the workspace is /view/:id. A flat check passes the broken link.
 *
 * So the block belonging to each module prefix is isolated by indentation —
 * from its `path="tpv"` line until the next line indented no further — and the
 * tail is looked for only inside that block.
 */
const routeLines = routes.split('\n')

const blockFor = (prefix) => {
  const start = routeLines.findIndex(l => l.includes(`path="${prefix}"`))
  if (start === -1) return null
  const indent = routeLines[start].search(/\S/)
  const out = []
  for (let i = start + 1; i < routeLines.length; i++) {
    const line = routeLines[i]
    if (!line.trim()) continue
    if (line.search(/\S/) <= indent) break
    out.push(line)
  }
  return out.join('\n')
}

for (const url of templates) {
  const parts = url.replace(/^\/app\//, '').split('/')
  const block = blockFor(parts[0])
  const tail = parts.slice(1).join('/')
  // Two shapes are both legal and both in use here:
  //   flat   — <Route path="projects/:id" />, a sibling of path="projects"
  //   nested — <Route path="tpv"> ... <Route path="view/:id" />
  // so the whole tail is looked for as one path first, then inside the parent's
  // block. Plain string matching, not a built regex: the tail contains ":" and
  // "/" and nothing that needs escaping.
  const spellings = (hay) => hay.includes(`path="${parts[0]}/${tail}"`)
    || hay.includes(`path="${parts[0]}/${tail}/*"`)
  const inBlock = (hay) => hay.includes(`path="${tail}"`) || hay.includes(`path="${tail}/*"`)

  const ok = spellings(routes) || (block !== null && inBlock(block))
  check(`${url} has a route`, ok,
    ok ? '' : `no path="${tail}" inside the ${parts[0]} block — a link to a page that does not exist reads as the page being broken`)
}

check('the TPV vendor link is not the Purchase one',
  !svc.includes('/app/tpv/vendors/{$id}'),
  'the obvious guess is right for Purchase and wrong for TPV')

/* ── projects assign the same people ─────────────────────────────────────── */
group('projects use the same engine, not a second copy of it')

const tabs = read('src/modules/projects/components/ProjectTabs.jsx')
const projApi = read('src/services/projectApi.js')
const svcShared = read('../backend/app/Services/Shared/PartyAssignmentService.php')

check('the picker is shared, not duplicated',
  tabs.includes("import PartyPicker from '@/components/ui/PartyPicker'")
  && detail.includes("import PartyPicker from '@/components/ui/PartyPicker'"),
  'a second copy of the walk is a second place for the rules to drift')

check('the picker takes an accent rather than hard-coding the task purple',
  picker.includes("accent = '#7C3AED'") && picker.includes('avatarStyle(accent)'),
  'a module-scope constant silently painted every caller task-purple')

check('the project tab can assign vendors',
  tabs.includes('Assign vendors') && tabs.includes('projectApi.parties.sync'),
  'it could only ever DERIVE them from the project\'s tasks')

check('projects assign several people at once',
  tabs.includes('multi accent={PROJECT_ACCENT}'))

check('the project tab keeps assigned and derived apart',
  tabs.includes('On this project') && tabs.includes("Working on this project's tasks"),
  'being on the project and doing a task on it are two different statements')

check('the project api posts the full list',
  projApi.includes('party-assignees') && projApi.includes('{ parties }'))

/* ── one table, two subjects ─────────────────────────────────────────────── */
group('the two subjects cannot bleed into each other')

check('every read filters by subject type',
  svcShared.includes('private function rowsFor(') && svcShared.includes("where('subject_type', $subjectType)"),
  'one funnel, so the filter cannot be forgotten')

check('the portal task list is pinned to tasks',
  svcShared.includes("->where('subject_type', PartyAssignee::SUBJECT_TASK)"),
  'a project id read as a task id would show somebody work they were never given')

check('the polymorphic column has no foreign key to tasks',
  !svcShared.includes('task_id'),
  'the column points at a task OR a project — see the drop-FK migration')

console.log(`\n${failures ? `${failures} FAILED` : 'all checks passed'}`)
process.exit(failures ? 1 : 0)

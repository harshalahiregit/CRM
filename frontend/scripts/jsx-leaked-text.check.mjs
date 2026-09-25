/**
 * A comment that is not a comment — source text rendering onto the page.
 *
 * WHY. Between JSX tags, a block comment must be wrapped in braces:
 *
 *     {\/* like this *\/}          a comment
 *      \/* like this *\/           CHILD TEXT — React renders it
 *
 * The second form is valid JSX. It parses, it compiles, it bundles, it ships,
 * and the words appear on the screen. Nothing in the pipeline objects, because
 * nothing is wrong with it as far as the pipeline is concerned — it is a string
 * of characters the author asked to be displayed.
 *
 * That is exactly what happened on the meeting form: a fourteen-line note
 * explaining why the Minutes section had been removed was printed in full,
 * mid-form, to every user scheduling a meeting. It sat there through a build, a
 * merge and a deploy. The only way anyone was ever going to find it was by
 * looking at the page — which is how it was found.
 *
 * Linting would catch it if ESLint ran here; it does not, because the config
 * imports a package that is not installed. So: a real parse, and any JSX text
 * node containing comment syntax is a leak.
 *
 * WHY THE PARSER AND NOT A REGEX. `/*` appears in perfectly good code on nearly
 * every page — in strings, in regexes, in actual comments. Only a parser can
 * say whether a given one is in child position, which is the whole question.
 *
 * Run: npm run check:jsx
 */
import { readFileSync, globSync } from 'node:fs'
import { parse } from '@babel/parser'
import _traverse from '@babel/traverse'

const traverse = _traverse.default ?? _traverse

const files = globSync('src/**/*.{jsx,js}')

const leaks = []
const unparsed = []
let parsed = 0

for (const file of files) {
  const source = readFileSync(file, 'utf8')

  let ast
  try {
    ast = parse(source, {
      sourceType: 'module',
      plugins: ['jsx', 'classProperties', 'optionalChaining', 'nullishCoalescingOperator'],
    })
  } catch (err) {
    // A file this cannot read is not a pass. Silently skipping is how a check
    // ends up reporting success for a file it never looked at.
    unparsed.push(`${file}: ${err.message.split('\n')[0]}`)
    continue
  }

  parsed++

  traverse(ast, {
    JSXText(path) {
      const value = path.node.value

      if (!/\/\*|\*\//.test(value)) return

      const firstLine = value.split('\n').map((s) => s.trim()).find(Boolean) ?? ''

      leaks.push({
        file,
        line: path.node.loc.start.line,
        preview: firstLine.slice(0, 72),
      })
    },
  })
}

if (leaks.length || unparsed.length) {
  console.error('✗ jsx leaked text\n')

  for (const l of leaks) {
    console.error(`  - ${l.file}:${l.line}  renders on the page: ${l.preview || '(blank)'}`)
  }

  if (leaks.length) {
    console.error('\n  Wrap it in braces: {/* … */} rather than /* … */')
  }

  for (const u of unparsed) console.error(`  - could not parse ${u}`)

  console.error(`\n${leaks.length + unparsed.length} problem(s).`)
  process.exit(1)
}

// A check that passes because it found nothing to look at is worse than none.
if (parsed < 100) {
  console.error(`✗ only ${parsed} file(s) parsed — the scan found nothing to check`)
  process.exit(1)
}

console.log(`✓ no comments rendering as text (${parsed} file(s) checked)`)

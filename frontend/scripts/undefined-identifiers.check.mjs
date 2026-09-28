/**
 * Find identifiers a file uses but never declares.
 *
 * This is `eslint --rule no-undef`, which this box cannot run: eslint.config.js
 * imports @eslint/js and that package is not installed, so `npm run lint:crash`
 * exits 0 having linted nothing. A green run that checked nothing is worse than
 * no run at all, which is how a component shipped referencing `canManage` from
 * a scope two functions up — it rendered, React threw a ReferenceError, and the
 * error boundary replaced the whole page with "Something went wrong".
 *
 * That class of bug is invisible to the build (Vite bundles it happily) and to
 * every backend test, and it only appears when a human opens the screen. So it
 * gets its own check.
 *
 * Usage: node scripts/undefined-identifiers.check.mjs [--all]
 *   default   only files changed against master — fast enough for every commit
 *   --all     the whole of src/
 */
import { readFileSync } from 'node:fs';
import { execSync } from 'node:child_process';
import { globSync } from 'node:fs';
import { parse } from '@babel/parser';
import _traverse from '@babel/traverse';

const traverse = _traverse.default ?? _traverse;

/* Globals a browser module may use without declaring. Deliberately short: this
   list is where a real mistake hides, so it holds only what is genuinely
   ambient, not whatever made the run go green. */
const GLOBALS = new Set([
  'window', 'document', 'navigator', 'location', 'history', 'screen', 'console',
  'fetch', 'Request', 'Response', 'Headers', 'FormData', 'URL', 'URLSearchParams',
  'Blob', 'File', 'FileReader', 'AbortController', 'WebSocket', 'EventSource',
  'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'queueMicrotask',
  'requestAnimationFrame', 'cancelAnimationFrame', 'requestIdleCallback',
  'localStorage', 'sessionStorage', 'indexedDB', 'crypto', 'performance',
  'alert', 'confirm', 'prompt', 'atob', 'btoa', 'structuredClone',
  'Image', 'Audio', 'Option', 'Event', 'CustomEvent', 'MutationObserver',
  'IntersectionObserver', 'ResizeObserver', 'DOMParser', 'XMLHttpRequest',
  'Node', 'Element', 'HTMLElement', 'NodeList', 'DocumentFragment',
  'createImageBitmap', 'ImageBitmap', 'OffscreenCanvas', 'CanvasRenderingContext2D',
  'getComputedStyle', 'matchMedia', 'scrollTo', 'open', 'close', 'print',
  'Object', 'Array', 'String', 'Number', 'Boolean', 'Symbol', 'BigInt',
  'Math', 'JSON', 'Date', 'RegExp', 'Error', 'TypeError', 'RangeError',
  'SyntaxError', 'ReferenceError', 'EvalError', 'URIError', 'AggregateError',
  'Promise', 'Map', 'Set', 'WeakMap', 'WeakSet', 'WeakRef', 'Proxy', 'Reflect',
  'ArrayBuffer', 'DataView', 'Int8Array', 'Uint8Array', 'Uint8ClampedArray',
  'Int16Array', 'Uint16Array', 'Int32Array', 'Uint32Array',
  'Float32Array', 'Float64Array', 'BigInt64Array', 'BigUint64Array',
  'Intl', 'globalThis', 'undefined', 'NaN', 'Infinity',
  'isNaN', 'isFinite', 'parseInt', 'parseFloat', 'encodeURI', 'encodeURIComponent',
  'decodeURI', 'decodeURIComponent', 'escape', 'unescape',
  'process', 'React', 'JSX',
]);

const files = process.argv.includes('--all')
  ? globSync('src/**/*.{js,jsx}')
  : changedFiles();

function changedFiles() {
  try {
    // Whatever this branch changed, plus anything not yet committed. A file the
    // author has not touched is not this check's business.
    const out = execSync('git diff --name-only master...HEAD -- "frontend/src" && git diff --name-only -- "frontend/src" && git ls-files --others --exclude-standard -- "frontend/src"',
      { encoding: 'utf8', cwd: '..', stdio: ['ignore', 'pipe', 'ignore'] });
    return [...new Set(out.split('\n').filter(Boolean))]
      .filter(f => /\.jsx?$/.test(f))
      .map(f => f.replace(/^frontend\//, ''));
  } catch {
    return globSync('src/**/*.{js,jsx}');
  }
}

let failures = 0;
let scanned = 0;

for (const file of files) {
  let code;
  try { code = readFileSync(file, 'utf8'); } catch { continue; }
  scanned++;

  let ast;
  try {
    ast = parse(code, {
      sourceType: 'module',
      plugins: ['jsx', 'classProperties', 'optionalChaining', 'nullishCoalescingOperator', 'dynamicImport'],
    });
  } catch (e) {
    console.error(`  ${file}: could not parse — ${e.message}`);
    failures++;
    continue;
  }

  const bad = [];
  traverse(ast, {
    ReferencedIdentifier(path) {
      const { name } = path.node;
      if (GLOBALS.has(name)) return;
      if (path.scope.hasBinding(name, /* noGlobals */ true)) return;
      // JSX member expressions like <Foo.Bar/> resolve on Foo, already handled.
      bad.push({ name, line: path.node.loc?.start.line });
    },
  });

  if (bad.length) {
    // One name can be referenced in ten places; the first is enough to find it.
    const seen = new Map();
    for (const b of bad) if (!seen.has(b.name)) seen.set(b.name, b.line);
    console.error(`\n  ${file}`);
    for (const [name, line] of seen) {
      console.error(`    line ${line}: '${name}' is used but never declared, imported or passed in`);
    }
    failures += seen.size;
  }
}

if (failures) {
  console.error(`\n✗ ${failures} undefined identifier(s) across ${scanned} file(s).`);
  console.error('  Each one throws a ReferenceError the moment its component renders.\n');
  process.exit(1);
}

console.log(`✓ no undefined identifiers (${scanned} file(s) checked)`);

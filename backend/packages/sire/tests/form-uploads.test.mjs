import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';

/**
 * SIRE — a FormData post must declare multipart.
 *
 * WHY THIS EXISTS. The shared axios instance sets a default
 * `Content-Type: application/json`. Axios 1.x reads that inside
 * transformRequest, BEFORE the XHR adapter gets its chance to strip the header
 * and let the browser add a boundary. Seeing a JSON content type with a
 * FormData body, it converts the whole thing:
 *
 *     return hasJSONContentType ? JSON.stringify(formDataToJSON(data)) : data;
 *
 * So the file never leaves as a file. Laravel's `file` rule rejects the plain
 * value that arrives instead and answers 422, and nothing in that 422 points at
 * a Content-Type default three layers up.
 *
 * It cost a real upload to production to find, because no server-side test can
 * see it: PHPUnit posts multipart directly and passes either way. The working
 * pattern was already a few lines below the broken one in the same file.
 *
 * So this reads the client source and checks the one thing the server cannot.
 */

const API = new URL('../../../../frontend/src/services/sireApi.js', import.meta.url);
const PANEL = new URL('../../../../frontend/src/modules/sire/components/DeveloperExport.jsx', import.meta.url);

/** Read one call's full argument text, balancing parens and skipping strings. */
function callArgs(src, openParenIndex) {
  let depth = 0;
  let quote = null;

  for (let i = openParenIndex; i < src.length; i += 1) {
    const ch = src[i];

    if (quote) {
      if (ch === quote && src[i - 1] !== '\\') quote = null;
      continue;
    }
    if (ch === '"' || ch === "'" || ch === '`') { quote = ch; continue; }

    if (ch === '(') depth += 1;
    else if (ch === ')') {
      depth -= 1;
      if (depth === 0) return src.slice(openParenIndex + 1, i);
    }
  }

  return '';
}

test('every FormData upload in sireApi declares multipart', { skip: !existsSync(API) && 'no host client' }, () => {
  const src = readFileSync(API, 'utf8');

  // The variables that actually hold a FormData. Checking for a name like
  // `body` would flag addComment(), which passes an object that happens to
  // use the same word.
  const formVars = new Set(
    [...src.matchAll(/(?:const|let|var)\s+([A-Za-z_$][\w$]*)\s*=\s*new FormData\(/g)].map((m) => m[1]),
  );

  assert.ok(formVars.size > 0, 'expected at least one FormData in the SIRE client');

  const offenders = [];
  let checked = 0;

  for (const m of src.matchAll(/api\.post\(/g)) {
    const args = callArgs(src, m.index + 'api.post'.length);
    if (!args) continue;

    // Does this call pass one of the FormData variables as its body?
    const sendsForm = [...formVars].some((v) => new RegExp(`(^|[,\\s(])${v}\\s*(,|$)`).test(args));
    if (!sendsForm) continue;

    checked += 1;
    if (!/multipart\/form-data/.test(args)) {
      offenders.push(args.split('\n')[0].trim());
    }
  }

  assert.ok(checked > 0, 'found FormData but no api.post sending it — the matcher is wrong');

  assert.deepEqual(
    offenders,
    [],
    'these posts send a FormData body without a multipart Content-Type, so axios '
      + 'will JSON-ify the file and the server will answer 422:\n  '
      + offenders.join('\n  '),
  );
});

test('the upload error path reports the server reason, not a guess', { skip: !existsSync(PANEL) && 'no panel' }, () => {
  const src = readFileSync(PANEL, 'utf8');

  // A 404 here means the endpoint is not deployed yet, which is a completely
  // different problem from an unreadable file and must not be described as one.
  assert.match(src, /status === 404/, 'an un-deployed endpoint must say so');
  assert.match(src, /response\?\.data\?\.message/, "the server's own message must reach the user");
});

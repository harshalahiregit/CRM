import { test } from 'node:test';
import assert from 'node:assert/strict';
import { resolveCanonical, canMarkDuplicate, membersOf, MAX_CHAIN } from './reference/duplicates.mjs';

const chain = (pairs) => new Map(pairs);

test('an issue that is not a duplicate is its own canonical', () => {
  const r = resolveCanonical(chain([[1, null]]), 1);
  assert.equal(r.canonical, 1);
  assert.equal(r.cycle, false);
});

test('a chain resolves to the issue people should actually be reading', () => {
  // A → B → C: the work is on C.
  const r = resolveCanonical(chain([[1, 2], [2, 3], [3, null]]), 1);
  assert.equal(r.canonical, 3);
  assert.deepEqual(r.path, [1, 2, 3]);
});

test('a stored cycle is reported, not looped over', () => {
  const r = resolveCanonical(chain([[1, 2], [2, 1]]), 1);
  assert.equal(r.cycle, true, 'existing bad data must be survivable');
});

test('an issue can never be a duplicate of itself', () => {
  const result = canMarkDuplicate(chain([[1, null]]), 1, 1);
  assert.equal(result.ok, false);
  assert.match(result.reason, /itself/);
});

test('a direct loop is refused with the loop named', () => {
  // B is already a duplicate of A; marking A duplicate of B closes the loop.
  const result = canMarkDuplicate(chain([[2, 1], [1, null]]), 1, 2);
  assert.equal(result.ok, false);
  assert.match(result.reason, /loop/);
  assert.match(result.reason, /1/);
});

test('an indirect loop through a chain is refused', () => {
  // C → B → A. Marking A duplicate of C would close A → C → B → A.
  const result = canMarkDuplicate(chain([[3, 2], [2, 1], [1, null]]), 1, 3);
  assert.equal(result.ok, false);
  assert.match(result.reason, /loop/);
});

test('marking a duplicate of a duplicate flattens to the real issue', () => {
  // B already duplicates C. Marking A duplicate of B must point A at C.
  const result = canMarkDuplicate(chain([[2, 3], [3, null]]), 1, 2);
  assert.equal(result.ok, true);
  assert.equal(result.target, 3, 'chains are flattened on write so reads stay O(1)');
});

test('an ordinary duplicate is allowed', () => {
  const result = canMarkDuplicate(chain([[1, null], [2, null]]), 1, 2);
  assert.deepEqual(result, { ok: true, target: 2 });
});

test('pointing at an issue inside a broken chain is refused', () => {
  const result = canMarkDuplicate(chain([[2, 3], [3, 2]]), 1, 2);
  assert.equal(result.ok, false);
  assert.match(result.reason, /broken duplicate chain/);
});

test('a runaway chain is treated as broken rather than silently truncated', () => {
  const pairs = [];
  for (let i = 1; i <= MAX_CHAIN + 5; i += 1) pairs.push([i, i + 1]);
  const r = resolveCanonical(chain(pairs), 1);
  assert.equal(r.cycle, true);
});

test('members of a canonical issue are found through chains', () => {
  // 1 → 3, 2 → 3, 4 → 2 → 3. All four are the same underlying issue.
  const map = chain([[1, 3], [2, 3], [4, 2], [3, null]]);
  assert.deepEqual(membersOf(map, 3), [1, 2, 4]);
});

test('nothing is deleted: every member still resolves and is countable', () => {
  // The brief is explicit that duplicates are preserved. This asserts the model
  // supports that — the members are still present, still reachable, still counted.
  const map = chain([[1, 2], [2, null], [3, 2]]);
  assert.equal(membersOf(map, 2).length, 2);
  assert.equal(resolveCanonical(map, 1).canonical, 2);
  assert.equal(resolveCanonical(map, 3).canonical, 2);
});

/**
 * SIRE — the declared-context registry.
 *
 * The half of Report Issue that a host controls. Route inference covers most
 * screens; this covers the ones a URL cannot describe, and it has to behave
 * predictably because getting it wrong is worse than not using it: a stale entry
 * means a user on Billing files an issue against Leads, with high confidence and
 * no indication anything went wrong.
 */
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SireScreenContext } from '../resources/js/lib/sire/screenContext.js';

const reset = () => SireScreenContext.clear();

test('a declaration becomes the current context', () => {
  reset();
  SireScreenContext.register({ module: 'sales', screen: 'lead-details', entityId: 10452 });

  assert.deepEqual(SireScreenContext.current(), {
    module: 'sales',
    screen: 'lead-details',
    entityId: '10452',
  });
});

test('entity ids are normalised to strings', () => {
  /**
   * They arrive as numbers from props and strings from URLs. Without this the
   * modal shows 10452 while the payload carries "10452", and a comparison
   * somewhere quietly fails.
   */
  reset();
  SireScreenContext.register({ screen: 's', entityId: 10452 });
  assert.strictEqual(SireScreenContext.current().entityId, '10452');
});

test('unknown fields are dropped, not stored', () => {
  reset();
  SireScreenContext.register({ screen: 's', secret: 'token-abc', password: 'hunter2' });

  assert.deepEqual(Object.keys(SireScreenContext.current()), ['screen']);
});

test('the innermost declaration wins', () => {
  reset();
  SireScreenContext.register({ screen: 'module-shell' });
  SireScreenContext.register({ screen: 'detail-page' });
  SireScreenContext.register({ screen: 'wizard-step-3' });

  assert.equal(SireScreenContext.current().screen, 'wizard-step-3');
  assert.equal(SireScreenContext.depth, 3);
});

test('unregistering removes the right entry, not the last one', () => {
  /**
   * Unmount order is not always the reverse of mount order — a modal can close
   * after the page beneath it re-renders. Popping blindly would leave the wrong
   * screen current.
   */
  reset();
  const offOuter = SireScreenContext.register({ screen: 'outer' });
  SireScreenContext.register({ screen: 'inner' });

  offOuter();

  assert.equal(SireScreenContext.depth, 1);
  assert.equal(SireScreenContext.current().screen, 'inner');
});

test('releasing twice is harmless', () => {
  // React strict mode runs effect cleanups twice. A non-idempotent release would
  // pop somebody else's entry the second time.
  reset();
  SireScreenContext.register({ screen: 'kept' });
  const off = SireScreenContext.register({ screen: 'released' });

  off();
  off();

  assert.equal(SireScreenContext.depth, 1);
  assert.equal(SireScreenContext.current().screen, 'kept');
});

test('an empty declaration registers nothing', () => {
  reset();
  const off = SireScreenContext.register({});

  assert.equal(SireScreenContext.depth, 0);
  assert.equal(typeof off, 'function');
  off();   // must not throw
});

test('null and empty-string fields are ignored', () => {
  reset();
  SireScreenContext.register({ module: 'sales', section: null, screen: '', entityId: undefined });

  assert.deepEqual(SireScreenContext.current(), { module: 'sales' });
});

test('current() returns a copy — callers cannot corrupt the stack', () => {
  reset();
  SireScreenContext.register({ screen: 'original' });

  const snapshot = SireScreenContext.current();
  snapshot.screen = 'mutated';

  assert.equal(SireScreenContext.current().screen, 'original');
});

test('clear() empties everything', () => {
  reset();
  SireScreenContext.register({ screen: 'a' });
  SireScreenContext.register({ screen: 'b' });

  SireScreenContext.clear();

  assert.equal(SireScreenContext.depth, 0);
  assert.equal(SireScreenContext.current(), null);
});

test('all() reports the full stack, outermost first', () => {
  // What makes a cleanup bug visible: "three entries and none popped" cannot be
  // seen from current() alone.
  reset();
  SireScreenContext.register({ screen: 'outer' });
  SireScreenContext.register({ screen: 'inner' });

  assert.deepEqual(SireScreenContext.all().map((e) => e.screen), ['outer', 'inner']);
});

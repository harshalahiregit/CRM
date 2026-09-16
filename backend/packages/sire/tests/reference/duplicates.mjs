/**
 * SIRE duplicate chains — the executable specification.
 *
 * NOT APPLICATION CODE. Mirrored by SireDuplicateService.php.
 *
 * A duplicate points at the issue it duplicates. Those pointers form chains
 * (A → B → C) and, if nothing prevents it, cycles (A → B → A). Both are easy to
 * create by accident: mark A duplicate of B on Monday, then B duplicate of A on
 * Friday when someone reads them the other way round.
 *
 * Rules:
 *   1. The CANONICAL issue is the end of the chain — the one that is not itself
 *      a duplicate. That is the issue people should be reading and working.
 *   2. A link that would close a cycle is REFUSED. Not silently dropped: refused,
 *      with the cycle named, because the person making it has misunderstood
 *      something and should be told.
 *   3. An issue is never its own duplicate.
 *   4. Marking A as duplicate of B, where B is already a duplicate of C, links A
 *      to C directly. Chains are flattened on write so reads stay O(1).
 */

export const MAX_CHAIN = 32;

/**
 * Walk to the end of the chain.
 * @param {Map<number, number|null>} duplicateOf  issue id -> the id it duplicates
 * @returns {{canonical:number, path:number[], cycle:boolean}}
 */
export function resolveCanonical(duplicateOf, startId) {
  const path = [startId];
  const seen = new Set([startId]);
  let current = startId;

  for (let step = 0; step < MAX_CHAIN; step += 1) {
    const next = duplicateOf.get(current) ?? null;
    if (next === null || next === undefined) {
      return { canonical: current, path, cycle: false };
    }
    if (seen.has(next)) {
      // Already-stored cycle: report it rather than loop forever. Existing data
      // can be broken even when new writes are guarded.
      return { canonical: current, path: [...path, next], cycle: true };
    }
    seen.add(next);
    path.push(next);
    current = next;
  }

  // Runaway chain — treat as broken rather than silently truncating.
  return { canonical: current, path, cycle: true };
}

/**
 * May `fromId` be marked a duplicate of `toId`?
 * @returns {{ok:boolean, reason?:string, target?:number}}
 */
export function canMarkDuplicate(duplicateOf, fromId, toId) {
  if (fromId === toId) {
    return { ok: false, reason: 'An issue cannot be a duplicate of itself.' };
  }
  if (toId === null || toId === undefined) {
    return { ok: false, reason: 'Choose the issue this duplicates.' };
  }

  // Rule 4: point at the real issue, not at another pointer.
  const target = resolveCanonical(duplicateOf, toId);
  if (target.cycle) {
    return { ok: false, reason: `The target issue is part of a broken duplicate chain (${target.path.join(' → ')}).` };
  }
  if (target.canonical === fromId) {
    return {
      ok: false,
      reason: `That would create a loop: ${[...target.path, fromId].join(' → ')}.`,
    };
  }

  // Anything currently pointing at fromId would be dragged into the cycle too.
  const inbound = [...duplicateOf.entries()].filter(([, to]) => to === fromId).map(([id]) => id);
  for (const id of inbound) {
    if (id === target.canonical) {
      return { ok: false, reason: `That would create a loop through issue ${id}.` };
    }
  }

  return { ok: true, target: target.canonical };
}

/** Every issue that resolves to this canonical id, excluding the canonical itself. */
export function membersOf(duplicateOf, canonicalId) {
  const members = [];
  for (const [id] of duplicateOf) {
    if (id === canonicalId) continue;
    const r = resolveCanonical(duplicateOf, id);
    if (!r.cycle && r.canonical === canonicalId) members.push(id);
  }
  return members.sort((a, b) => a - b);
}

/**
 * SIRE — the declared-context registry.
 *
 * Deliberately framework-free: a plain module with no React in it, so context can
 * be declared from anywhere a host has code — a route guard, a saga, a legacy
 * jQuery island, an imperative wizard controller. React is one caller, not the
 * only one.
 *
 * DECLARING BEATS INFERRING
 *
 * SIRE infers context from the URL, which covers most screens and costs the host
 * nothing. But some screens genuinely cannot be described by a path: a wizard
 * whose step lives in component state, a console whose entity is chosen in a
 * dropdown, a modal that is really a page. Those screens say what they are, and
 * a screen that names itself is authoritative in a way no pattern match can be.
 *
 * A STACK, NOT A VALUE
 *
 * Nested declarations are legal — a tab inside a detail page inside a module
 * shell — and the most recently registered entry wins. That matches how people
 * think about it: the innermost thing you are looking at is what you are
 * reporting about.
 *
 * COSTS NOTHING UNTIL USED
 *
 * Registering mutates a module-level array and notifies nobody. There is no
 * subscription, no re-render, no observer. Report Issue reads the stack at the
 * moment it opens, and not before — which is the whole reason context capture
 * is free until somebody actually reports something.
 */

/** @type {Array<object>} innermost last */
const stack = [];

/** Fields SIRE understands. Anything else is ignored rather than stored. */
const FIELDS = [
  'module', 'section', 'screen',
  'entityType', 'entityId', 'entityLabel',
  'moduleLabel', 'sectionLabel', 'screenLabel',
  'pageContext',
];

function normalise(descriptor) {
  const entry = {};

  for (const field of FIELDS) {
    const value = descriptor?.[field];
    if (value === undefined || value === null || value === '') continue;

    // Ids arrive as numbers, strings and occasionally objects with a toString.
    // Normalising here means the modal, the payload and the server all see the
    // same thing, rather than 10452 in one place and "10452" in another.
    entry[field] = field === 'entityId' ? String(value) : value;
  }

  return entry;
}

export const SireScreenContext = {
  /**
   * Declare what the user is looking at.
   *
   *   const done = SireScreenContext.register({
   *     module: 'sales', section: 'leads', screen: 'lead-details',
   *     entityType: 'lead', entityId: 10452,
   *   });
   *
   * Returns an unregister function. CALL IT when the screen goes away — an
   * un-popped entry means a user who has navigated to Billing reports an issue
   * against Leads, which is worse than no context at all.
   */
  register(descriptor) {
    const entry = normalise(descriptor);

    if (Object.keys(entry).length === 0) {
      return () => {};   // nothing declared; nothing to undo
    }

    stack.push(entry);

    let released = false;

    return () => {
      if (released) return;   // idempotent: React strict mode calls cleanups twice
      released = true;

      const index = stack.lastIndexOf(entry);
      if (index !== -1) stack.splice(index, 1);
    };
  },

  /** The innermost declaration, or null. A copy — callers must not mutate the stack. */
  current() {
    return stack.length ? { ...stack[stack.length - 1] } : null;
  },

  /**
   * Everything declared, outermost first.
   *
   * Report Issue uses only the innermost, but the full stack is what makes a
   * misbehaving screen diagnosable: "three entries deep and none popped" is a
   * cleanup bug, and it is invisible from current() alone.
   */
  all() {
    return stack.map((entry) => ({ ...entry }));
  },

  /**
   * Drop everything. For a host that routes imperatively and would rather clear
   * on navigation than track individual unregister functions.
   */
  clear() {
    stack.length = 0;
  },

  get depth() {
    return stack.length;
  },
};

export default SireScreenContext;

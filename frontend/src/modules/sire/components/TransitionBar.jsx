/**
 * SIRE — the only place an issue moves.
 *
 * Renders EXACTLY what the server said is available (`available_transitions` on
 * the case payload). It never recomputes legality: a client that decides for
 * itself is a second copy of the rules, and second copies drift.
 *
 * When a transition needs fields the issue does not have yet, clicking it opens a
 * small form asking for precisely those fields — no more, no less.
 */
import { useState } from 'react';
import { decorateTransitions } from '../../../lib/sire/workflow';
import { FIELD_SPECS, PRIORITIES } from './transitionFields';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');
const Modal = sireHostUi('Modal');

function FieldInput({ name, value, onChange, severities = [], users = [], categories = [] }) {
  const spec = FIELD_SPECS[name] ?? { label: name.replace(/_/g, ' '), type: 'text' };
  const cls = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800';

  return (
    <label className="block">
      <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{spec.label}</span>

      {spec.type === 'textarea' ? (
        <textarea rows={4} className={cls} placeholder={spec.placeholder} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
      ) : spec.type === 'priority' ? (
        <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          <option value="">Select…</option>
          {PRIORITIES.map((p) => <option key={p.value} value={p.value}>{p.label}</option>)}
        </select>
      ) : spec.type === 'severity' ? (
        <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          <option value="">Select…</option>
          {severities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
        </select>
      ) : spec.type === 'category' ? (
        <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          <option value="">Select…</option>
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
      ) : spec.type === 'user' ? (
        <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
          <option value="">Select…</option>
          {/* The identity DTO says display_name; reading u.name rendered blank options. */}
          {users.map((u) => <option key={u.id} value={u.id}>{u.display_name ?? u.name}</option>)}
        </select>
      ) : (
        <input className={cls} placeholder={spec.placeholder} value={value ?? ''} onChange={(e) => onChange(e.target.value)} />
      )}

      {spec.help && <span className="mt-1 block text-[11px] text-gray-400">{spec.help}</span>}
    </label>
  );
}

export default function TransitionBar({ available = [], onApply, severities, users, categories, busy }) {
  const [pending, setPending] = useState(null); // the transition awaiting its fields
  const [draft, setDraft] = useState({});

  const transitions = decorateTransitions(available);
  const requiresFor = (action) => available.find((t) => t.action === action)?.requires ?? [];

  // Fields the transition ACCEPTS but does not demand. They render in the same
  // form and are never part of the completeness check, so an optional field can
  // never be the reason a transition will not go through.
  const optionalFor = (action) => available.find((t) => t.action === action)?.optional ?? [];

  if (!transitions.length) {
    return <p className="text-xs text-gray-400">No actions available to you on this issue.</p>;
  }

  const click = (t) => {
    const needed = requiresFor(t.action);
    const extra = optionalFor(t.action);
    if (needed.length === 0 && extra.length === 0) return onApply(t.action, {});
    setDraft({});
    setPending({ ...t, needed, extra });
    return undefined;
  };

  const confirm = async () => {
    await onApply(pending.action, draft);
    setPending(null);
  };

  // Only REQUIRED fields gate the button.
  const complete = pending?.needed.every((f) => String(draft[f] ?? '').trim() !== '');

  return (
    <>
      <div className="flex flex-wrap gap-2">
        {transitions.map((t) => (
          <button
            key={t.action}
            type="button"
            disabled={busy}
            onClick={() => click(t)}
            className={[
              'rounded-lg px-3 py-1.5 text-xs font-medium transition disabled:opacity-50',
              t.primary
                ? 'bg-gray-900 text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900 dark:hover:bg-white'
                : t.destructive
                  ? 'border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950'
                  : 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800',
            ].join(' ')}
          >
            {t.label}
          </button>
        ))}
      </div>

      {pending && (
        <Modal open onClose={() => setPending(null)} title={pending.label}>
          <div className="space-y-3">
            {[...pending.needed, ...(pending.extra ?? [])].map((field) => (
              <FieldInput
                key={field}
                name={field}
                value={draft[field]}
                onChange={(v) => setDraft((d) => ({ ...d, [field]: v }))}
                severities={severities}
                categories={categories}
                users={users}
              />
            ))}
            <div className="flex justify-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
              <button type="button" className="px-3 py-2 text-sm text-gray-500" onClick={() => setPending(null)}>
                Cancel
              </button>
              <AsyncButton onClick={confirm} disabled={!complete}>{pending.label}</AsyncButton>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
}

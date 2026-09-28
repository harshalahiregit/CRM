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

/**
 * A picker with nothing in it is the worst thing this form can render.
 *
 * "No severities" and "we could not reach the server" produce an identical empty
 * dropdown, and the user is left clicking a control that cannot work with no idea
 * why -- which is exactly what happened when the API was down: Triage opened with
 * three blank selects and said nothing at all.
 *
 * So an empty list says which one it is, and what to do about it.
 */
function EmptyChoices({ label, hint }) {
  return (
    <p
      className="rounded-lg px-3 py-2 text-xs"
      style={{ background: 'var(--bg-input)', border: '1px dashed var(--border-input)', color: 'var(--text-muted)' }}
    >
      No {label.toLowerCase()} to choose from. {hint}
    </p>
  );
}

/**
 * SIRE — choosing who an issue goes to.
 *
 * TWO GROUPS, NEVER ONE LIST. Staff and customer contacts are both people an
 * issue can be put against, and a flat dropdown of both is how a production
 * defect gets assigned to a client by mistake. The server says which is which
 * through `kind`; this only renders it.
 *
 * Staff rows carry their Staff Management department and designation, because
 * "Priya Sharma" is only useful to somebody who already knows who Priya is.
 *
 * MULTIPLE, WITH AN OWNER. The first person chosen owns the issue -- every
 * workflow guard is written against a single assignee, and a defect with four
 * equal owners has none. The rest are working it alongside them. The UI says so
 * rather than leaving people to discover it.
 */
/**
 * Split a multi-person choice into the owner and the rest.
 *
 * The server takes one `assignee_id` and a list of `co_assignee_ids`, because
 * every workflow guard is written against a single owner. The picker collects
 * one ordered list and this is where the two shapes meet -- doing it here rather
 * than in the picker keeps "who did the user tick, in what order" separate from
 * "what does the API want".
 */
function toPayload(draft) {
  const out = { ...draft };

  if (Array.isArray(out.assignee_id)) {
    const [owner, ...rest] = out.assignee_id;
    out.assignee_id = owner ?? '';
    out.co_assignee_ids = rest.map(Number);
  }

  return out;
}

function PeoplePicker({ users, value, multiple, onChange, cls }) {
  const [term, setTerm] = useState('');

  const groups = [
    { kind: 'staff', label: 'Staff Management' },
    { kind: 'customer', label: 'Customer' },
  ];

  const describe = (u) => {
    const bits = [u.designation, u.department].filter(Boolean);
    if (u.kind === 'customer' && u.company) bits.unshift(u.company);
    if (!bits.length && u.rosters?.length) bits.push(u.rosters.join(', '));
    return bits.length ? ` — ${bits.join(' · ')}` : '';
  };

  // A single select for one person, checkboxes for several. A multi-select
  // <select> is a control almost nobody can drive without a mouse and a hint.
  if (!multiple) {
    return (
      <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
        <option value="">Select…</option>
        {groups.map(({ kind, label }) => {
          const rows = users.filter((u) => (u.kind ?? 'staff') === kind);
          if (!rows.length) return null;
          return (
            <optgroup key={kind} label={label}>
              {rows.map((u) => (
                <option key={u.id} value={u.id}>
                  {/* The identity says display_name; reading u.name rendered blanks. */}
                  {u.display_name ?? u.name}{describe(u)}
                </option>
              ))}
            </optgroup>
          );
        })}
      </select>
    );
  }

  const chosen = Array.isArray(value) ? value.map(String) : value ? [String(value)] : [];

  const toggle = (id) => {
    const key = String(id);
    onChange(chosen.includes(key) ? chosen.filter((v) => v !== key) : [...chosen, key]);
  };

  // A native <select> gives you type-ahead for free; a list of checkboxes does
  // not, so it has to be put back. Without it, picking one person out of a
  // hundred is scrolling.
  const needle = term.trim().toLowerCase();
  const matches = (u) =>
    !needle ||
    [u.display_name, u.name, u.department, u.designation, u.company, u.role]
      .filter(Boolean)
      .some((field) => String(field).toLowerCase().includes(needle));

  // Somebody already ticked stays visible whatever is typed. Filtering a
  // selection out of sight makes it look unselected, and the next click adds a
  // person the user thought they were replacing.
  const visible = (u) => matches(u) || chosen.includes(String(u.id));

  return (
    <div className="rounded-lg border border-gray-300 dark:border-gray-600">
      <input
        type="search"
        value={term}
        onChange={(e) => setTerm(e.target.value)}
        placeholder="Search by name, department or designation…"
        className="w-full rounded-t-lg border-b border-gray-300 bg-transparent px-2 py-1.5 text-sm outline-none dark:border-gray-600"
      />

      <div className="max-h-52 overflow-y-auto">
      {groups.map(({ kind, label }) => {
        const rows = users.filter((u) => (u.kind ?? 'staff') === kind).filter(visible);
        if (!rows.length) return null;

        return (
          <div key={kind}>
            <p className="sticky top-0 bg-gray-50 px-2 py-1 text-[10px] font-semibold uppercase tracking-wide text-gray-500 dark:bg-gray-900 dark:text-gray-400">
              {label}
            </p>
            {rows.map((u) => {
              const key = String(u.id);
              const index = chosen.indexOf(key);

              return (
                <label
                  key={u.id}
                  className="flex cursor-pointer items-center gap-2 px-2 py-1.5 text-sm hover:bg-gray-50 dark:hover:bg-gray-800"
                >
                  <input
                    type="checkbox"
                    checked={index !== -1}
                    onChange={() => toggle(u.id)}
                    className="shrink-0"
                  />
                  <span className="truncate">
                    {u.display_name ?? u.name}
                    <span className="text-gray-400">{describe(u)}</span>
                  </span>
                  {index === 0 && (
                    <span className="ml-auto shrink-0 rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-medium text-indigo-700 dark:bg-indigo-900 dark:text-indigo-200">
                      owner
                    </span>
                  )}
                </label>
              );
            })}
          </div>
        );
      })}

      {users.filter(visible).length === 0 && (
        <p className="px-2 py-3 text-xs text-gray-400">Nobody matches “{term}”.</p>
      )}
      </div>

      {chosen.length > 1 && (
        <p className="border-t border-gray-200 px-2 py-1.5 text-[11px] text-gray-500 dark:border-gray-700 dark:text-gray-400">
          {chosen.length} people — the first owns the issue, the rest work it with them.
        </p>
      )}
    </div>
  );
}

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
        severities.length === 0 ? (
          <EmptyChoices label="Severities" hint="Either the server is unreachable, or sire:seed-defaults has not been run for this workspace." />
        ) : (
          <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
            <option value="">Select…</option>
            {severities.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
          </select>
        )
      ) : spec.type === 'category' ? (
        categories.length === 0 ? (
          <EmptyChoices label="Categories" hint="Run php artisan sire:seed-defaults, or check the connection." />
        ) : (
          <select className={cls} value={value ?? ''} onChange={(e) => onChange(e.target.value)}>
            <option value="">Select…</option>
            {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
          </select>
        )
      ) : spec.type === 'user' ? (
        users.length === 0 ? (
          <EmptyChoices label="People" hint="Nobody in this workspace can be given engineering work, or the server is unreachable." />
        ) : (
          <PeoplePicker
            users={users}
            value={value}
            multiple={spec.multiple}
            onChange={onChange}
            cls={cls}
          />
        )
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
    await onApply(pending.action, toPayload(draft));
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

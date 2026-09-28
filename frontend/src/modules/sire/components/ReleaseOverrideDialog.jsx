/**
 * SIRE — emergency release override.
 *
 * Written to be slightly uncomfortable to use, on purpose. It names the gates
 * being bypassed, requires a reason from a fixed list and a written justification,
 * and says out loud that the record is permanent — because it is, and because the
 * person filling it in should know that before they do.
 */
import { useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');
const Modal = sireHostUi('Modal');

const REASONS = [
  { value: 'hotfix', label: 'Production hotfix' },
  { value: 'customer_commitment', label: 'Customer commitment' },
  { value: 'regulatory', label: 'Regulatory deadline' },
  { value: 'other', label: 'Other' },
];

const MIN_JUSTIFICATION = 20;

export default function ReleaseOverrideDialog({ open, onClose, evaluation, onSubmit }) {
  const failing = (evaluation?.gates ?? []).filter((g) => g.blocking && ['fail', 'unknown'].includes(g.status));

  const [selected, setSelected] = useState(() => failing.map((g) => g.key));
  const [reason, setReason] = useState('hotfix');
  const [justification, setJustification] = useState('');

  const toggle = (key) =>
    setSelected((s) => (s.includes(key) ? s.filter((k) => k !== key) : [...s, key]));

  const remaining = MIN_JUSTIFICATION - justification.trim().length;
  const ready = selected.length > 0 && remaining <= 0;

  if (!open) return null;

  return (
    <Modal open={open} onClose={onClose} title="Emergency release override">
      <div className="space-y-3">
        <p className="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-900 dark:bg-amber-950 dark:text-amber-200">
          This is recorded permanently against the release, with your name, the gates you bypassed and
          what they said at the time. It cannot be edited afterwards.
        </p>

        <div>
          <span className="mb-1.5 block text-xs font-medium text-gray-600 dark:text-gray-300">
            Gates being overridden
          </span>
          {failing.length === 0 ? (
            <p className="text-xs text-gray-500">
              Nothing is currently blocking this release. An override would be recorded but would not
              change anything.
            </p>
          ) : (
            <ul className="space-y-1">
              {failing.map((gate) => (
                <li key={gate.key}>
                  <label className="flex items-start gap-2 text-sm">
                    <input
                      type="checkbox"
                      className="mt-1"
                      checked={selected.includes(gate.key)}
                      onChange={() => toggle(gate.key)}
                    />
                    <span>
                      {gate.label}
                      <span className="block text-[11px] text-red-600 dark:text-red-400">{gate.detail}</span>
                    </span>
                  </label>
                </li>
              ))}
            </ul>
          )}
        </div>

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Reason</span>
          <select
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          >
            {REASONS.map((r) => <option key={r.value} value={r.value}>{r.label}</option>)}
          </select>
        </label>

        <label className="block">
          <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">
            Justification
          </span>
          <textarea
            rows={4}
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
            placeholder="Why is shipping now the right call, despite these gates?"
            value={justification}
            onChange={(e) => setJustification(e.target.value)}
          />
          {remaining > 0 && (
            <span className="text-[11px] text-gray-400">{remaining} more characters</span>
          )}
        </label>

        <div className="flex items-center justify-end gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
          <button type="button" className="px-3 py-2 text-sm text-gray-500" onClick={onClose}>Cancel</button>
          <AsyncButton
            onClick={() => onSubmit({ gates: selected, reason, justification }).then(onClose)}
            disabled={!ready}
          >
            Authorise override
          </AsyncButton>
        </div>
      </div>
    </Modal>
  );
}

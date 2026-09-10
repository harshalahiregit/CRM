/**
 * SIRE — root cause analysis.
 *
 * Five Whys appear ONLY when the issue is serious enough to warrant them —
 * critical severity, P1, a recurrence, or a reopen. The server decides that from
 * data it already holds and sends `requires_five_whys`; the form does not guess.
 * Showing five text boxes on every trivial defect is how they end up filled with
 * "because it was broken" five times.
 */
import { useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');
const TagInput = sireHostUi('TagInput');

const CATEGORIES = [
  'code', 'design', 'requirements', 'data', 'configuration',
  'infrastructure', 'third_party', 'process', 'human_error', 'testing_gap', 'unknown',
];

const label = (c) => c.replace(/_/g, ' ').replace(/^./, (m) => m.toUpperCase());
const FIELD = 'w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800';

export default function RootCausePanel({ rootCause, requiresFiveWhys, canEdit, canConfirm, onSave, onConfirm }) {
  const [form, setForm] = useState({
    category: rootCause?.category ?? '',
    description: rootCause?.description ?? '',
    contributing_factors: rootCause?.contributing_factors ?? [],
    detection_gap: rootCause?.detection_gap ?? '',
    corrective_action: rootCause?.corrective_action ?? '',
    preventive_action: rootCause?.preventive_action ?? '',
    five_whys: rootCause?.five_whys ?? ['', '', '', '', ''],
  });

  const set = (k, v) => setForm((f) => ({ ...f, [k]: v }));
  const setWhy = (i, v) => setForm((f) => ({ ...f, five_whys: f.five_whys.map((w, j) => (j === i ? v : w)) }));

  const whysComplete = form.five_whys.filter((w) => w.trim()).length;
  const confirmBlocked = requiresFiveWhys && whysComplete < 5;

  return (
    <div className="space-y-3">
      {rootCause?.confirmed_at && (
        <div className="rounded-lg bg-green-50 px-3 py-2 text-xs text-green-800 dark:bg-green-950 dark:text-green-200">
          Confirmed {new Date(rootCause.confirmed_at).toLocaleDateString()}.
          Editing this analysis will clear the confirmation — a sign-off attests to what it said at the time.
        </div>
      )}

      <label className="block">
        <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Category</span>
        <select className={FIELD} value={form.category} disabled={!canEdit} onChange={(e) => set('category', e.target.value)}>
          <option value="">Choose…</option>
          {CATEGORIES.map((c) => <option key={c} value={c}>{label(c)}</option>)}
        </select>
      </label>

      {[
        ['description', 'What actually caused it', 4],
        ['detection_gap', 'Why our own testing did not catch it', 2],
        ['corrective_action', 'Corrective action — fixing this occurrence', 2],
        ['preventive_action', 'Preventive action — stopping the next one', 2],
      ].map(([key, title, rows]) => (
        <label key={key} className="block">
          <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">{title}</span>
          <textarea rows={rows} className={FIELD} value={form[key]} disabled={!canEdit} onChange={(e) => set(key, e.target.value)} />
        </label>
      ))}

      <div>
        <span className="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Contributing factors</span>
        <TagInput value={form.contributing_factors} disabled={!canEdit} onChange={(v) => set('contributing_factors', v)} />
      </div>

      {requiresFiveWhys && (
        <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/30">
          <div className="mb-2 flex items-center justify-between">
            <span className="text-xs font-medium text-amber-900 dark:text-amber-200">
              Five Whys — required before this analysis can be confirmed
            </span>
            <span className="text-[11px] tabular-nums text-amber-700 dark:text-amber-300">{whysComplete}/5</span>
          </div>
          <div className="space-y-2">
            {form.five_whys.map((why, i) => (
              <div key={i} className="flex items-start gap-2">
                <span className="mt-2 w-4 shrink-0 text-right text-[11px] text-amber-700">{i + 1}.</span>
                <input
                  className={FIELD}
                  placeholder={i === 0 ? 'Why did it happen?' : 'And why was that?'}
                  value={why}
                  disabled={!canEdit}
                  onChange={(e) => setWhy(i, e.target.value)}
                />
              </div>
            ))}
          </div>
        </div>
      )}

      <div className="flex items-center gap-2 border-t border-gray-200 pt-3 dark:border-gray-700">
        {canEdit && <AsyncButton onClick={() => onSave(form)}>Save analysis</AsyncButton>}
        {canConfirm && !rootCause?.confirmed_at && (
          <AsyncButton onClick={onConfirm} disabled={confirmBlocked}>Confirm</AsyncButton>
        )}
        {confirmBlocked && (
          <span className="text-[11px] text-gray-500">All five whys are needed before sign-off.</span>
        )}
      </div>
    </div>
  );
}

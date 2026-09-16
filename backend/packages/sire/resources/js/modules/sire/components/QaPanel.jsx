/**
 * SIRE — QA working surface.
 *
 * QA sees the developer's fix summary and dev-testing notes read-only, above
 * their own notes field. Pass and Fail are workflow transitions, so they live in
 * the TransitionBar with everything else rather than being special buttons here —
 * one place where an issue moves, not two.
 *
 * Failing requires notes. The workflow enforces it; this just says so up front.
 */
import { useEffect, useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const ReadOnly = ({ label, body, empty }) => (
  <div>
    <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{label}</h4>
    {body ? (
      <p className="whitespace-pre-wrap rounded-lg border border-gray-200 bg-gray-50 p-2.5 text-sm text-gray-800 dark:border-gray-700 dark:bg-gray-800/50 dark:text-gray-100">
        {body}
      </p>
    ) : (
      <p className="text-xs italic text-gray-400">{empty}</p>
    )}
  </div>
);

export default function QaPanel({ issue, cycles = [], canEdit, onAction }) {
  const [notes, setNotes] = useState(issue.qa_notes ?? '');
  useEffect(() => setNotes(issue.qa_notes ?? ''), [issue.qa_notes]);

  const allowed = (action) => (issue.available_actions ?? []).some((a) => a === action || a?.action === action);
  const qaHistory = cycles.filter((c) => c.phase === 'qa' && c.outcome && c.outcome !== 'abandoned');

  return (
    <section className="space-y-4 rounded-lg border border-violet-200 p-4 dark:border-violet-900">
      <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">QA</h3>

      <ReadOnly label="Developer fix summary" body={issue.fix_summary} empty="The developer has not written one yet." />
      <ReadOnly label="Developer testing" body={issue.dev_test_notes} empty="No developer testing recorded." />

      <div>
        <div className="mb-1 flex items-baseline justify-between">
          <span className="text-xs font-medium text-gray-600 dark:text-gray-300">QA notes</span>
          {allowed('qa_fail') && <span className="text-[10px] text-red-500">required to fail</span>}
        </div>
        <textarea
          rows={4}
          value={notes}
          disabled={!canEdit || !allowed('add_qa_notes')}
          onChange={(e) => setNotes(e.target.value)}
          placeholder="What you ran, on what build, and what happened. On a failure, the step it broke on."
          className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:disabled:bg-gray-900"
        />
        {canEdit && allowed('add_qa_notes') && (
          <div className="mt-1 flex justify-end">
            <AsyncButton onClick={() => onAction('add_qa_notes', { qa_notes: notes })} disabled={notes === (issue.qa_notes ?? '')}>
              Save notes
            </AsyncButton>
          </div>
        )}
      </div>

      {qaHistory.length > 0 && (
        <div>
          <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">
            Previous QA rounds ({qaHistory.length})
          </h4>
          <ul className="space-y-1">
            {qaHistory.map((c) => (
              <li key={c.id} className="rounded border border-gray-200 p-2 text-xs dark:border-gray-700">
                <div className="flex items-center justify-between">
                  <span className="font-medium">
                    Round {c.cycle_no} —{' '}
                    <span className={c.outcome === 'passed' ? 'text-green-600' : 'text-red-600'}>{c.outcome}</span>
                  </span>
                  <span className="text-gray-400">
                    {c.actor_name} · {c.ended_at ? new Date(c.ended_at).toLocaleDateString() : '—'}
                  </span>
                </div>
                {c.notes && <p className="mt-1 whitespace-pre-wrap text-gray-600 dark:text-gray-300">{c.notes}</p>}
              </li>
            ))}
          </ul>
        </div>
      )}
    </section>
  );
}

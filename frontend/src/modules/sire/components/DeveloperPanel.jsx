/**
 * SIRE — developer working surface.
 *
 * Three fields the developer owns, each saved on its own so nothing is lost when
 * the browser is closed mid-thought. Saving is a non-transition action: adding
 * investigation notes on a Tuesday must not change what the board says.
 *
 * "Accept assignment" and "Start development" are separate on purpose — knowing
 * an issue was accepted three days before work began is exactly the sort of thing
 * a lead needs to see.
 */
import { useEffect, useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const Field = ({ label, hint, value, onChange, onSave, disabled, rows = 4, dirty }) => (
  <div>
    <div className="mb-1 flex items-baseline justify-between">
      <span className="text-xs font-medium text-gray-600 dark:text-gray-300">{label}</span>
      {dirty && <span className="text-[10px] text-amber-600">unsaved</span>}
    </div>
    <textarea
      rows={rows}
      value={value ?? ''}
      disabled={disabled}
      onChange={(e) => onChange(e.target.value)}
      placeholder={hint}
      className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm disabled:bg-gray-50 disabled:text-gray-500 dark:border-gray-600 dark:bg-gray-800 dark:disabled:bg-gray-900"
    />
    {!disabled && (
      <div className="mt-1 flex justify-end">
        <AsyncButton onClick={onSave} disabled={!dirty}>Save</AsyncButton>
      </div>
    )}
  </div>
);

export default function DeveloperPanel({ issue, canEdit, onAction, isAssignee }) {
  const [draft, setDraft] = useState({
    investigation_notes: issue.investigation_notes ?? '',
    fix_summary: issue.fix_summary ?? '',
    dev_test_notes: issue.dev_test_notes ?? '',
  });

  // Re-seed when the server sends a newer version (another action, another tab).
  useEffect(() => {
    setDraft({
      investigation_notes: issue.investigation_notes ?? '',
      fix_summary: issue.fix_summary ?? '',
      dev_test_notes: issue.dev_test_notes ?? '',
    });
  }, [issue.investigation_notes, issue.fix_summary, issue.dev_test_notes]);

  const allowed = (action) => (issue.available_actions ?? []).some((a) => a === action || a?.action === action);
  const dirty = (field) => (draft[field] ?? '') !== (issue[field] ?? '');

  return (
    <section className="space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
      <div className="flex items-center justify-between">
        <h3 className="text-sm font-semibold text-gray-800 dark:text-gray-100">Development</h3>
        {issue.assignment_accepted_at ? (
          <span className="text-[11px] text-green-600">
            Accepted {new Date(issue.assignment_accepted_at).toLocaleDateString()}
          </span>
        ) : allowed('accept_assignment') && isAssignee ? (
          <AsyncButton onClick={() => onAction('accept_assignment', {})}>Accept assignment</AsyncButton>
        ) : (
          <span className="text-[11px] text-gray-400">Not yet accepted</span>
        )}
      </div>

      <Field
        label="Investigation notes"
        hint="What you found. Root cause, the file, the query, the reason."
        value={draft.investigation_notes}
        dirty={dirty('investigation_notes')}
        disabled={!canEdit || !allowed('add_investigation_notes')}
        onChange={(v) => setDraft((d) => ({ ...d, investigation_notes: v }))}
        onSave={() => onAction('add_investigation_notes', { investigation_notes: draft.investigation_notes })}
      />

      <Field
        label="Fix summary"
        hint="What changed, and where. QA reads this first — it is required before Ready for QA."
        value={draft.fix_summary}
        dirty={dirty('fix_summary')}
        disabled={!canEdit || !allowed('add_fix_summary')}
        onChange={(v) => setDraft((d) => ({ ...d, fix_summary: v }))}
        onSave={() => onAction('add_fix_summary', { fix_summary: draft.fix_summary })}
      />

      <Field
        label="Developer testing"
        hint="What you tested yourself, and on what. Saves QA repeating it."
        rows={3}
        value={draft.dev_test_notes}
        dirty={dirty('dev_test_notes')}
        disabled={!canEdit || !allowed('submit_dev_testing')}
        onChange={(v) => setDraft((d) => ({ ...d, dev_test_notes: v }))}
        onSave={() => onAction('submit_dev_testing', { dev_test_notes: draft.dev_test_notes })}
      />
    </section>
  );
}

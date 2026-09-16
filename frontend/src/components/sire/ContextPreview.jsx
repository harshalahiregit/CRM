/**
 * SIRE — "we already know where you are" panel.
 *
 * Shows the four things the user would otherwise have to type. The correction
 * affordance appears ONLY when detection is uncertain (confidence !== 'high'),
 * per the brief: the confident path must stay a two-field form.
 */
import { useState } from 'react';

const Row = ({ label, value, muted }) => (
  <div className="flex items-baseline gap-3 py-1">
    <span className="w-20 shrink-0 text-xs uppercase tracking-wide text-gray-400">{label}</span>
    <span className={muted ? 'text-sm text-gray-400 italic' : 'text-sm font-medium text-gray-800 dark:text-gray-100'}>
      {value || 'Not detected'}
    </span>
  </div>
);

export default function ContextPreview({ context, onCorrect }) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState({
    module: context.module || '',
    section: context.section || '',
    screen: context.screen || '',
    entityType: context.entity_type || '',
    entityId: context.entity_id || '',
  });

  const uncertain = context.context_confidence !== 'high';
  const labels = context.labels || {};

  if (editing) {
    return (
      <div className="rounded-lg border border-amber-300 bg-amber-50 p-3 dark:border-amber-700 dark:bg-amber-950/30">
        <p className="mb-2 text-xs text-amber-800 dark:text-amber-200">
          Correct where this issue belongs. Leave a field blank to keep what we detected.
        </p>
        <div className="grid grid-cols-2 gap-2">
          {['module', 'section', 'screen', 'entityType', 'entityId'].map((field) => (
            <label key={field} className="text-xs">
              <span className="mb-1 block text-gray-500">{field}</span>
              <input
                className="w-full rounded border border-gray-300 px-2 py-1 text-sm dark:border-gray-600 dark:bg-gray-800"
                value={draft[field]}
                onChange={(e) => setDraft({ ...draft, [field]: e.target.value })}
              />
            </label>
          ))}
        </div>
        <div className="mt-2 flex gap-2">
          <button
            type="button"
            className="rounded bg-gray-900 px-3 py-1 text-xs text-white dark:bg-gray-100 dark:text-gray-900"
            onClick={() => { onCorrect(draft); setEditing(false); }}
          >
            Use this
          </button>
          <button type="button" className="px-3 py-1 text-xs text-gray-500" onClick={() => setEditing(false)}>
            Cancel
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/50">
      <Row label="Module" value={labels.module} muted={!context.module} />
      <Row label="Section" value={labels.section} muted={!context.section} />
      <Row label="Screen" value={labels.screen} muted={!context.screen} />
      <Row label="Record" value={labels.entity} muted={!context.entity_id} />

      <div className="mt-2 flex items-center justify-between border-t border-gray-200 pt-2 dark:border-gray-700">
        <span className="text-[11px] text-gray-400">
          {context.context_source === 'user'
            ? 'Corrected by you'
            : `Detected automatically${uncertain ? ' — please check' : ''}`}
        </span>
        {uncertain && (
          <button type="button" className="text-[11px] font-medium text-blue-600 hover:underline" onClick={() => setEditing(true)}>
            Not right? Correct it
          </button>
        )}
      </div>

      {context.failed_requests?.length > 0 && (
        <div className="mt-2 border-t border-gray-200 pt-2 dark:border-gray-700">
          <span className="text-[11px] uppercase tracking-wide text-gray-400">Recent failed request</span>
          <div className="mt-1 font-mono text-[11px] text-gray-600 dark:text-gray-300">
            {context.failed_requests[0].method} {context.failed_requests[0].path}
            {' → '}
            {context.failed_requests[0].status || 'network error'}
            {context.failed_requests[0].correlation_ref ? ` (ref ${context.failed_requests[0].correlation_ref})` : ''}
          </div>
        </div>
      )}
    </div>
  );
}

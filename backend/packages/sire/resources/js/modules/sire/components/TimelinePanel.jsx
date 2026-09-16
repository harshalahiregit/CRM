/**
 * SIRE — one timeline, two kinds of entry, visibly different.
 *
 *   system   — what the workflow recorded. Left rail, muted, no controls, EVER.
 *              These come from the shared audit_logs table and there is no API
 *              to edit or delete one. A history someone can rewrite is not a
 *              history.
 *   comment  — what a person typed. Card, avatar initial, editable by its author.
 *
 * The distinction is carried by `kind` from the server, not inferred here.
 */
import { useState } from 'react';
import StatusBadge from './StatusBadge';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const when = (iso) => {
  const d = new Date(iso);
  return `${d.toLocaleDateString()} ${d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}`;
};

function SystemEntry({ entry }) {
  return (
    <li className="relative flex gap-3 py-2 pl-6">
      <span className="absolute left-[7px] top-4 h-1.5 w-1.5 rounded-full bg-gray-300 ring-2 ring-white dark:bg-gray-600 dark:ring-gray-900" />
      <div className="min-w-0 flex-1">
        <div className="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-gray-500">
          {entry.from && entry.to ? (
            <span className="flex items-center gap-1.5">
              <StatusBadge status={entry.from} size="sm" />
              <span aria-hidden>→</span>
              <StatusBadge status={entry.to} size="sm" />
            </span>
          ) : (
            <span className="font-medium text-gray-700 dark:text-gray-200">{entry.title}</span>
          )}
          <span>
            {entry.automatic ? 'automatically' : `by ${entry.actor_name || 'someone'}`}
            {entry.actor_role && !entry.automatic ? ` (${entry.actor_role})` : ''}
          </span>
          <span className="text-gray-400">· {when(entry.at)}</span>
        </div>
        {entry.body && <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">{entry.body}</p>}
      </div>
    </li>
  );
}

function CommentEntry({ entry, onEdit, onDelete }) {
  const [editing, setEditing] = useState(false);
  const [body, setBody] = useState(entry.body ?? '');

  return (
    <li className="relative py-2 pl-6">
      <span className="absolute left-[3px] top-4 h-2.5 w-2.5 rounded-full bg-blue-400 ring-2 ring-white dark:ring-gray-900" />
      <div className="rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
        <div className="mb-1 flex items-center justify-between">
          <span className="text-xs font-medium text-gray-800 dark:text-gray-100">
            {entry.actor_name || 'Someone'}
            {entry.actor_role && <span className="ml-1 font-normal text-gray-400">({entry.actor_role})</span>}
          </span>
          <span className="text-[11px] text-gray-400">
            {when(entry.at)}{entry.edited_at ? ' · edited' : ''}
          </span>
        </div>

        {editing ? (
          <>
            <textarea
              rows={3}
              value={body}
              onChange={(e) => setBody(e.target.value)}
              className="w-full rounded border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800"
            />
            <div className="mt-1 flex justify-end gap-2">
              <button type="button" className="px-2 py-1 text-xs text-gray-500" onClick={() => { setBody(entry.body); setEditing(false); }}>
                Cancel
              </button>
              <AsyncButton onClick={async () => { await onEdit(entry.note_id, body); setEditing(false); }}>Save</AsyncButton>
            </div>
          </>
        ) : (
          <p className="whitespace-pre-wrap text-sm text-gray-700 dark:text-gray-200">{entry.body}</p>
        )}

        {entry.editable && !editing && (
          <div className="mt-1 flex gap-3">
            <button type="button" className="text-[11px] text-gray-400 hover:text-gray-600" onClick={() => setEditing(true)}>Edit</button>
            <button type="button" className="text-[11px] text-gray-400 hover:text-red-600" onClick={() => onDelete(entry.note_id)}>Delete</button>
          </div>
        )}
      </div>
    </li>
  );
}

/**
 * Three kinds, three appearances:
 *   system         a workflow event. Grey, immutable.
 *   comment        a person typed it. Editable by its author.
 *   ai_suggestion  a machine proposed it. Dashed violet, never editable,
 *                  attributed to the MODEL rather than to a person.
 *
 * A reader must be able to tell them apart without knowing the schema.
 */
const KIND_STYLE = {
  system:        'border-l-2 border-gray-200 dark:border-gray-700',
  comment:       'border-l-2 border-blue-300 dark:border-blue-800',
  ai_suggestion: 'border-l-2 border-dashed border-violet-400 dark:border-violet-700',
};

export default function TimelinePanel({ entries = [], onComment, onEditComment, onDeleteComment, canComment }) {
  const [filter, setFilter] = useState('all');
  const [draft, setDraft] = useState('');

  const shown = entries.filter((e) => filter === 'all' || e.kind === filter);
  const counts = {
    all: entries.length,
    system: entries.filter((e) => e.kind === 'system').length,
    comment: entries.filter((e) => e.kind === 'comment').length,
  };

  return (
    <section>
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Activity</h3>
        <div className="flex gap-1 rounded-lg bg-gray-100 p-0.5 text-[11px] dark:bg-gray-800">
          {[['all', 'All'], ['system', 'Events'], ['comment', 'Comments']].map(([key, label]) => (
            <button
              key={key}
              type="button"
              onClick={() => setFilter(key)}
              className={`rounded px-2 py-0.5 ${filter === key ? 'bg-white shadow-sm dark:bg-gray-700' : 'text-gray-500'}`}
            >
              {label} {counts[key]}
            </button>
          ))}
        </div>
      </div>

      {canComment && (
        <div className="mb-3">
          <textarea
            rows={2}
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder="Add a comment…"
            className="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-600 dark:bg-gray-800"
          />
          {draft.trim() && (
            <div className="mt-1 flex justify-end">
              <AsyncButton onClick={async () => { await onComment(draft.trim()); setDraft(''); }}>Comment</AsyncButton>
            </div>
          )}
        </div>
      )}

      {shown.length === 0 ? (
        <p className="text-xs text-gray-400">Nothing here yet.</p>
      ) : (
        <ul className="relative border-l border-gray-200 dark:border-gray-700">
          {shown.map((entry) =>
            entry.kind === 'system'
              ? <SystemEntry key={entry.id} entry={entry} />
              : <CommentEntry key={entry.id} entry={entry} onEdit={onEditComment} onDelete={onDeleteComment} />,
          )}
        </ul>
      )}
    </section>
  );
}

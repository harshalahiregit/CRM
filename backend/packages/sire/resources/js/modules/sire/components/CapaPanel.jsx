/**
 * SIRE — corrective and preventive actions.
 *
 * Verification is shown as a distinct step from completion, because it is one:
 * the person who did the work is not the person who confirms it worked, and the
 * server refuses when they are the same. Surfacing that here stops it reading as
 * an arbitrary error later.
 */
import { useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');
const EmptyState = sireHostUi('EmptyState');

const TYPE_LABEL = { corrective: 'Corrective', preventive: 'Preventive', containment: 'Containment' };
const STATUS_TONE = {
  open: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
  in_progress: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
  completed: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
  verified: 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-200',
  cancelled: 'bg-gray-100 text-gray-400 line-through dark:bg-gray-800',
};

export default function CapaPanel({ actions = [], canManage, onCreate, onStart, onComplete, onVerify }) {
  const [adding, setAdding] = useState(false);
  const [draft, setDraft] = useState({ action_type: 'corrective', title: '', description: '', due_at: '' });

  return (
    <div className="space-y-3">
      {actions.length === 0 && !adding && (
        <EmptyState
          title="No actions raised"
          description="Corrective actions fix this occurrence; preventive actions stop the next one."
        />
      )}

      <ul className="space-y-2">
        {actions.map((a) => (
          <li key={a.id} className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="flex items-center gap-2">
                  <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                    {TYPE_LABEL[a.action_type] ?? a.action_type}
                  </span>
                  <span className={`rounded px-1.5 py-0.5 text-[10px] font-medium ${STATUS_TONE[a.status] ?? ''}`}>
                    {a.status.replace(/_/g, ' ')}
                  </span>
                  {a.effectiveness && (
                    <span className="text-[10px] text-gray-500">— {a.effectiveness}</span>
                  )}
                </div>
                <p className="mt-1 text-sm font-medium">{a.title}</p>
                {a.description && <p className="mt-0.5 text-xs text-gray-500">{a.description}</p>}
                <p className="mt-1 text-[11px] text-gray-400">
                  {a.owner?.name ?? 'Unassigned'}
                  {a.due_at && (
                    <span className={new Date(a.due_at) < new Date() && ['open', 'in_progress'].includes(a.status) ? 'ml-2 text-red-600' : 'ml-2'}>
                      due {new Date(a.due_at).toLocaleDateString()}
                    </span>
                  )}
                </p>
              </div>

              {canManage && (
                <div className="flex shrink-0 gap-1">
                  {a.status === 'open' && <AsyncButton onClick={() => onStart(a)}>Start</AsyncButton>}
                  {a.status === 'in_progress' && <AsyncButton onClick={() => onComplete(a)}>Complete</AsyncButton>}
                  {a.status === 'completed' && <AsyncButton onClick={() => onVerify(a)}>Verify</AsyncButton>}
                </div>
              )}
            </div>

            {a.status === 'completed' && (
              <p className="mt-2 border-t border-gray-100 pt-2 text-[11px] text-gray-500 dark:border-gray-800">
                Awaiting verification by someone other than whoever completed it.
              </p>
            )}
          </li>
        ))}
      </ul>

      {canManage && (adding ? (
        <div className="space-y-2 rounded-lg border border-gray-300 p-3 dark:border-gray-600">
          <div className="flex gap-2">
            <select
              className="rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800"
              value={draft.action_type}
              onChange={(e) => setDraft({ ...draft, action_type: e.target.value })}
            >
              <option value="corrective">Corrective</option>
              <option value="preventive">Preventive</option>
              <option value="containment">Containment</option>
            </select>
            <input
              className="flex-1 rounded-lg border border-gray-300 px-2 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800"
              placeholder="What needs to happen?"
              value={draft.title}
              onChange={(e) => setDraft({ ...draft, title: e.target.value })}
            />
            <input
              type="date"
              className="rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800"
              value={draft.due_at}
              onChange={(e) => setDraft({ ...draft, due_at: e.target.value })}
            />
          </div>
          <div className="flex gap-2">
            <AsyncButton onClick={() => onCreate(draft).then(() => setAdding(false))} disabled={!draft.title.trim()}>
              Raise action
            </AsyncButton>
            <button type="button" className="px-2 text-xs text-gray-500" onClick={() => setAdding(false)}>Cancel</button>
          </div>
        </div>
      ) : (
        <button type="button" className="text-xs font-medium text-blue-600 hover:underline" onClick={() => setAdding(true)}>
          + Raise an action
        </button>
      ))}
    </div>
  );
}

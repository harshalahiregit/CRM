/**
 * SIRE AI — suggestions on an issue.
 *
 * Advisory decoration, never a source of truth. Three things this component is
 * careful about:
 *
 *   1. IT NEVER LOOKS LIKE A DECISION. A suggestion is visually distinct from
 *      everything a person did — different border, an explicit "Suggested by"
 *      line naming the model, and no styling borrowed from the fields it advises.
 *   2. ACCEPTING DOES NOT APPLY. It records the decision and hands back the value
 *      so the ordinary form can be pre-filled. The change itself is made by the
 *      person, through the ordinary endpoint.
 *   3. IT DISAPPEARS QUIETLY. When AI is off — which is the default, and the only
 *      state today — this renders nothing at all. No empty panel, no "enable AI"
 *      upsell on a page someone opened to fix a bug.
 */
import { useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const CONFIDENCE_TONE = (c) => {
  if (c === null || c === undefined) return 'text-gray-400';
  if (c >= 0.8) return 'text-green-600 dark:text-green-400';
  if (c >= 0.5) return 'text-amber-600 dark:text-amber-400';
  return 'text-gray-500';
};

function Suggestion({ suggestion, onDecide, onApply }) {
  const [open, setOpen] = useState(false);
  const { capability_label: label, confidence, evidence, provider, model, payload } = suggestion;

  return (
    <li className="rounded-lg border border-dashed border-violet-300 bg-violet-50/40 p-3 dark:border-violet-800 dark:bg-violet-950/20">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <span className="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-violet-800 dark:bg-violet-900 dark:text-violet-200">
              Suggestion
            </span>
            <span className="text-sm font-medium">{label}</span>
          </div>

          {evidence?.summary && (
            <p className="mt-1 text-xs text-gray-600 dark:text-gray-300">{evidence.summary}</p>
          )}

          {/* Attribution is to the MODEL, never to a person. */}
          <p className="mt-1 text-[11px] text-gray-500">
            Suggested by {[provider, model].filter(Boolean).join(' ') || 'AI'}
            {confidence !== null && confidence !== undefined && (
              <span className={`ml-2 ${CONFIDENCE_TONE(confidence)}`}>
                {Math.round(confidence * 100)}% confidence
              </span>
            )}
          </p>
        </div>

        <div className="flex shrink-0 flex-col gap-1">
          <AsyncButton onClick={() => onDecide(suggestion, 'accepted').then((v) => onApply?.(v))}>
            Use this
          </AsyncButton>
          <button
            type="button"
            className="text-[11px] text-gray-500 hover:text-red-600"
            onClick={() => onDecide(suggestion, 'rejected')}
          >
            Dismiss
          </button>
        </div>
      </div>

      {(evidence?.signals?.length > 0 || payload) && (
        <div className="mt-2 border-t border-violet-200 pt-2 dark:border-violet-900">
          <button
            type="button"
            className="text-[11px] font-medium text-violet-700 hover:underline dark:text-violet-300"
            onClick={() => setOpen((o) => !o)}
          >
            {open ? 'Hide reasoning' : 'Why?'}
          </button>

          {open && (
            <div className="mt-1.5 space-y-1">
              {/* A recommendation nobody can interrogate is not usable evidence. */}
              {(evidence?.signals ?? []).map((signal, i) => (
                <p key={i} className="text-[11px] text-gray-600 dark:text-gray-400">• {signal}</p>
              ))}
              {payload && (
                <pre className="mt-1 overflow-x-auto rounded bg-white/60 p-2 text-[10px] text-gray-600 dark:bg-black/20 dark:text-gray-400">
                  {JSON.stringify(payload, null, 2)}
                </pre>
              )}
            </div>
          )}
        </div>
      )}
    </li>
  );
}

export default function AiSuggestionPanel({ status, suggestions = [], onDecide, onApply }) {
  // Off by default, and off is the only state today. Render nothing rather than
  // an empty panel on a page someone opened to fix a bug.
  if (!status?.enabled) return null;
  if (suggestions.length === 0) return null;

  return (
    <section>
      <h3 className="mb-2 text-[10px] font-medium uppercase tracking-wide text-gray-400">
        Suggestions · advisory only
      </h3>
      <ul className="space-y-2">
        {suggestions.map((s) => (
          <Suggestion key={s.id} suggestion={s} onDecide={onDecide} onApply={onApply} />
        ))}
      </ul>
    </section>
  );
}

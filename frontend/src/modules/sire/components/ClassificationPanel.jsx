/**
 * SIRE — classification recommendations.
 *
 * Four fields, each with a recommendation, a confidence and a reason a person can
 * check against the neighbours listed underneath. Three choices per field:
 * accept, change, reject — and none of them writes anything on its own.
 *
 * Accepting PRE-FILLS the ordinary form. The change is then made by the person,
 * through the ordinary endpoint, as themselves. That is the point: nothing here
 * can overwrite a human decision, because nothing here writes.
 */
import { useState } from 'react';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const FIELD_LABEL = {
  module: 'Module', category: 'Issue type', severity: 'Severity', priority: 'Priority',
};

const confidenceTone = (c) => {
  if (c >= 0.75) return 'text-green-700 dark:text-green-400';
  if (c >= 0.45) return 'text-amber-700 dark:text-amber-400';
  return 'text-gray-500';
};

function Field({ field, result, onAccept, onChange, onReject }) {
  const [changing, setChanging] = useState(false);
  const [value, setValue] = useState(result.value ?? '');

  // An abstention is shown, not hidden. "Not enough similar issues" tells the
  // triager something real: this is new territory.
  if (result.abstained) {
    return (
      <li className="flex items-baseline gap-3 py-1.5">
        <span className="w-20 shrink-0 text-[11px] uppercase tracking-wide text-gray-400">
          {FIELD_LABEL[field]}
        </span>
        <span className="text-xs italic text-gray-400">{result.reason}</span>
      </li>
    );
  }

  return (
    <li className="py-2">
      <div className="flex items-start gap-3">
        <span className="w-20 shrink-0 pt-0.5 text-[11px] uppercase tracking-wide text-gray-400">
          {FIELD_LABEL[field]}
        </span>

        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-baseline gap-2">
            <span className="text-sm font-medium">{result.value}</span>
            <span className={`text-[11px] tabular-nums ${confidenceTone(result.confidence)}`}>
              {Math.round(result.confidence * 100)}% confidence
            </span>
            {result.source === 'captured' && (
              <span className="rounded bg-blue-50 px-1 text-[9px] font-medium uppercase text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                observed
              </span>
            )}
          </div>

          {/* Checkable, not decorative: "7 of 9 similar issues were logged as Bug". */}
          <p className="mt-0.5 text-[11px] text-gray-500">{result.reason}</p>

          {result.tally?.length > 1 && (
            <p className="mt-0.5 text-[10px] text-gray-400">
              Also seen: {result.tally.slice(1).map((t) => `${t.value} (${Math.round(t.share * 100)}%)`).join(', ')}
            </p>
          )}

          {changing && (
            <div className="mt-1.5 flex items-center gap-2">
              <input
                className="rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800"
                value={value}
                onChange={(e) => setValue(e.target.value)}
                placeholder={`Different ${FIELD_LABEL[field].toLowerCase()}`}
              />
              <AsyncButton onClick={() => onChange(field, value).then(() => setChanging(false))}>
                Use mine
              </AsyncButton>
            </div>
          )}
        </div>

        <div className="flex shrink-0 items-center gap-2 text-[11px]">
          <AsyncButton onClick={() => onAccept(field, result.value)}>Accept</AsyncButton>
          <button type="button" className="text-gray-500 hover:underline" onClick={() => setChanging((c) => !c)}>
            Change
          </button>
          <button type="button" className="text-gray-400 hover:text-red-600" onClick={() => onReject(field)}>
            Reject
          </button>
        </div>
      </div>
    </li>
  );
}

export default function ClassificationPanel({ suggestion, onAccept, onChange, onReject }) {
  if (!suggestion) return null;

  const payload = suggestion.payload ?? {};
  const evidence = suggestion.evidence ?? {};

  return (
    <section className="rounded-lg border border-dashed border-violet-300 bg-violet-50/30 p-3 dark:border-violet-800 dark:bg-violet-950/20">
      <header className="mb-1.5 flex items-baseline justify-between gap-2">
        <h3 className="text-[10px] font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-300">
          Suggested classification · advisory
        </h3>
        <span className="text-[10px] text-gray-400">
          {[suggestion.provider, suggestion.model, suggestion.model_version].filter(Boolean).join(' ')}
        </span>
      </header>

      {evidence.summary && <p className="mb-1 text-[11px] text-gray-600 dark:text-gray-300">{evidence.summary}</p>}

      <ul className="divide-y divide-violet-200/60 dark:divide-violet-900/60">
        {['module', 'category', 'severity', 'priority'].map((field) => (
          payload[field] ? (
            <Field
              key={field}
              field={field}
              result={payload[field]}
              onAccept={onAccept}
              onChange={onChange}
              onReject={onReject}
            />
          ) : null
        ))}
      </ul>

      {evidence.references?.length > 0 && (
        <p className="mt-2 border-t border-violet-200 pt-1.5 text-[10px] text-gray-500 dark:border-violet-900">
          Compared against {evidence.references.join(', ')}
        </p>
      )}
    </section>
  );
}

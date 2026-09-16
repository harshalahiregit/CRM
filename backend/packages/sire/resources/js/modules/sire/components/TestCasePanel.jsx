/**
 * SIRE — test cases.
 *
 * Two lists in one panel: tests that exist on the issue, and tests a generator has
 * proposed. The visual difference matters — a proposal is dashed and violet, an
 * accepted test looks like everything else, and the result column only exists on
 * the former.
 *
 * A GENERATED TEST NEVER ARRIVES WITH A RESULT, and this component cannot set one
 * on a suggestion at all: results only appear once a test has been accepted and
 * exists as a record. Recording one is a single explicit act per test — there is
 * deliberately no "mark all passed".
 */
import { useState } from 'react';

const CATEGORY_LABEL = {
  happy_path: 'Happy path', failure_path: 'Failure path', boundary: 'Boundary case',
  permission: 'Permission case', regression: 'Regression case', related_workflow: 'Related workflow',
};

import { testResultToken, chipClasses } from '../../../lib/sire/tokens';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');
const EmptyState = sireHostUi('EmptyState');

function Steps({ item }) {
  return (
    <dl className="mt-1 space-y-0.5 text-[11px]">
      {[['Given', item.given], ['When', item.when], ['Then', item.then]].map(([label, value]) => value && (
        <div key={label} className="flex gap-2">
          <dt className="w-11 shrink-0 font-medium text-gray-400">{label}</dt>
          <dd className="text-gray-600 dark:text-gray-300">{value}</dd>
        </div>
      ))}
    </dl>
  );
}

function ExistingTest({ item, canExecute, onResult, onReset, onRemove }) {
  const [noting, setNoting] = useState(false);
  const [note, setNote] = useState('');

  return (
    <li className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">
              {CATEGORY_LABEL[item.category] ?? item.category}
            </span>
            {item.source === 'ai_suggested' && (
              /* Recorded honestly: a test accepted from a generator is still a
                 generated test, and the register says so. */
              <span className="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] text-violet-800 dark:bg-violet-900 dark:text-violet-200">
                generated
              </span>
            )}
            {(() => {
              const t = testResultToken(item.result);
              return (
                <span className={chipClasses(t, 'sm')}>
                  {t.marker && <span aria-hidden>{t.marker}</span>}
                  {t.label}
                  {item.result && item.executor?.name && ` · ${item.executor.name}`}
                </span>
              );
            })()}
          </div>

          <p className="mt-1 text-sm font-medium">{item.title}</p>
          <Steps item={item} />
          {item.result_note && (
            <p className="mt-1 text-[11px] italic text-gray-500">{item.result_note}</p>
          )}
        </div>

        <div className="flex shrink-0 flex-col items-stretch gap-1 text-[11px]">
          {canExecute && !item.result && (
            <>
              {/* One test, one result, one person. No bulk pass. */}
              <AsyncButton onClick={() => onResult(item, 'passed', note)}>Pass</AsyncButton>
              <button type="button" className="rounded border border-red-200 px-2 py-1 text-red-600 hover:bg-red-50 dark:border-red-800 dark:hover:bg-red-950"
                onClick={() => setNoting((n) => !n)}>
                Fail
              </button>
              <button type="button" className="text-gray-500 hover:underline" onClick={() => onResult(item, 'blocked', note)}>
                Blocked
              </button>
            </>
          )}
          {canExecute && item.result && (
            <button type="button" className="text-gray-500 hover:underline" onClick={() => onReset(item)}>
              Re-run
            </button>
          )}
          <button type="button" className="text-gray-400 hover:text-red-600" onClick={() => onRemove(item)}>
            Remove
          </button>
        </div>
      </div>

      {noting && (
        <div className="mt-2 flex gap-2 border-t border-gray-200 pt-2 dark:border-gray-700">
          <input
            className="flex-1 rounded border border-gray-300 px-2 py-1 text-xs dark:border-gray-600 dark:bg-gray-800"
            placeholder="What failed? QA notes are how a developer reproduces it."
            value={note}
            onChange={(ev) => setNote(ev.target.value)}
          />
          <AsyncButton onClick={() => onResult(item, 'failed', note).then(() => setNoting(false))} disabled={!note.trim()}>
            Record failure
          </AsyncButton>
        </div>
      )}
    </li>
  );
}

function SuggestedTest({ item, checked, onToggle }) {
  return (
    <li className="rounded-lg border border-dashed border-violet-300 bg-violet-50/30 p-3 dark:border-violet-800 dark:bg-violet-950/20">
      <label className="flex cursor-pointer items-start gap-2">
        <input type="checkbox" className="mt-1" checked={checked} onChange={onToggle} />
        <div className="min-w-0">
          <div className="flex flex-wrap items-center gap-2">
            <span className="rounded bg-violet-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-violet-800 dark:bg-violet-900 dark:text-violet-200">
              {CATEGORY_LABEL[item.category] ?? item.category}
            </span>
            {/* No result control here at all. A proposal cannot have one. */}
            <span className="text-[10px] text-gray-400">suggested</span>
          </div>
          <p className="mt-1 text-sm font-medium">{item.title}</p>
          <Steps item={item} />
          {item.rationale && (
            <p className="mt-1 text-[10px] text-gray-500">{item.rationale}</p>
          )}
        </div>
      </label>
    </li>
  );
}

export default function TestCasePanel({ testCases = [], summary, suggestion, canExecute, onAccept, onResult, onReset, onRemove, onAdd }) {
  const suggested = suggestion?.payload?.test_cases ?? [];
  const [selected, setSelected] = useState(() => new Set(suggested.map((_, i) => i)));

  const toggle = (i) => setSelected((s) => {
    const next = new Set(s);
    next.has(i) ? next.delete(i) : next.add(i);
    return next;
  });

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-baseline justify-between gap-2">
        <h3 className="text-[10px] font-medium uppercase tracking-wide text-gray-400">Test cases</h3>
        {summary && (
          <span className="text-[11px] text-gray-500">
            {summary.passed} passed · {summary.failed} failed ·{' '}
            <span className={summary.unrun > 0 ? 'font-medium text-amber-600' : ''}>{summary.unrun} not run</span>
            {summary.generated > 0 && ` · ${summary.generated} generated`}
          </span>
        )}
      </header>

      {testCases.length === 0 && suggested.length === 0 && (
        <EmptyState
          title="No test cases yet"
          description="Add one, or accept a suggested checklist. Nothing is added automatically."
        />
      )}

      {testCases.length > 0 && (
        <ul className="space-y-2">
          {testCases.map((item) => (
            <ExistingTest
              key={item.id}
              item={item}
              canExecute={canExecute}
              onResult={onResult}
              onReset={onReset}
              onRemove={onRemove}
            />
          ))}
        </ul>
      )}

      {suggested.length > 0 && (
        <section>
          <p className="mb-2 text-[11px] text-gray-500">
            {suggestion.evidence?.summary} Nothing below exists yet — tick what is useful, edit it after
            adding, and discard the rest.
          </p>

          <ul className="space-y-2">
            {suggested.map((item, i) => (
              <SuggestedTest key={i} item={item} checked={selected.has(i)} onToggle={() => toggle(i)} />
            ))}
          </ul>

          <div className="mt-2 flex items-center gap-2">
            <AsyncButton
              onClick={() => onAccept(suggested.filter((_, i) => selected.has(i)))}
              disabled={selected.size === 0}
            >
              Add {selected.size} test{selected.size === 1 ? '' : 's'}
            </AsyncButton>
            <span className="text-[11px] text-gray-400">
              Added as unrun. Results are recorded one at a time, by whoever runs them.
            </span>
          </div>
        </section>
      )}

      <button type="button" className="text-xs font-medium text-blue-600 hover:underline" onClick={onAdd}>
        + Write a test case
      </button>
    </div>
  );
}

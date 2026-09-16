/**
 * SIRE — the developer and QA queues.
 *
 * "What is on my plate right now", which is the question an engineer actually
 * opens SIRE to answer. The dashboard answers "how is the team doing"; those are
 * different questions and conflating them produces a screen that serves neither.
 *
 * TWO TABS, ONE PAGE
 *
 * Development and QA are the same shape — a list of issues waiting on you — so
 * they share a page and a table rather than duplicating both. Which queue is
 * showing lives in the URL, so a link to "my QA work" is a link somebody can
 * send.
 *
 * THE UNASSIGNED TOGGLE, AND WHY IT IS ONLY ON QA
 *
 * Development work always has an assignee: an issue reaches IN_DEVELOPMENT by
 * being assigned to someone. QA work does not — an issue can sit in
 * READY_FOR_QA with no QA owner, and if nobody can see it, it sits there
 * forever. The toggle exists for that queue and is deliberately absent from the
 * other, where it would always return nothing.
 */
import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { sireApi, toPage } from '../../../services/sireApi';
import IssueTable from '../components/IssueTable';
import SireStateBoundary from '../components/SireStateBoundary';

const QUEUES = {
  development: {
    label: 'Development',
    fetch: sireApi.myDevelopmentQueue,
    empty: 'Nothing assigned to you for development.',
    unassignable: false,
  },
  qa: {
    label: 'QA',
    fetch: sireApi.myQaQueue,
    empty: 'Nothing waiting on your QA.',
    unassignable: true,
  },
};

export default function MyWorkPage() {
  const [searchParams, setSearchParams] = useSearchParams();

  const queue = QUEUES[searchParams.get('queue')] ? searchParams.get('queue') : 'development';
  const mine = searchParams.get('mine') !== '0';
  const [pageNo, setPageNo] = useState(1);

  const config = QUEUES[queue];

  const params = useMemo(() => ({ mine, page: pageNo }), [mine, pageNo]);

  const query = useQuery({
    queryKey: ['sire', 'queue', queue, params],
    queryFn: () => config.fetch(params).then(toPage),
  });

  // Changing queue or filter resets paging: page 3 of one queue is meaningless
  // in another, and a silently empty page reads as "no work".
  const select = (next) => {
    setPageNo(1);
    setSearchParams(next, { replace: true });
  };

  return (
    <div className="space-y-4">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <h1 className="text-lg font-semibold text-gray-900 dark:text-gray-100">My work</h1>

        <div className="flex items-center gap-2">
          <nav className="flex rounded-lg bg-gray-100 p-0.5 dark:bg-gray-800" aria-label="Queue">
            {Object.entries(QUEUES).map(([key, { label }]) => (
              <button
                key={key}
                type="button"
                onClick={() => select({ queue: key })}
                aria-current={queue === key ? 'page' : undefined}
                className={`rounded-md px-3 py-1 text-sm font-medium transition-colors ${
                  queue === key
                    ? 'bg-white text-gray-900 shadow-sm dark:bg-gray-700 dark:text-gray-100'
                    : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-200'
                }`}
              >
                {label}
              </button>
            ))}
          </nav>

          {config.unassignable && (
            <label className="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-400">
              <input
                type="checkbox"
                checked={!mine}
                onChange={(e) => select({ queue, mine: e.target.checked ? '0' : '1' })}
                className="rounded border-gray-300 dark:border-gray-600"
              />
              Show unassigned
            </label>
          )}
        </div>
      </header>

      <SireStateBoundary
        query={query}
        isEmpty={(query.data?.data ?? []).length === 0}
        emptyTitle={config.empty}
        emptyDescription={
          config.unassignable && mine
            ? 'Tick “Show unassigned” to see QA work nobody has picked up.'
            : undefined
        }
      >
        <IssueTable page={query.data} loading={query.isLoading} onPageChange={setPageNo} />
      </SireStateBoundary>
    </div>
  );
}

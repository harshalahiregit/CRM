/**
 * SIRE — one place that decides what a panel shows.
 *
 * Wraps any query result and renders loading, empty, error, permission, not-found
 * or the children. Before this, each list handled these slightly differently and
 * some rendered an EMPTY STATE when the request had actually failed — "no issues
 * match" and "we could not reach the server" look identical and mean opposite
 * things.
 *
 * Uses the existing kit (ui/EmptyState, ui/AsyncButton) rather than inventing
 * chrome. The skeleton is three grey bars: no shimmer, no pulse, no spinner
 * that outlives the request. A defect tracker that animates more than it informs
 * is one people close.
 */
import { resolveQueryState, STATE, STATE_COPY } from '../../../lib/sire/queryState';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');
const EmptyState = sireHostUi('EmptyState');

function Skeleton({ rows = 3 }) {
  return (
    <div className="space-y-2" aria-busy="true" aria-live="polite">
      <span className="sr-only">Loading</span>
      {Array.from({ length: rows }, (_, i) => (
        <div key={i} className="h-9 rounded bg-gray-100 dark:bg-gray-800" />
      ))}
    </div>
  );
}

function Problem({ title, body, reference, onRetry, retryable }) {
  return (
    <div className="rounded-lg border border-gray-200 p-6 text-center dark:border-gray-700">
      <p className="text-sm font-medium text-gray-900 dark:text-gray-100">{title}</p>
      <p className="mx-auto mt-1 max-w-md text-xs text-gray-500">{body}</p>

      {reference && (
        // Ties the message on screen to the logged stack trace, so "it broke"
        // becomes something support can look up.
        <p className="mt-2 font-mono text-[11px] text-gray-400">Reference {reference}</p>
      )}

      {retryable && onRetry && (
        <div className="mt-3">
          <AsyncButton onClick={onRetry}>Try again</AsyncButton>
        </div>
      )}
    </div>
  );
}

export default function SireStateBoundary({
  query,
  isEmpty,
  emptyTitle = 'Nothing here yet',
  emptyDescription,
  emptyAction,
  skeletonRows = 3,
  children,
}) {
  const { state, reference, retryable } = resolveQueryState({ ...query, isEmpty });

  if (state === STATE.LOADING) return <Skeleton rows={skeletonRows} />;

  if (state === STATE.EMPTY) {
    return <EmptyState title={emptyTitle} description={emptyDescription} action={emptyAction} />;
  }

  if (state !== STATE.READY) {
    const copy = STATE_COPY[state];
    return (
      <Problem
        title={copy.title}
        body={copy.body}
        reference={reference}
        retryable={retryable}
        onRetry={query?.refetch}
      />
    );
  }

  return children;
}

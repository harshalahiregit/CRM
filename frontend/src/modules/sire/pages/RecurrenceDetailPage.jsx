/**
 * SIRE — one recurrence group.
 *
 * RECURRING IS NOT DUPLICATE, AND THE DIFFERENCE IS THE POINT
 *
 * A duplicate is the same report filed twice: one issue, two records, and one of
 * them should be closed. A recurrence is the same fault happening again after it
 * was fixed — several genuinely separate issues that share a cause. Closing them
 * as duplicates would hide exactly the pattern worth seeing.
 *
 * So this page shows the occurrences in time order with the interval between
 * them, because "three times in six weeks, and the gap is shrinking" is the
 * finding. A permanent fix is recorded against the group rather than against any
 * single occurrence — that is what makes the group worth having.
 */
import { useParams, Link } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { sireApi } from '../../../services/sireApi';
import SireStateBoundary from '../components/SireStateBoundary';
import StatusBadge from '../components/StatusBadge';
import CapaPanel from '../components/CapaPanel';

const shortDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');

/** Days between consecutive occurrences — the number that says "getting worse". */
const gapInDays = (later, earlier) => {
  if (!later || !earlier) return null;
  const days = Math.round((new Date(later) - new Date(earlier)) / 86400000);
  return Number.isFinite(days) ? days : null;
};

export default function RecurrenceDetailPage() {
  const { id } = useParams();
  const queryClient = useQueryClient();

  const query = useQuery({
    queryKey: ['sire', 'recurrence', id],
    queryFn: () => sireApi.getRecurrenceGroup(id).then((r) => r.data?.data ?? r.data),
  });

  const group = query.data;

  // Every CAPA action changes what this page shows, so they share one
  // invalidation rather than each patching the cache its own way.
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['sire', 'recurrence', id] });
  const create = useMutation({
    mutationFn: (payload) => sireApi.createGroupCapa(id, payload),
    onSuccess: refresh,
  });
  const start = useMutation({
    mutationFn: (actionId) => sireApi.startCapa(actionId),
    onSuccess: refresh,
  });
  const complete = useMutation({
    mutationFn: ({ actionId, note }) => sireApi.completeCapa(actionId, note),
    onSuccess: refresh,
  });
  const verify = useMutation({
    mutationFn: ({ actionId, payload }) => sireApi.verifyCapa(actionId, payload),
    onSuccess: refresh,
  });
  // Oldest first: a recurrence is a story about time, and reading it backwards
  // makes the shrinking interval harder to see.
  const occurrences = [...(group?.occurrences ?? [])].sort(
    (a, b) => new Date(a.created_at) - new Date(b.created_at),
  );

  return (
    <div className="space-y-4">
      <SireStateBoundary query={query} skeletonRows={4}>
        <header>
          <h1 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
            {group?.title ?? 'Recurrence group'}
          </h1>
          <p className="text-sm text-gray-500 dark:text-gray-400">
            <span className="font-mono">{group?.reference}</span>
            {' · '}
            {occurrences.length} occurrence{occurrences.length === 1 ? '' : 's'}
            {group?.permanent_fix_at
              ? ` · permanent fix recorded ${shortDate(group.permanent_fix_at)}`
              : ' · no permanent fix yet'}
          </p>
        </header>

        {group?.description && (
          <p className="text-sm text-gray-700 dark:text-gray-300">{group.description}</p>
        )}

        <section>
          <h2 className="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">Occurrences</h2>
          <ol className="space-y-2">
            {occurrences.map((issue, index) => {
              const gap = index > 0 ? gapInDays(issue.created_at, occurrences[index - 1].created_at) : null;

              return (
                <li
                  key={issue.id}
                  className="flex flex-wrap items-center gap-3 rounded-lg border border-gray-200 px-3 py-2 dark:border-gray-700"
                >
                  <Link to={`/app/sire/cases/${issue.id}`} className="min-w-0 flex-1 hover:underline">
                    <span className="font-mono text-[11px] text-gray-400">{issue.report_number}</span>
                    <span className="block truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                      {issue.title}
                    </span>
                  </Link>

                  <StatusBadge status={issue.status} size="sm" />
                  <span className="text-xs text-gray-500">{shortDate(issue.created_at)}</span>

                  {gap !== null && (
                    <span className="text-xs text-gray-400">+{gap}d</span>
                  )}
                </li>
              );
            })}
          </ol>
        </section>

        {/* Corrective and preventive actions hang off the GROUP, not an
            occurrence: fixing the third instance again is not a preventive
            action, and recording it against one issue loses that distinction. */}
        <CapaPanel
          actions={group?.actions ?? []}
          canManage={group?.can_manage ?? false}
          onCreate={(payload) => create.mutateAsync(payload)}
          onStart={(actionId) => start.mutateAsync(actionId)}
          onComplete={(actionId, note) => complete.mutateAsync({ actionId, note })}
          onVerify={(actionId, payload) => verify.mutateAsync({ actionId, payload })}
        />
      </SireStateBoundary>
    </div>
  );
}

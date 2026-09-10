/**
 * SIRE — one release, and the decision to ship it.
 *
 * The board answers "what is in flight". This page answers "may this one go,
 * and if not, why not" — which is a different question and the one somebody is
 * about to act on.
 *
 * GATES ARE RECOMPUTED ON OPEN
 *
 * A gate evaluation is only true for the moment it was taken: an issue reopened
 * five minutes ago changes the answer. So the page fetches a fresh evaluation
 * rather than trusting whatever the board was showing, and offers an explicit
 * re-check for the case where somebody has just fixed the blocker and wants to
 * see it clear without reloading.
 *
 * SIRE RECORDS A RELEASE. IT DOES NOT PERFORM ONE.
 *
 * "Mark released" writes down that a deployment happened. Nothing here deploys,
 * builds or touches an environment, and the deployment reference is free text
 * SIRE stores and never interprets.
 */
import { useState } from 'react';
import { useParams } from 'react-router-dom';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { sireApi } from '../../../services/sireApi';
import ReleaseGatePanel from '../components/ReleaseGatePanel';
import ReleaseOverrideDialog from '../components/ReleaseOverrideDialog';
import SireStateBoundary from '../components/SireStateBoundary';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

/** Transitions a person can start here, and what each one needs from them. */
const ACTIONS = [
  { action: 'approve',         label: 'Approve release',  tone: 'primary' },
  { action: 'revoke_approval', label: 'Revoke approval',  tone: 'ghost' },
  { action: 'release',         label: 'Mark released',    tone: 'primary', asks: 'deployment_ref' },
  { action: 'cancel',          label: 'Cancel release',   tone: 'danger',  asks: 'reason' },
  { action: 'roll_back',       label: 'Roll back',        tone: 'danger',  asks: 'reason' },
];

export default function ReleaseDetailPage() {
  const { id } = useParams();
  const queryClient = useQueryClient();
  const [overrideOpen, setOverrideOpen] = useState(false);

  const query = useQuery({
    queryKey: ['sire', 'release', id],
    queryFn: () => sireApi.releaseGovernance(id).then((r) => r.data?.data ?? r.data),
  });

  const detail = query.data;
  const release = detail?.release;

  // One invalidation point. Every mutation below changes gate state, so they all
  // refetch the same key rather than each patching the cache differently.
  const refresh = () => queryClient.invalidateQueries({ queryKey: ['sire', 'release', id] });

  const recheck = useMutation({ mutationFn: () => sireApi.evaluateGates(id), onSuccess: refresh });

  const transition = useMutation({
    mutationFn: (payload) => sireApi.releaseTransition(id, payload),
    onSuccess: refresh,
  });

  const override = useMutation({
    mutationFn: (payload) => sireApi.overrideRelease(id, payload),
    onSuccess: refresh,
  });

  const run = (spec) => {
    const payload = { action: spec.action };

    if (spec.asks === 'reason') {
      // Required by the server for cancel and roll_back. Asking here rather than
      // letting a 422 come back means the person is prompted while they still
      // have the context in their head.
      const reason = window.prompt(`${spec.label} — why?`);
      if (!reason?.trim()) return;
      payload.reason = reason.trim();
    }

    if (spec.asks === 'deployment_ref') {
      const ref = window.prompt('Deployment reference (optional) — build number, tag, pipeline id');
      if (ref?.trim()) payload.deployment_ref = ref.trim();
    }

    transition.mutate(payload);
  };

  const available = (detail?.available_actions ?? ACTIONS.map((a) => a.action));

  return (
    <div className="space-y-4">
      <SireStateBoundary query={query} skeletonRows={5}>
        <header className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h1 className="text-lg font-semibold text-gray-900 dark:text-gray-100">
              {release?.version ?? release?.name ?? `Release ${id}`}
            </h1>
            <p className="text-sm text-gray-500 dark:text-gray-400">
              {release?.status} · {detail?.counts?.total ?? 0} issues
              {release?.owner?.name ? ` · owned by ${release.owner.name}` : ''}
            </p>
          </div>

          <div className="flex flex-wrap items-center gap-2">
            <AsyncButton variant="ghost" onClick={() => recheck.mutate()} loading={recheck.isPending}>
              Re-check gates
            </AsyncButton>

            {ACTIONS.filter((spec) => available.includes(spec.action)).map((spec) => (
              <AsyncButton
                key={spec.action}
                variant={spec.tone}
                onClick={() => run(spec)}
                loading={transition.isPending && transition.variables?.action === spec.action}
                disabled={spec.action === 'approve' && detail?.can_approve === false}
              >
                {spec.label}
              </AsyncButton>
            ))}
          </div>
        </header>

        {transition.isError && (
          <p className="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-800 dark:bg-red-950 dark:text-red-200">
            {transition.error?.response?.data?.message ?? 'That transition was refused.'}
          </p>
        )}

        <ReleaseGatePanel
          evaluation={detail?.gates}
          canOverride={Boolean(detail?.gates?.blocking_failures?.length)}
          onOverride={() => setOverrideOpen(true)}
        />

        {(detail?.overrides ?? []).length > 0 && (
          <section>
            <h2 className="mb-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
              Overrides on this release
            </h2>
            <ul className="space-y-2">
              {detail.overrides.map((o) => (
                <li
                  key={o.id}
                  className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs dark:border-amber-900 dark:bg-amber-950"
                >
                  <span className="font-medium">{o.authorizer?.name ?? 'Unknown'}</span>
                  {' bypassed '}
                  <span className="font-mono">{(o.gates ?? []).join(', ')}</span>
                  {o.revoked_at && <span className="ml-1 font-medium">(revoked)</span>}
                  <p className="mt-1 text-amber-900 dark:text-amber-200">{o.justification}</p>
                </li>
              ))}
            </ul>
          </section>
        )}
      </SireStateBoundary>

      {/*
        onSubmit uses mutateAsync, not mutate: the dialog closes itself on the
        resolved promise, and mutate() returns undefined — .then() on that throws
        inside the dialog, leaving it open over a submitted override.
      */}
      <ReleaseOverrideDialog
        open={overrideOpen}
        onClose={() => setOverrideOpen(false)}
        evaluation={detail?.gates}
        onSubmit={(payload) => override.mutateAsync(payload)}
      />
    </div>
  );
}

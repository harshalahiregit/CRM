/**
 * SIRE — the release board.
 *
 * One row per release with every governance column the brief names. A release
 * governance view, not a deployment console: nothing here starts, stops or
 * touches a deployment, because there is no pipeline in this CRM to touch.
 */
import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { sireApi, toPage } from '../../../services/sireApi';

const Modal = sireHostUi('Modal');
const Button = sireHostUi('Button');
const useToast = sireHostToast();

const RELEASE_TYPES = ['major', 'minor', 'patch', 'hotfix'];

/**
 * Creating a release had no UI at all: sireApi.createRelease existed, the endpoint
 * existed, and the empty state told you to "create a release to start tracking" --
 * with nothing anywhere on the page that could. A release is the thing every gate,
 * note and governance screen hangs off, so without this half the module was
 * unreachable.
 */
function NewReleaseButton({ onCreated }) {
  const [open, setOpen] = useState(false);
  const [version, setVersion] = useState('');
  const [name, setName] = useState('');
  const [type, setType] = useState('patch');
  const [busy, setBusy] = useState(false);
  const toast = useToast();

  const submit = async () => {
    if (!version.trim()) return toast?.error?.('A version is required.');
    setBusy(true);
    try {
      await sireApi.createRelease({
        version: version.trim(),
        name: name.trim() || null,
        release_type: type,
        status: 'planned',
      });
      toast?.success?.(`Release ${version.trim()} created.`);
      setOpen(false);
      setVersion(''); setName(''); setType('patch');
      onCreated?.();
    } catch (err) {
      toast?.error?.(err?.response?.data?.message || 'Could not create that release.');
    } finally {
      setBusy(false);
    }
    return undefined;
  };

  const field = 'w-full rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm dark:border-gray-600 dark:bg-gray-800';

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-gray-700 dark:bg-gray-100 dark:text-gray-900"
      >
        New release
      </button>

      <Modal open={open} onClose={() => setOpen(false)} title="New release">
        <div className="space-y-3">
          <label className="block">
            <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Version</span>
            <input className={field} value={version} onChange={(e) => setVersion(e.target.value)} placeholder="2026.09.1" />
          </label>
          <label className="block">
            <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Name (optional)</span>
            <input className={field} value={name} onChange={(e) => setName(e.target.value)} placeholder="September patch" />
          </label>
          <label className="block">
            <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide text-gray-500">Type</span>
            <select className={field} value={type} onChange={(e) => setType(e.target.value)}>
              {RELEASE_TYPES.map((v) => <option key={v} value={v}>{v}</option>)}
            </select>
          </label>
          <p className="text-[11px] text-gray-500">
            It starts as <strong>planned</strong>. Whether it can ship is decided by its gates, never by this form.
          </p>
          <div className="flex justify-end gap-2 pt-1">
            <button type="button" onClick={() => setOpen(false)} className="rounded-lg border border-gray-300 px-3 py-1.5 text-xs dark:border-gray-600">
              Cancel
            </button>
            <Button onClick={submit} loading={busy}>Create release</Button>
          </div>
        </div>
      </Modal>
    </>
  );
}
import { releaseStateToken, chipClasses } from '../../../lib/sire/tokens';
import { sireHostUi, sireHostToast } from '../../../lib/sire/host';
const EmptyState = sireHostUi('EmptyState');
const TablePagination = sireHostUi('TablePagination');

const NOTE_TONE = {
  published: 'text-green-600', approved: 'text-blue-600',
  pending_approval: 'text-amber-600', draft: 'text-gray-400',
};

const Num = ({ value, tone = '' }) =>
  <span className={`tabular-nums ${value ? tone : 'text-gray-300 dark:text-gray-600'}`}>{value || '—'}</span>;

function NoteCell({ notes }) {
  if (!notes?.internal && !notes?.user) return <span className="text-gray-300">—</span>;
  return (
    <span className="text-[11px]">
      {['internal', 'user'].map((audience) => notes[audience] && (
        <span key={audience} className={`mr-2 ${NOTE_TONE[notes[audience]] ?? ''}`}>
          {audience === 'internal' ? 'Int' : 'User'}: {notes[audience].replace(/_/g, ' ')}
        </span>
      ))}
    </span>
  );
}

export default function ReleaseBoardPage() {
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState('');
  const queryClient = useQueryClient();

  const { data, isLoading } = useQuery({
    queryKey: ['sire', 'release-board', status, page],
    // Same envelope trap as the issue register: unwrapping to the bare array
    // left rows undefined, so a board with two releases rendered "No releases".
    queryFn: () => sireApi.releaseBoard({ status: status || undefined, page }).then(toPage),
    placeholderData: (previous) => previous,
  });

  const rows = data?.data ?? [];

  return (
    <div className="mx-auto max-w-[90rem] space-y-4 p-4 sm:p-6">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold text-gray-900 dark:text-gray-50">Releases</h1>
          <p className="mt-0.5 text-xs text-gray-500">
            Governance only. SIRE records what shipped and whether it should — it does not deploy.
          </p>
        </div>
        <div className="flex items-center gap-2">
        <select
          className="rounded-lg border border-gray-300 px-2 py-1.5 text-xs dark:border-gray-600 dark:bg-gray-800"
          value={status}
          onChange={(e) => { setStatus(e.target.value); setPage(1); }}
        >
          <option value="">All statuses</option>
          <option value="blocked">Blocked</option>
          <option value="ready">Ready</option>
          <option value="approved">Approved</option>
          <option value="released">Released</option>
          <option value="cancelled,rolled_back">Cancelled / rolled back</option>
        </select>
        <NewReleaseButton onCreated={() => queryClient.invalidateQueries({ queryKey: ['sire', 'release-board'] })} />
        </div>
      </header>

      {!isLoading && rows.length === 0 ? (
        <EmptyState title="No releases" description="Create a release to start tracking what ships in it." />
      ) : (
        /* The board is twelve columns wide by design — a governance view is a
           comparison, and hiding columns to fit a phone would make it a worse one.
           It scrolls horizontally inside its own container instead, so the PAGE
           never scrolls sideways. */
        <div className="-mx-4 overflow-x-auto rounded-lg border border-gray-200 sm:mx-0 dark:border-gray-700">
          <table className="w-full min-w-[72rem] text-sm">
            <thead>
              <tr className="border-b border-gray-200 bg-gray-50 text-left text-[10px] uppercase tracking-wide text-gray-500 dark:border-gray-700 dark:bg-gray-800/50">
                <th className="px-3 py-2 font-medium">Version</th>
                <th className="px-2 py-2 text-right font-medium">Issues</th>
                <th className="px-2 py-2 text-right font-medium">Crit</th>
                <th className="px-2 py-2 text-right font-medium">High</th>
                <th className="px-2 py-2 text-right font-medium">QA ✓</th>
                <th className="px-2 py-2 text-right font-medium">QA ✕</th>
                <th className="px-2 py-2 text-right font-medium">Regr</th>
                <th className="px-2 py-2 text-right font-medium">Recur</th>
                <th className="px-2 py-2 text-right font-medium">SLA</th>
                <th className="px-3 py-2 font-medium">Notes</th>
                <th className="px-3 py-2 font-medium">Approval</th>
                <th className="px-3 py-2 font-medium">Gates</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {rows.map((release) => {
                const g = release.governance ?? {};
                return (
                  <tr key={release.id} className="hover:bg-gray-50 dark:hover:bg-gray-800/40">
                    <td className="px-3 py-2">
                      <Link to={`/app/sire/releases/${release.id}`} className="font-medium hover:underline">
                        {release.version}
                      </Link>
                      {release.name && <span className="ml-2 text-[11px] text-gray-500">{release.name}</span>}
                      {(() => {
                        const t = releaseStateToken(release.status);
                        return (
                          <span className={`ml-2 ${chipClasses(t, 'sm')}`}>
                            {t.marker && <span aria-hidden>{t.marker}</span>}
                            {t.label}
                          </span>
                        );
                      })()}
                    </td>
                    <td className="px-2 py-2 text-right"><Num value={g.total_issues} /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.critical} tone="text-red-600 font-medium" /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.high} tone="text-orange-600" /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.qa_passed} tone="text-green-600" /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.qa_failed} tone="text-red-600 font-medium" /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.regression} tone="text-red-600" /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.recurring} tone="text-orange-600" /></td>
                    <td className="px-2 py-2 text-right"><Num value={g.sla_breach} tone="text-red-600" /></td>
                    <td className="px-3 py-2"><NoteCell notes={g.release_notes} /></td>
                    <td className="px-3 py-2 text-[11px] text-gray-600 dark:text-gray-300">
                      {String(g.approval_status ?? '—').replace(/_/g, ' ')}
                    </td>
                    <td className="px-3 py-2">
                      {g.gate_status ? (
                        <span className="flex items-center gap-1.5">
                          <span className={g.gate_status === 'ready' ? 'text-green-600' : 'text-red-600'}>
                            {g.gate_status === 'ready' ? 'Passing' : `${g.gate_blocking_fails} blocking`}
                          </span>
                          {g.gate_override_active && (
                            <span title="Shipping on an authorised override" className="text-amber-600">override</span>
                          )}
                          {g.gate_stale && (
                            /* Never present a stale reading as current. */
                            <span title="Recomputed when you open the release" className="text-[10px] text-gray-400">stale</span>
                          )}
                        </span>
                      ) : (
                        <span className="text-[11px] text-gray-400">not evaluated</span>
                      )}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}

      {data && (
        <TablePagination
          currentPage={data.current_page}
          lastPage={data.last_page}
          total={data.total}
          perPage={data.per_page}
          onPageChange={setPage}
        />
      )}
    </div>
  );
}

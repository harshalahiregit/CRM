/**
 * SIRE — release notes.
 *
 * Generate → submit → approve → publish. Four steps, not one button: approving
 * says the content is right, publishing says it is time, and the record shows
 * which happened when. Publication is gated on an approval because these notes
 * reach an audience outside engineering.
 */
import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useParams } from 'react-router-dom';
import { sireApi } from '../../../services/sireApi';
import { sireHostUi } from '../../../lib/sire/host';
import { sireHostToast } from '../../../lib/sire/host';
const useToast = sireHostToast();
const AsyncButton = sireHostUi('AsyncButton');
const EmptyState = sireHostUi('EmptyState');

const STATUS_TONE = {
  draft: 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
  pending_approval: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-200',
  approved: 'bg-blue-50 text-blue-700 dark:bg-blue-950 dark:text-blue-200',
  published: 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-200',
};

function InternalEntry({ entry }) {
  return (
    <li className="py-1.5">
      <div className="flex items-baseline gap-2">
        <span className="font-mono text-[11px] text-gray-400">{entry.number}</span>
        <span className="text-sm">{entry.title}</span>
        {entry.regression && (
          <span className="rounded bg-red-50 px-1 text-[10px] font-medium text-red-700 dark:bg-red-950 dark:text-red-300">
            regression
          </span>
        )}
      </div>
      <div className="mt-0.5 text-[11px] text-gray-500">
        {[entry.module, entry.type, entry.severity, entry.assignee, entry.root_cause]
          .filter(Boolean).join(' · ')}
      </div>
      {entry.fix_summary && <p className="mt-1 text-xs text-gray-600 dark:text-gray-400">{entry.fix_summary}</p>}
    </li>
  );
}

export default function ReleaseNotesPage() {
  const { noteId } = useParams();
  const qc = useQueryClient();
  const toast = useToast();
  const [audience, setAudience] = useState('internal');

  const { data: note, isLoading } = useQuery({
    queryKey: ['sire', 'release-note', noteId],
    queryFn: () => sireApi.getReleaseNote(noteId).then((r) => r.data?.data ?? r.data),
  });

  const act = useMutation({
    mutationFn: ({ fn }) => fn(noteId),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['sire', 'release-note', noteId] });
      toast?.success?.('Updated.');
    },
    onError: (e) => toast?.error?.(e?.response?.data?.message ?? 'That did not work.'),
  });

  if (isLoading) return <div className="p-6 text-sm text-gray-400">Loading…</div>;
  if (!note) return <EmptyState title="Release notes not found" />;

  const sections = note.sections ?? [];
  const isUserFacing = note.audience === 'user';

  return (
    <div className="mx-auto max-w-4xl space-y-4 p-4 sm:p-6">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 className="text-xl font-semibold">{note.title || note.release?.version}</h1>
          <p className="mt-0.5 text-xs text-gray-500">
            {isUserFacing ? 'User-facing' : 'Internal'} · {note.issue_count} item{note.issue_count === 1 ? '' : 's'}
            {note.generated_at && ` · generated ${new Date(note.generated_at).toLocaleString()}`}
          </p>
        </div>
        <span className={`rounded-full px-2.5 py-1 text-[11px] font-medium ${STATUS_TONE[note.status]}`}>
          {note.status.replace(/_/g, ' ')}
        </span>
      </header>

      {note.status === 'published' && (
        <div className="rounded-lg bg-green-50 px-3 py-2 text-xs text-green-800 dark:bg-green-950 dark:text-green-200">
          Published {new Date(note.published_at).toLocaleString()} by {note.publisher?.name}.
          These notes are frozen — reopening an issue will not rewrite what has already been read.
        </div>
      )}

      {sections.length === 0 ? (
        <EmptyState
          title="Nothing to announce yet"
          description={isUserFacing
            ? 'No shipped issue has a customer-facing summary. Issues without one are deliberately left out rather than published with an internal title.'
            : 'No issues are stamped with this release.'}
        />
      ) : (
        <div className="space-y-5">
          {sections.map((section) => (
            <section key={section.key}>
              <h2 className="mb-1.5 text-sm font-semibold text-gray-900 dark:text-gray-50">{section.label}</h2>
              <ul className={isUserFacing ? 'list-disc space-y-1 pl-5' : 'divide-y divide-gray-100 dark:divide-gray-800'}>
                {section.entries.map((entry, i) => (
                  isUserFacing
                    ? <li key={i} className="text-sm">{entry.summary}{entry.module && <span className="ml-2 text-[11px] text-gray-400">{entry.module}</span>}</li>
                    : <InternalEntry key={i} entry={entry} />
                ))}
              </ul>
            </section>
          ))}
        </div>
      )}

      <footer className="flex flex-wrap items-center gap-2 border-t border-gray-200 pt-4 dark:border-gray-700">
        {note.status === 'draft' && (
          <>
            <AsyncButton onClick={() => act.mutateAsync({ fn: sireApi.regenerateReleaseNote })}>Regenerate</AsyncButton>
            <AsyncButton onClick={() => act.mutateAsync({ fn: sireApi.submitReleaseNote })} disabled={sections.length === 0}>
              Send for approval
            </AsyncButton>
          </>
        )}
        {note.status === 'pending_approval' && (
          <AsyncButton onClick={() => act.mutateAsync({ fn: sireApi.approveReleaseNote })}>Approve</AsyncButton>
        )}
        {note.status === 'approved' && (
          <>
            <AsyncButton onClick={() => act.mutateAsync({ fn: sireApi.publishReleaseNote })}>Publish</AsyncButton>
            <span className="text-[11px] text-gray-500">
              Approved by {note.approver?.name} on {new Date(note.approved_at).toLocaleDateString()}.
            </span>
          </>
        )}
      </footer>
    </div>
  );
}

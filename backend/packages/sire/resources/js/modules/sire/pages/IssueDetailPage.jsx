/**
 * SIRE — the issue screen. One page, role-aware panels.
 *
 * Not two pages. A lead is both a developer and a QA reviewer, and two pages
 * would mean two layouts, two data fetches and two places to fix a bug. Which
 * panels appear is decided by what the SERVER says this user may do
 * (`available_actions`), never by reading the user's role in the browser.
 */
import { useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { sireApi } from '../../../services/sireApi';
import StatusBadge from '../components/StatusBadge';
import WorkflowRail from '../components/WorkflowRail';
import TransitionBar from '../components/TransitionBar';
import ReproductionPanel from '../components/ReproductionPanel';
import CapturedContextPanel from '../components/CapturedContextPanel';
import EvidencePanel from '../components/EvidencePanel';
import DeveloperPanel from '../components/DeveloperPanel';
import QaPanel from '../components/QaPanel';
import TimelinePanel from '../components/TimelinePanel';
import IssueMetaSidebar from '../components/IssueMetaSidebar';
import { MAIN_PATH } from '../../../lib/sire/workflow';
import { sireHostToast } from '../../../lib/sire/host';
const useToast = sireHostToast();

export default function IssueDetailPage() {
  const { id } = useParams();
  const toast = useToast();
  const { user } = useAuth();
  const queryClient = useQueryClient();

  const issueQuery = useQuery({
    queryKey: ['sire', 'issue', id],
    queryFn: () => sireApi.getReport(id).then((r) => r.data?.data ?? r.data),
  });

  const timelineQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'timeline'],
    queryFn: () => sireApi.getTimeline(id).then((r) => r.data?.data ?? r.data),
  });

  // After anything that changes the issue, refresh the issue AND the timeline —
  // precisely those two, not the whole cache.
  const refresh = useCallback(() => {
    queryClient.invalidateQueries({ queryKey: ['sire', 'issue', id] });
    queryClient.invalidateQueries({ queryKey: ['sire', 'register'] });
  }, [queryClient, id]);

  const run = useCallback(async (fn, successMessage) => {
    try {
      await fn();
      refresh();
      if (successMessage) toast?.success?.(successMessage);
    } catch (err) {
      // A 403 is passed through by lib/api.js and must be shown, not swallowed.
      toast?.error?.(err?.response?.data?.message || 'That did not work. Please try again.');
      throw err;
    }
  }, [refresh, toast]);

  const applyTransition = useCallback(
    (action, payload) => run(() => sireApi.transition(id, action, payload), 'Issue updated.'),
    [run, id],
  );
  const recordAction = useCallback(
    (action, payload) => run(() => sireApi.recordAction(id, action, payload), 'Saved.'),
    [run, id],
  );

  if (issueQuery.isLoading) {
    return <div className="p-6 text-sm text-gray-400">Loading issue…</div>;
  }
  if (issueQuery.isError) {
    return <div className="p-6 text-sm text-red-600">This issue could not be loaded, or you do not have access to it.</div>;
  }

  const issue = issueQuery.data;
  const timeline = timelineQuery.data ?? [];

  // Where to rest the rail marker when the issue is off the main path (QA Failed,
  // On Hold, Reopened). The server supplies it; if an older API build does not,
  // derive it from the timeline rather than defaulting the marker to the start,
  // which would read as "no progress" on an issue that reached QA.
  const lastMainPathStatus = issue.last_main_path_status
    ?? timeline.find((e) => e.kind === 'system' && MAIN_PATH.includes(e.to))?.to
    ?? null;
  const can = (action) => (issue.available_actions ?? []).some((a) => a === action || a?.action === action);

  const showDeveloperPanel = can('add_investigation_notes') || can('add_fix_summary')
    || can('submit_dev_testing') || can('accept_assignment') || Boolean(issue.fix_summary);
  const showQaPanel = can('add_qa_notes') || can('start_qa') || can('qa_pass') || can('qa_fail')
    || issue.status?.startsWith('qa_') || Boolean(issue.qa_notes);

  return (
    <div className="mx-auto max-w-6xl space-y-4 p-4 sm:p-6">
      <header className="space-y-3">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2 text-xs text-gray-400">
              <span className="font-mono">{issue.report_number}</span>
              <StatusBadge status={issue.status} size="sm" />
            </div>
            <h1 className="mt-1 text-xl font-semibold text-gray-900 dark:text-gray-50">{issue.title}</h1>
          </div>
        </div>

        <WorkflowRail status={issue.status} lastMainPathStatus={lastMainPathStatus} />

        <div className="border-t border-gray-200 pt-3 dark:border-gray-700">
          <TransitionBar
            available={issue.available_transitions ?? []}
            severities={issue.severity_options ?? []}
            users={issue.assignable_users ?? []}
            onApply={applyTransition}
            busy={issueQuery.isFetching}
          />
        </div>
      </header>

      <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_260px]">
        <main className="space-y-4">
          <ReproductionPanel issue={issue} />
          <CapturedContextPanel issue={issue} context={issue.context} />
          <EvidencePanel
            issueId={issue.id}
            attachments={issue.attachments ?? []}
            canUpload={can('add_qa_notes') || can('add_investigation_notes')}
            onUploaded={refresh}
            toast={toast}
          />

          {showDeveloperPanel && (
            <DeveloperPanel
              issue={issue}
              canEdit
              isAssignee={Number(issue.assignee_id) === Number(user?.id)}
              onAction={recordAction}
            />
          )}

          {showQaPanel && (
            <QaPanel issue={issue} cycles={issue.work_cycles ?? []} canEdit onAction={recordAction} />
          )}

          <TimelinePanel
            entries={timeline}
            canComment
            onComment={(body) => run(() => sireApi.addComment(id, body), 'Comment added.')}
            onEditComment={(noteId, body) => run(() => sireApi.editComment(id, noteId, body), 'Comment updated.')}
            onDeleteComment={(noteId) => run(() => sireApi.deleteComment(id, noteId), 'Comment deleted.')}
          />
        </main>

        <IssueMetaSidebar issue={issue} sla={issue.sla} />
      </div>
    </div>
  );
}

/**
 * SIRE — the issue screen. One page, role-aware panels.
 *
 * Not two pages. A lead is both a developer and a QA reviewer, and two pages
 * would mean two layouts, two data fetches and two places to fix a bug. Which
 * panels appear is decided by what the SERVER says this user may do
 * (`available_actions`), never by reading the user's role in the browser.
 */
import { useState, useCallback } from 'react';
import { useParams } from 'react-router-dom';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { sireApi, toIssue, toAttachments } from '../../../services/sireApi';
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
import RootCausePanel from '../components/RootCausePanel';
import TestCasePanel from '../components/TestCasePanel';
import RelationsPanel from '../components/RelationsPanel';
import KbLinksPanel from '../components/KbLinksPanel';
import AiSuggestionPanel from '../components/AiSuggestionPanel';
import ClassificationPanel from '../components/ClassificationPanel';
import DuplicateCandidatesPanel from '../components/DuplicateCandidatesPanel';
import RootCauseSuggestionPanel from '../components/RootCauseSuggestionPanel';
import KnowledgeSuggestionsPanel from '../components/KnowledgeSuggestionsPanel';
import { MAIN_PATH } from '../../../lib/sire/workflow';
import { sireHostToast, sireHostAuth, sireHostUi } from '../../../lib/sire/host';
const Modal = sireHostUi('Modal');
const AsyncButton = sireHostUi('AsyncButton');
const useToast = sireHostToast();
// Through the bridge, not a direct import of the host's AuthContext: this file
// called useAuth() without importing it, which threw "useAuth is not defined"
// and blanked the whole case page. Importing the host's hook would have fixed
// the crash and broken SIRE's CRM-agnosticism (sire:architecture checks for
// exactly that), so it goes through the seam built for it.
const useCurrentUser = sireHostAuth();

/**
 * A titled card for the case page's sections.
 *
 * The Phase 2 panels ship without headings of their own, so mounted bare they
 * read as a run of loose form fields after Evidence with nothing saying what
 * they are. This gives each one the app's card surface and a caps label, the
 * same treatment Description and Captured Context already have.
 */
function CasePanel({ title, note, children }) {
  return (
    <section
      className="rounded-2xl p-4"
      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}
    >
      <div className="mb-3 flex items-baseline justify-between gap-3">
        <h3 className="label-caps text-[11px]">{title}</h3>
        {note && <span className="text-[11px]" style={{ color: 'var(--text-faint)' }}>{note}</span>}
      </div>
      {children}
    </section>
  );
}

/**
 * The six categories the server accepts. Two are mandatory in the workflow's own
 * terms -- a fix that breaks the happy path is not a fix, and the failure path is
 * the test the issue itself is asking for.
 */
const TEST_CATEGORIES = [
  ['happy_path', 'Happy path'],
  ['failure_path', 'Failure path'],
  ['boundary', 'Boundary'],
  ['permission', 'Permission'],
  ['regression', 'Regression'],
  ['related_workflow', 'Related workflow'],
];

export default function IssueDetailPage() {
  const { id } = useParams();
  const toast = useToast();
  const user = useCurrentUser();
  const queryClient = useQueryClient();
  const [newTestCase, setNewTestCase] = useState(null);

  const issueQuery = useQuery({
    queryKey: ['sire', 'issue', id],
    queryFn: () => sireApi.getReport(id).then(toIssue),
  });

  // Evidence is its own endpoint. EvidencePanel was reading issue.attachments,
  // a key the detail payload has never carried -- so an uploaded screenshot
  // always rendered as "No screenshots or files attached".
  const attachmentsQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'attachments'],
    queryFn: () => sireApi.listAttachments(id).then((r) => toAttachments(r, id)),
  });

  // The pickers were fed issue.severity_options / issue.assignable_users -- keys
  // the detail payload has never carried. Every dropdown in every transition form
  // was therefore EMPTY, which made triage and assignment impossible from the UI
  // even though both endpoints worked. These are the real lists.
  const optionsQuery = useQuery({
    queryKey: ['sire', 'dashboard', 'options'],
    queryFn: () => sireApi.dashboardOptions().then((r) => r.data?.data ?? r.data),
    staleTime: 5 * 60 * 1000,
  });

  // Phase 2 lives behind these four endpoints. Every one of them worked; nothing
  // ever called them, because the panels that read them were never mounted --
  // so root cause, test cases, relations and KB links were unreachable features
  // in a module that already had the tables, services and routes for them.
  const rootCauseQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'root-cause'],
    queryFn: () => sireApi.getRootCause(id).then((r) => r.data?.data ?? r.data),
  });

  const relationsQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'relations'],
    queryFn: () => sireApi.getRelations(id).then((r) => r.data?.data ?? r.data),
  });

  const testCasesQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'test-cases'],
    queryFn: () => sireApi.testCases(id).then((r) => r.data?.data ?? r.data),
  });

  const kbLinksQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'kb-links'],
    queryFn: () => sireApi.listKbLinks(id).then((r) => r.data?.data ?? r.data),
  });

  // AI is OFF by default, and every capability is advisory. The panels are only
  // mounted when a tenant has actually opted in -- an install with AI off should
  // not carry five empty suggestion boxes down its case page.
  const aiStatusQuery = useQuery({
    queryKey: ['sire', 'ai', 'status'],
    queryFn: () => sireApi.aiStatus().then((r) => r.data?.data ?? r.data),
    staleTime: 10 * 60 * 1000,
  });

  const aiEnabled = Boolean(aiStatusQuery.data?.enabled);

  const aiSuggestionsQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'ai'],
    queryFn: () => sireApi.aiSuggestions(id).then((r) => r.data?.data ?? r.data),
    enabled: aiEnabled,
  });

  const timelineQuery = useQuery({
    queryKey: ['sire', 'issue', id, 'timeline'],
    queryFn: () => sireApi.getTimeline(id).then((r) => r.data?.data ?? r.data),
  });

  // After anything that changes the issue, refresh the issue AND the timeline —
  // precisely those two, not the whole cache.
  const refresh = useCallback(() => {
    // Prefix match pulls the issue AND everything hanging off it -- timeline,
    // attachments, root cause, relations, test cases, kb links -- so a panel
    // cannot be left showing what was true before the action.
    queryClient.invalidateQueries({ queryKey: ['sire', 'issue', id], refetchType: 'all' });
    queryClient.invalidateQueries({ queryKey: ['sire', 'register'] });
    queryClient.invalidateQueries({ queryKey: ['sire', 'dashboard'] });
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
            severities={optionsQuery.data?.severities ?? []}
            users={optionsQuery.data?.assignees ?? []}
            categories={optionsQuery.data?.types ?? []}
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
            attachments={attachmentsQuery.data ?? []}
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

          {/* ---- Phase 2: why it broke, and what proves it is fixed ---- */}
          <CasePanel title="Root cause analysis" note="Confirmed analyses feed defect trends">
          <RootCausePanel
            // The endpoint answers {root_cause, requires_five_whys}; the panel wants
            // the record itself.
            rootCause={rootCauseQuery.data?.root_cause}
            requiresFiveWhys={Boolean(rootCauseQuery.data?.requires_five_whys)}
            canEdit={can('add_investigation_notes') || can('add_fix_summary') || Boolean(issue.assignee_id)}
            canConfirm={can('triage') || can('assign')}
            onSave={(payload) => run(() => sireApi.saveRootCause(id, payload), 'Root cause saved.')}
            onConfirm={(payload) => run(() => sireApi.confirmRootCause(id, payload), 'Root cause confirmed.')}
          />
          </CasePanel>

          <CasePanel title="Test cases" note="What proves the fix, and that it stays fixed">
          {/*
            The panel hands back the test case OBJECT and the result as separate
            arguments -- onResult(item, 'passed', note) -- not an id and a payload.
            Wired the other way round, every button posted to
            /test-cases/[object Object]/result and nothing worked.

            onAdd takes no arguments at all: it is a request to open an authoring
            form, which the panel does not ship. That form is below.
          */}
          <TestCasePanel
            testCases={testCasesQuery.data?.test_cases ?? []}
            summary={testCasesQuery.data?.summary}
            // The server is the authority here: record() refuses unless the issue
            // is in development or QA and the actor holds sire.qa.execute. Gating
            // the client on available TRANSITIONS instead meant an issue sitting
            // in development showed no Pass/Fail at all -- a button hidden from
            // someone the server would have accepted.
            canExecute={['in_development', 'ready_for_qa', 'qa_in_progress', 'qa_failed', 'qa_passed'].includes(issue.status)}
            onAdd={() => setNewTestCase({ category: 'happy_path', title: '', given: '', when: '', then: '' })}
            onAccept={(cases) => run(
              () => sireApi.addTestCases(id, { test_cases: cases.map((c) => ({ ...c, source: 'ai_suggested' })) }),
              'Test cases added.',
            )}
            onResult={(item, result, note) => run(
              () => sireApi.recordTestResult(item.id, { result, note: note || null }),
              'Result recorded.',
            )}
            onReset={(item) => run(() => sireApi.resetTestResult(item.id), 'Result cleared.')}
            onRemove={(item) => run(() => sireApi.removeTestCase(item.id), 'Test case removed.')}
          />
          </CasePanel>

          <CasePanel title="Related issues" note="Duplicates, regressions and links">
          <RelationsPanel
            relations={relationsQuery.data}
            canEdit={can('triage') || can('assign')}
            onClearRegression={() => run(() => sireApi.clearRegression(id), 'Regression flag cleared.')}
          />
          </CasePanel>

          <CasePanel title="Knowledge base" note="SIRE proposes; the KB owner publishes">
          <KbLinksPanel
            links={kbLinksQuery.data?.links ?? kbLinksQuery.data ?? []}
            canAuthor={can('triage') || can('assign')}
            resolved={Boolean(issue.closed_at) || issue.status === 'released' || issue.status === 'closed'}
            onLink={(payload) => run(() => sireApi.linkKbArticle(id, payload), 'Article linked.')}
            onCreateArticle={(payload) => run(() => sireApi.draftKbArticle(id, payload), 'Draft article created.')}
            onUnlink={(linkId) => run(() => sireApi.unlinkKbArticle(linkId), 'Link removed.')}
          />
          </CasePanel>

          {aiEnabled && (
            <CasePanel title="AI assistance" note="Advisory only — nothing is applied automatically">
              <div className="space-y-4">
                <AiSuggestionPanel
                  status={aiStatusQuery.data}
                  suggestions={aiSuggestionsQuery.data?.suggestions ?? []}
                  onDecide={(sid, decision) => run(() => sireApi.aiDecide(sid, decision), 'Decision recorded.')}
                />
                <ClassificationPanel suggestion={aiSuggestionsQuery.data?.classification} />
                <DuplicateCandidatesPanel suggestion={aiSuggestionsQuery.data?.duplicates} />
                <RootCauseSuggestionPanel suggestion={aiSuggestionsQuery.data?.root_cause} />
                <KnowledgeSuggestionsPanel suggestion={aiSuggestionsQuery.data?.knowledge} />
              </div>
            </CasePanel>
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

      {/* Authoring a test case. TestCasePanel calls onAdd with NO arguments -- it
          asks the host to open a form and ships none of its own, which is why
          "+ Write a test case" appeared to do nothing at all. */}
      <Modal open={Boolean(newTestCase)} onClose={() => setNewTestCase(null)} title="Write a test case">
        {newTestCase && (
          <div className="space-y-3">
            <label className="block">
              <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>Category</span>
              <select
                className="w-full rounded-lg px-2.5 py-1.5 text-sm"
                style={{ background: 'var(--bg-input)', border: '1px solid var(--border-input)', color: 'var(--text-body)' }}
                value={newTestCase.category}
                onChange={(e) => setNewTestCase((c) => ({ ...c, category: e.target.value }))}
              >
                {TEST_CATEGORIES.map(([v, l]) => <option key={v} value={v}>{l}</option>)}
              </select>
            </label>

            {[['title', 'Title', 'Credit note total includes GST'],
              ['given', 'Given', 'a credit note with one taxable line'],
              ['when', 'When', 'it is saved'],
              ['then', 'Then', 'the total includes GST at the line rate']].map(([field, label, placeholder]) => (
              <label className="block" key={field}>
                <span className="mb-1 block text-[11px] font-medium uppercase tracking-wide" style={{ color: 'var(--text-muted)' }}>
                  {label}{field === 'title' ? '' : ' (optional)'}
                </span>
                <input
                  className="w-full rounded-lg px-2.5 py-1.5 text-sm"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border-input)', color: 'var(--text-body)' }}
                  placeholder={placeholder}
                  value={newTestCase[field]}
                  onChange={(e) => setNewTestCase((c) => ({ ...c, [field]: e.target.value }))}
                />
              </label>
            ))}

            <p className="text-[11px]" style={{ color: 'var(--text-faint)' }}>
              A test is created with no result. Only a person with QA rights can record one.
            </p>

            <div className="flex justify-end gap-2 pt-1">
              <button type="button" className="rounded-lg px-3 py-1.5 text-xs" style={{ border: '1px solid var(--border)' }} onClick={() => setNewTestCase(null)}>
                Cancel
              </button>
              <AsyncButton
                disabled={!newTestCase.title.trim()}
                onClick={async () => {
                  await run(
                    () => sireApi.addTestCases(id, { test_cases: [{ ...newTestCase, source: 'human' }] }),
                    'Test case added.',
                  );
                  setNewTestCase(null);
                }}
              >
                Add test case
              </AsyncButton>
            </div>
          </div>
        )}
      </Modal>
    </div>
  );
}
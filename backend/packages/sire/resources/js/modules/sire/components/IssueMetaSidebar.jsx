import { slaToken, chipClasses } from '../../../lib/sire/tokens';
import StatusBadge from './StatusBadge';
import { priorityClasses, priorityLabel } from './transitionFields';


const Row = ({ label, children }) => (
  <div className="flex items-baseline justify-between gap-3 py-1.5">
    <span className="text-[11px] uppercase tracking-wide text-gray-400">{label}</span>
    <span className="text-right text-sm text-gray-800 dark:text-gray-100">{children ?? <span className="italic text-gray-400">—</span>}</span>
  </div>
);

export default function IssueMetaSidebar({ issue, sla }) {
  return (
    <aside className="space-y-3">
      <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <Row label="Status"><StatusBadge status={issue.status} size="sm" /></Row>
        <Row label="Severity">{issue.severity?.name}</Row>
        <Row label="Priority">
          {issue.priority && (
            <span className={`rounded-full px-2 py-0.5 text-[11px] font-medium ring-1 ring-inset ${priorityClasses(issue.priority)}`}>
              {priorityLabel(issue.priority)}
            </span>
          )}
        </Row>
        {issue.resolution && <Row label="Resolution">{issue.resolution.replace(/_/g, ' ')}</Row>}
        {issue.reopen_count > 0 && <Row label="Reopened">{issue.reopen_count}×</Row>}
      </div>

      <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <Row label="Reporter">{issue.reporter?.name ?? issue.reporter_name}</Row>
        <Row label="Developer">{issue.assignee?.name}</Row>
        <Row label="QA">{issue.qa_assignee?.name}</Row>
        <Row label="Tenant">{issue.tenant?.name}</Row>
      </div>

      <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
        <Row label="Module">{issue.module_label ?? issue.module}</Row>
        <Row label="Screen">{issue.screen_label ?? issue.screen}</Row>
        <Row label="Record">
          {issue.related_url
            ? <a className="text-blue-600 hover:underline" href={issue.related_url}>{issue.related_label}</a>
            : issue.related_label}
        </Row>
      </div>

      {sla && (
        <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
          <Row label="SLA">
            <span className={`font-medium ${slaToken(sla.state).classes ?? 'text-gray-500'}`}>
              {sla.state === 'paused' ? 'Paused' : sla.label}
            </span>
          </Row>
          {sla.due_at && <Row label="Due">{new Date(sla.due_at).toLocaleString()}</Row>}
          {sla.state === 'paused' && (
            <p className="pt-1 text-[11px] text-gray-400">
              The clock stops while an issue is on hold or waiting on a release.
            </p>
          )}
        </div>
      )}

      {issue.release_ref && (
        <div className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
          <Row label="Release">{issue.release_ref}</Row>
          <Row label="Released">{issue.released_at && new Date(issue.released_at).toLocaleDateString()}</Row>
          <Row label="Validated">{issue.production_validated_at && new Date(issue.production_validated_at).toLocaleDateString()}</Row>
        </div>
      )}
    </aside>
  );
}

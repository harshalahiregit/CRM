/**
 * SIRE — duplicates, links and regressions on one issue.
 *
 * The duplicate section is written to make the brief's rule visible: nothing is
 * deleted. A duplicate keeps its number, its comments and its reporter, and this
 * panel shows both directions — where the work went, and what this issue absorbed.
 */
import { Link } from 'react-router-dom';
import StatusBadge from './StatusBadge';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const IssueRef = ({ issue }) => (
  <Link to={`/app/sire/cases/${issue.id}`} className="inline-flex items-center gap-2 hover:underline">
    <span className="font-mono text-[11px] text-gray-400">{issue.report_number}</span>
    <span className="truncate text-sm">{issue.title}</span>
    <StatusBadge status={issue.status} size="sm" />
  </Link>
);

const Section = ({ title, children }) => (
  <div>
    <h4 className="mb-1.5 text-[10px] font-medium uppercase tracking-wide text-gray-400">{title}</h4>
    {children}
  </div>
);

export default function RelationsPanel({ relations, canEdit, onClearRegression }) {
  if (!relations) return null;

  const {
    duplicate_of: duplicateOf, canonical, chain_broken: chainBroken, duplicates = [],
    links = [], inbound_links: inbound = [], regression_of: regressionOf, caused_by: causedBy,
  } = relations;

  const nothing = !duplicateOf && duplicates.length === 0 && links.length === 0
    && inbound.length === 0 && !regressionOf;

  return (
    <div className="space-y-4">
      {chainBroken && (
        <div className="rounded-lg bg-red-50 px-3 py-2 text-xs text-red-800 dark:bg-red-950 dark:text-red-200">
          This issue’s duplicate chain loops back on itself. Someone needs to break the loop before
          the canonical issue can be resolved.
        </div>
      )}

      {duplicateOf && (
        <Section title="Duplicate of">
          <IssueRef issue={duplicateOf} />
          {canonical && canonical.id !== duplicateOf.id && (
            <p className="mt-1 text-[11px] text-gray-500">
              The work is actually being tracked on <IssueRef issue={canonical} />.
            </p>
          )}
          <p className="mt-1 text-[11px] text-gray-400">
            This report was kept, not deleted — its history and reporter are intact.
          </p>
        </Section>
      )}

      {duplicates.length > 0 && (
        <Section title={`Also reported as (${duplicates.length})`}>
          <ul className="space-y-1">
            {duplicates.map((d) => (
              <li key={d.id} className="flex items-center justify-between gap-2">
                <IssueRef issue={d} />
                {d.reporter?.name && <span className="shrink-0 text-[11px] text-gray-400">{d.reporter.name}</span>}
              </li>
            ))}
          </ul>
        </Section>
      )}

      {(regressionOf || causedBy) && (
        <Section title="Regression">
          {regressionOf && (
            <p className="text-sm">
              Re-introduced a defect first fixed in <IssueRef issue={regressionOf} />
            </p>
          )}
          {causedBy && (
            <p className="mt-1 text-sm text-gray-600 dark:text-gray-300">
              Caused by release <span className="font-mono text-xs">{causedBy.version}</span>
              {causedBy.name && ` — ${causedBy.name}`}
            </p>
          )}
          {canEdit && (
            <AsyncButton onClick={onClearRegression} className="mt-2">Not a regression</AsyncButton>
          )}
        </Section>
      )}

      {links.length > 0 && (
        <Section title="Related">
          <ul className="space-y-1">
            {links.map((l) => (
              <li key={l.id} className="text-sm">
                <span className="mr-2 text-[11px] uppercase text-gray-400">{l.link_type.replace(/_/g, ' ')}</span>
                {l.to_report && <IssueRef issue={l.to_report} />}
              </li>
            ))}
          </ul>
        </Section>
      )}

      {inbound.length > 0 && (
        <Section title="Referenced by">
          <ul className="space-y-1">
            {inbound.map((l) => (
              <li key={l.id} className="text-sm">
                {l.from_report && <IssueRef issue={l.from_report} />}
              </li>
            ))}
          </ul>
        </Section>
      )}

      {nothing && <p className="text-sm text-gray-400">No related issues.</p>}
    </div>
  );
}

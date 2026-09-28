/**
 * SIRE — the register table.
 *
 * Uses the existing kit: ui/DataTable for the grid and ui/TablePagination for
 * paging. Column definitions live here; nothing about the table is bespoke.
 */
import { Link } from 'react-router-dom';
import StatusBadge from './StatusBadge';
import SlaChip from './SlaChip';
import { priorityClasses, priorityLabel } from './transitionFields';
import { sireHostUi } from '../../../lib/sire/host';
const DataTable = sireHostUi('DataTable');
const EmptyState = sireHostUi('EmptyState');
const TablePagination = sireHostUi('TablePagination');

const shortDate = (iso) => (iso ? new Date(iso).toLocaleDateString() : '—');

export default function IssueTable({ page, loading, onPageChange }) {
  const rows = page?.data ?? [];

  /** A value, or a muted placeholder that keeps the column's width and rhythm. */
  const cell = (value, empty = '—') => (
    <span className="whitespace-nowrap text-xs" style={{ color: value ? 'var(--text-body)' : 'var(--text-faint)' }}>
      {value || empty}
    </span>
  );

  const columns = [
    {
      key: 'report_number',
      header: 'Issue',
      render: (row) => (
        <Link to={`/app/sire/cases/${row.id}`} className="block max-w-md">
          <span className="font-mono text-[11px]" style={{ color: 'var(--text-faint)' }}>{row.report_number}</span>
          <span
            className="mt-0.5 block truncate text-sm font-semibold hover:underline"
            style={{ color: 'var(--text-h)' }}
          >
            {row.title}
          </span>
        </Link>
      ),
    },
    { key: 'status', header: 'Status', render: (row) => <StatusBadge status={row.status} size="sm" /> },
    {
      key: 'priority',
      header: 'Priority',
      render: (row) => (row.priority ? (
        <span className={`rounded-full px-2 py-0.5 text-[10px] font-medium ring-1 ring-inset ${priorityClasses(row.priority)}`}>
          {priorityLabel(row.priority)}
        </span>
      ) : '—'),
    },
    // Every one of these can legitimately be empty, and an unstyled em dash
    // pressed against the next column is what made a row read as one run-on
    // string ("S3 - Medium—"). One muted, padded placeholder instead.
    { key: 'severity', header: 'Severity', render: (row) => cell(row.severity?.name) },
    { key: 'type',     header: 'Type',     render: (row) => cell(row.category?.name) },
    { key: 'module',   header: 'Module',   render: (row) => cell(row.module_label ?? row.module) },
    // Who FILED it, next to who is fixing it. The register showed only the
    // assignee, so the one person who could answer a question about an issue --
    // the one who hit it -- was the one name the list left out (SIR-000026).
    { key: 'reporter', header: 'Reported by', render: (row) => cell(row.reporter?.name) },
    { key: 'assignee', header: 'Assignee', render: (row) => cell(row.assignee?.name, 'Unassigned') },
    {
      key: 'sla',
      header: 'SLA',
      // The worse of the two clocks. The detail view shows them separately.
      render: (row) => <SlaChip clock={row.sla?.resolve ?? row.sla?.ack} size="sm" />,
    },
    { key: 'created_at', header: 'Created', render: (row) => <span className="whitespace-nowrap text-xs" style={{ color: 'var(--text-muted)' }}>{shortDate(row.created_at)}</span> },
  ];

  if (!loading && rows.length === 0) {
    return (
      <EmptyState
        title="No issues match these filters"
        description="Clear a filter, or pick a different tile."
      />
    );
  }

  return (
    <>
      {/*
        Nine columns squeezed into the page width is what made a row read as one
        run-on string: "S3 - Medium—" and "Functional defect tasks" are two cells
        each, but at ~120px per column the content butts straight into its
        neighbour with nothing between them.

        A floor width fixes it properly. Below that the table scrolls INSIDE this
        container -- the page itself never scrolls sideways -- which is the same
        treatment the release board uses, and the reason a governance table keeps
        all its columns instead of hiding some to fit.
      */}
      <div className="overflow-x-auto">
        <div style={{ minWidth: '1180px' }}>
          <DataTable columns={columns} rows={rows} loading={loading} rowKey="id" />
        </div>
      </div>
      {page && (
        <TablePagination
          currentPage={page.current_page}
          lastPage={page.last_page}
          total={page.total}
          perPage={page.per_page}
          onPageChange={onPageChange}
        />
      )}
    </>
  );
}

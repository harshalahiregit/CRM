/**
 * The context the reporter never had to type — captured by SireContextCollector
 * at the moment they clicked Report Issue.
 *
 * Read-only here. Correcting it is the reporter's job at capture time, not the
 * developer's afterwards.
 */
const Row = ({ label, value, mono }) => (
  <div className="flex items-baseline gap-3 py-1">
    <span className="w-24 shrink-0 text-[11px] uppercase tracking-wide text-gray-400">{label}</span>
    <span className={`text-sm text-gray-800 dark:text-gray-100 ${mono ? 'break-all font-mono text-[12px]' : ''}`}>
      {value || <span className="italic text-gray-400">Not captured</span>}
    </span>
  </div>
);

export default function CapturedContextPanel({ issue, context }) {
  const failed = context?.failed_requests?.[0];

  return (
    <section className="rounded-lg border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800/50">
      <div className="mb-2 flex items-center justify-between">
        <h3 className="text-xs font-semibold uppercase tracking-wide text-gray-500">Captured context</h3>
        {issue.context_confidence && issue.context_confidence !== 'high' && (
          <span className="rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-medium text-amber-800 dark:bg-amber-900 dark:text-amber-200">
            detection was uncertain
          </span>
        )}
      </div>

      <div className="grid gap-x-6 sm:grid-cols-2">
        <div>
          <Row label="Module" value={issue.module_label ?? issue.module} />
          <Row label="Section" value={issue.section_label ?? issue.section} />
          <Row label="Screen" value={issue.screen_label ?? issue.screen} />
          <Row label="Record" value={issue.related_label} />
        </div>
        <div>
          <Row label="Browser" value={context?.browser} />
          <Row label="OS" value={context?.os} />
          <Row label="Viewport" value={context?.viewport} />
          <Row label="App version" value={context?.app_version} />
        </div>
      </div>

      <div className="mt-1 border-t border-gray-200 pt-1 dark:border-gray-700">
        <Row label="Route" value={issue.route} mono />
        <Row label="URL" value={context?.url} mono />
      </div>

      {failed && (
        <div className="mt-2 rounded border border-red-200 bg-red-50 p-2 dark:border-red-900 dark:bg-red-950/30">
          <span className="text-[11px] uppercase tracking-wide text-red-500">Failed request at the time</span>
          <div className="mt-0.5 font-mono text-[12px] text-red-800 dark:text-red-200">
            {failed.method} {failed.path} → {failed.status || 'network error'}
            {failed.correlation_ref ? `  (ref ${failed.correlation_ref})` : ''}
          </div>
        </div>
      )}
    </section>
  );
}

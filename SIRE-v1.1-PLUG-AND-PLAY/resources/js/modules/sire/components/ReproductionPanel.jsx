/**
 * What both the developer and QA need before they can do anything: what was
 * expected, what actually happened, and how to get there. Shared by both views
 * on purpose — a QA engineer reading different reproduction text from the
 * developer is how a bug gets closed twice and fixed never.
 */
const Block = ({ title, body, tone = 'default', mono = false }) => {
  if (!body) return null;
  const ring = tone === 'bad'
    ? 'border-red-200 bg-red-50/50 dark:border-red-900 dark:bg-red-950/20'
    : tone === 'good'
      ? 'border-green-200 bg-green-50/50 dark:border-green-900 dark:bg-green-950/20'
      : 'border-gray-200 bg-white dark:border-gray-700 dark:bg-gray-900';

  return (
    <div className={`rounded-lg border p-3 ${ring}`}>
      <h4 className="mb-1 text-[11px] font-semibold uppercase tracking-wide text-gray-500">{title}</h4>
      <div className={`whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100 ${mono ? 'font-mono text-[13px]' : ''}`}>
        {body}
      </div>
    </div>
  );
};

export default function ReproductionPanel({ issue }) {
  return (
    <section className="space-y-3">
      <Block title="Description" body={issue.description} />
      <div className="grid gap-3 sm:grid-cols-2">
        <Block title="Expected result" body={issue.expected_result} tone="good" />
        <Block title="Actual result" body={issue.actual_result} tone="bad" />
      </div>
      <Block title="Steps to reproduce" body={issue.steps_to_reproduce} mono />
    </section>
  );
}

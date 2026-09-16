/**
 * SIRE — a risk band with its reasons.
 *
 * One component for all three engines, because they share one banding scale: a
 * HIGH on a release and a HIGH on an issue mean a comparable weight of evidence.
 * Rendering them differently would quietly undo that.
 *
 * THE REASONS ARE THE PRODUCT. The band is a summary of them, not the other way
 * round — so they are visible without a click, heaviest first, and each one is a
 * sentence somebody can disagree with.
 */
const TONE = {
  low:    'bg-green-50 text-green-700 ring-green-200 dark:bg-green-950 dark:text-green-200 dark:ring-green-800',
  medium: 'bg-amber-50 text-amber-800 ring-amber-200 dark:bg-amber-950 dark:text-amber-200 dark:ring-amber-800',
  high:   'bg-red-50 text-red-700 ring-red-200 dark:bg-red-950 dark:text-red-200 dark:ring-red-800',
};

export default function RiskBadge({ suggestion, title, note }) {
  // Nothing found is not "all clear" — the engine returns null rather than a
  // reassuring LOW, and this renders nothing at all.
  if (!suggestion) return null;

  const p = suggestion.payload ?? {};
  const reasons = suggestion.evidence?.signals ?? [];

  return (
    <section className="rounded-lg border border-dashed border-violet-300 bg-violet-50/30 p-3 dark:border-violet-800 dark:bg-violet-950/20">
      <header className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <div className="flex items-center gap-2">
          <span className="text-[10px] font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-300">
            {title}
          </span>
          <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold uppercase ring-1 ring-inset ${TONE[p.level] ?? TONE.low}`}>
            {p.level}
          </span>
        </div>
        <span className="text-[10px] text-gray-400">
          {[suggestion.provider, suggestion.model].filter(Boolean).join(' ')}
          {suggestion.confidence != null && ` · ${Math.round(suggestion.confidence * 100)}% evidence`}
        </span>
      </header>

      {note && <p className="mb-1.5 text-[11px] italic text-gray-500">{note}</p>}

      <ul className="space-y-1">
        {reasons.map((reason, i) => (
          <li key={i} className="flex gap-2 text-[11px] text-gray-700 dark:text-gray-300">
            {/* Weight shown so a reader can see which argument is doing the work. */}
            <span className="mt-0.5 shrink-0 font-mono text-[10px] text-gray-400">
              +{p.factors?.[i]?.weight ?? '?'}
            </span>
            <span>{reason}</span>
          </li>
        ))}
      </ul>
    </section>
  );
}

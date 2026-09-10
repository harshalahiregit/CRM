/**
 * SIRE — AI Suggested Root Cause.
 *
 * THE HEADING IS PART OF THE FEATURE. It reads "AI Suggested Root Cause" and never
 * "Confirmed Root Cause", because the distance between those two phrases is the
 * whole safety argument. A confirmed root cause is a finding a person signed; this
 * is a pattern drawn from issues that already have one.
 *
 * The description is rendered as a QUOTATION with its source issue attached —
 * "SIR-00123 was caused by …" — not as a statement about this issue. Nothing here
 * was written by a generator; it is somebody's confirmed analysis of a similar
 * defect, offered for comparison.
 *
 * Confirming still happens in the ordinary root cause form, through
 * SireRootCauseService::confirm(), by a person with the capability. This panel
 * cannot confirm anything: "Use this" pre-fills that form and nothing more.
 */

import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const CATEGORY_LABEL = (c) => (c ?? '').replace(/_/g, ' ').replace(/^./, (m) => m.toUpperCase());

export default function RootCauseSuggestionPanel({ suggestion, onUse, onChange, onReject }) {
  if (!suggestion) return null;

  const p = suggestion.payload ?? {};
  const e = suggestion.evidence ?? {};

  return (
    <section className="rounded-lg border border-dashed border-violet-300 bg-violet-50/30 p-3 dark:border-violet-800 dark:bg-violet-950/20">
      <header className="mb-2 flex flex-wrap items-baseline justify-between gap-2">
        {/* Never "Confirmed Root Cause". Not a style choice. */}
        <h3 className="text-[11px] font-semibold uppercase tracking-wide text-violet-800 dark:text-violet-300">
          AI Suggested Root Cause
        </h3>
        <span className="text-[10px] text-gray-400">
          {[suggestion.provider, suggestion.model].filter(Boolean).join(' ')}
          {suggestion.confidence != null && ` · ${Math.round(suggestion.confidence * 100)}% confidence`}
        </span>
      </header>

      <p className="mb-2 rounded bg-amber-50 px-2 py-1 text-[11px] text-amber-900 dark:bg-amber-950/50 dark:text-amber-200">
        This is a suggestion, not a finding. It becomes a confirmed root cause only when
        someone records and confirms it below.
      </p>

      <dl className="space-y-2">
        <div className="flex items-baseline gap-3">
          <dt className="w-24 shrink-0 text-[10px] uppercase tracking-wide text-gray-400">Category</dt>
          <dd className="text-sm font-medium">{CATEGORY_LABEL(p.category)}</dd>
        </div>

        {p.description && (
          <div className="flex items-start gap-3">
            <dt className="w-24 shrink-0 pt-0.5 text-[10px] uppercase tracking-wide text-gray-400">
              Seen before
            </dt>
            <dd className="min-w-0">
              {/* A quotation with attribution — never a statement about THIS issue. */}
              <blockquote className="border-l-2 border-violet-300 pl-2 text-sm italic text-gray-700 dark:border-violet-700 dark:text-gray-200">
                {p.description}
              </blockquote>
              <cite className="mt-0.5 block text-[11px] not-italic text-gray-500">
                — from {p.quoted_from}, whose root cause was confirmed by a person
              </cite>
            </dd>
          </div>
        )}

        {p.detection_gap && (
          <div className="flex items-start gap-3">
            <dt className="w-24 shrink-0 pt-0.5 text-[10px] uppercase tracking-wide text-gray-400">Detection gap</dt>
            <dd className="text-sm text-gray-700 dark:text-gray-300">{p.detection_gap}</dd>
          </div>
        )}

        {p.contributing_factors?.length > 0 && (
          <div className="flex items-start gap-3">
            <dt className="w-24 shrink-0 pt-0.5 text-[10px] uppercase tracking-wide text-gray-400">
              Recurring factors
            </dt>
            <dd className="flex flex-wrap gap-1">
              {p.contributing_factors.map((f) => (
                <span key={f} className="rounded bg-gray-100 px-1.5 py-0.5 text-[11px] text-gray-700 dark:bg-gray-800 dark:text-gray-300">
                  {f}
                </span>
              ))}
            </dd>
          </div>
        )}
      </dl>

      {e.summary && <p className="mt-2 text-[11px] text-gray-500">{e.summary}</p>}

      {e.references?.length > 0 && (
        <p className="mt-1 text-[10px] text-gray-500">
          Historical related issues: {e.references.join(', ')}
        </p>
      )}

      <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-violet-200 pt-2 dark:border-violet-900">
        {/* Pre-fills the root cause form. It does not confirm, and cannot. */}
        <AsyncButton onClick={() => onUse(p)}>Use as a starting point</AsyncButton>
        <button type="button" className="text-xs text-gray-600 hover:underline" onClick={() => onChange(p)}>
          Change
        </button>
        <button type="button" className="text-xs text-gray-400 hover:text-red-600" onClick={onReject}>
          Reject
        </button>
        <span className="ml-auto text-[10px] text-gray-400">Confirming happens in the analysis form.</span>
      </div>
    </section>
  );
}

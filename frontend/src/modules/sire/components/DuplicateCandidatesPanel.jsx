/**
 * SIRE — possible duplicates.
 *
 * NOTHING IS MARKED AUTOMATICALLY. Each candidate offers three choices, exactly as
 * specified: link it, ignore it, or say you are not sure. "Not sure" is recorded
 * separately from "ignore" because it means something different — the reader
 * looked and could not tell, which is a fact about the suggestion rather than
 * about them.
 *
 * The most useful thing on this panel is not the percentage. It is the previous
 * resolution: "closed as fixed, released in 2026.3" answers the question the
 * triager actually has.
 */

import { similarityToken, chipClasses } from '../../../lib/sire/tokens';
import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

function Candidate({ entry, onLink, onIgnore, onUnsure }) {
  const c = entry.candidate;
  const s = entry.signals ?? {};

  return (
    <li className="rounded-lg border border-gray-200 p-3 dark:border-gray-700">
      <div className="flex items-start justify-between gap-3">
        <div className="min-w-0">
          <div className="flex flex-wrap items-baseline gap-2">
            <span className="font-mono text-xs text-gray-500">{c.report_number}</span>
            {(() => {
              const t = similarityToken(entry.label);
              return (
                <span className={chipClasses(t, 'sm')}>
                  {t.marker && <span aria-hidden>{t.marker}</span>}
                  {t.label} · {Math.round(entry.score * 100)}%
                </span>
              );
            })()}
          </div>

          {c.restricted ? (
            /* Surfaced without its title: knowing "this may already be filed" is
               what prevents the duplicate, but the detail stays behind the
               ordinary access rules. */
            <p className="mt-1 text-sm italic text-gray-400">
              You do not have access to this issue. Ask a lead to check it.
            </p>
          ) : (
            <p className="mt-1 truncate text-sm font-medium">{c.title}</p>
          )}

          <p className="mt-0.5 text-[11px] text-gray-500">
            {[c.module, c.category, c.severity].filter(Boolean).join(' · ')}
            {c.status && <span className="ml-2">{String(c.status).replace(/_/g, ' ')}</span>}
          </p>

          {/* The question a triager actually has. */}
          {c.resolution && (
            <p className="mt-1 text-[11px] text-gray-600 dark:text-gray-300">
              Previously closed as <span className="font-medium">{c.resolution}</span>
              {c.released_in && <> · released in <span className="font-mono">{c.released_in}</span></>}
            </p>
          )}
          {c.fix_summary && (
            <p className="mt-0.5 line-clamp-2 text-[11px] text-gray-500">{c.fix_summary}</p>
          )}

          {/* Evidence, not a score. */}
          <p className="mt-1.5 text-[10px] text-gray-400">
            {s.shared_terms?.length > 0 && <>Shared terms: {s.shared_terms.slice(0, 8).join(', ')}. </>}
            {s.structural_matched?.length > 0
              ? <>Same {s.structural_matched.join(', ')}.</>
              : <>No structural match — text only.</>}
          </p>
        </div>

        <div className="flex shrink-0 flex-col items-stretch gap-1 text-[11px]">
          <AsyncButton onClick={() => onLink(entry)}>Link duplicate</AsyncButton>
          <button type="button" className="text-gray-500 hover:underline" onClick={() => onIgnore(entry)}>
            Ignore
          </button>
          <button type="button" className="text-gray-400 hover:underline" onClick={() => onUnsure(entry)}>
            Not sure
          </button>
        </div>
      </div>
    </li>
  );
}

export default function DuplicateCandidatesPanel({ suggestion, onLink, onIgnore, onUnsure }) {
  if (!suggestion) return null;

  const candidates = suggestion.payload?.candidates ?? [];
  if (candidates.length === 0) return null;

  return (
    <section>
      <header className="mb-2 flex items-baseline justify-between gap-2">
        <h3 className="text-[10px] font-semibold uppercase tracking-wide text-gray-500">
          Possible duplicates · nothing is linked automatically
        </h3>
        <span className="text-[10px] text-gray-400">
          {[suggestion.provider, suggestion.model].filter(Boolean).join(' ')}
        </span>
      </header>

      {suggestion.evidence?.summary && (
        <p className="mb-2 text-[11px] text-gray-500">{suggestion.evidence.summary}</p>
      )}

      <ul className="space-y-2">
        {candidates.map((entry) => (
          <Candidate
            key={entry.candidate.id}
            entry={entry}
            onLink={onLink}
            onIgnore={onIgnore}
            onUnsure={onUnsure}
          />
        ))}
      </ul>
    </section>
  );
}

/**
 * SIRE — existing KB articles that may already answer this issue.
 *
 * Links into Helpdesk's knowledge base. SIRE has none of its own, and these are
 * recommendations to READ, not links to create — linking an article to the issue
 * is a separate, deliberate act through the KB links panel.
 */
export default function KnowledgeSuggestionsPanel({ suggestion, onLink }) {
  if (!suggestion) return null;

  const articles = suggestion.payload?.articles ?? [];
  if (articles.length === 0) return null;

  return (
    <section>
      <h3 className="mb-2 text-[10px] font-medium uppercase tracking-wide text-gray-400">
        Existing knowledge · may already cover this
      </h3>

      <ul className="space-y-2">
        {articles.map(({ article, relevance, reason }) => (
          <li key={article.id} className="rounded-lg border border-gray-200 p-2.5 dark:border-gray-700">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <a href={article.url} className="text-sm font-medium hover:underline">
                  {article.title}
                </a>
                {article.summary && (
                  <p className="mt-0.5 line-clamp-2 text-[11px] text-gray-500">{article.summary}</p>
                )}
                {/* Why this article, in the same evidence shape a duplicate uses. */}
                <p className="mt-1 text-[10px] text-gray-400">{reason}</p>
              </div>

              <div className="flex shrink-0 flex-col items-end gap-1">
                <span className="text-[11px] tabular-nums text-gray-500">
                  {Math.round(relevance * 100)}% relevant
                </span>
                <button type="button" className="text-[11px] text-blue-600 hover:underline" onClick={() => onLink(article)}>
                  Link to issue
                </button>
              </div>
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

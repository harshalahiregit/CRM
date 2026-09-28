/**
 * SIRE — knowledge base links.
 *
 * SIRE has no knowledge base. These point into Helpdesk's, which is where KB
 * search looks and where agents read. "Draft an article" creates a DRAFT there —
 * SIRE proposes, the KB owner publishes.
 */

import { sireHostUi } from '../../../lib/sire/host';
const AsyncButton = sireHostUi('AsyncButton');

const TYPE_LABEL = {
  resolution: 'Resolution', known_issue: 'Known issue', prevention: 'Prevention',
  troubleshooting: 'Troubleshooting', reference: 'Reference',
};

export default function KbLinksPanel({ links = [], canAuthor, resolved, onLink, onCreateArticle, onUnlink }) {
  return (
    <div className="space-y-2">
      {links.length === 0 && (
        <p className="text-sm text-gray-400">No knowledge base articles linked.</p>
      )}

      <ul className="space-y-1">
        {links.map((l) => (
          <li key={l.id} className="flex items-center justify-between gap-2 text-sm">
            <a href={`/app/helpdesk/kb/${l.kb_article_id}`} className="truncate hover:underline">
              <span className="mr-2 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium uppercase text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                {TYPE_LABEL[l.link_type] ?? l.link_type}
              </span>
              Article #{l.kb_article_id}
              {l.created_from_issue && <span className="ml-2 text-[10px] text-gray-400">drafted from this issue</span>}
            </a>
            {canAuthor && (
              <button type="button" className="shrink-0 text-[11px] text-gray-400 hover:text-red-500" onClick={() => onUnlink(l)}>
                unlink
              </button>
            )}
          </li>
        ))}
      </ul>

      {canAuthor && (
        <div className="flex flex-wrap items-center gap-2 border-t border-gray-200 pt-2 dark:border-gray-700">
          <AsyncButton onClick={onLink}>Link an existing article</AsyncButton>
          <AsyncButton onClick={onCreateArticle} disabled={!resolved}>Draft an article from this issue</AsyncButton>
          {!resolved && (
            <span className="text-[11px] text-gray-500">
              Available once the issue is resolved — a fix that is still moving is not yet knowledge.
            </span>
          )}
        </div>
      )}
    </div>
  );
}

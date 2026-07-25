import { useMemo } from 'react'
import { useQuery } from '@tanstack/react-query'
import { BookOpen, CornerDownLeft, ExternalLink } from 'lucide-react'
import { useNavigate } from 'react-router-dom'
import { helpdeskApi } from '@/services/helpdeskApi'

/* ───────────────────────────────────────────────────────────────
   Knowledge Suggestions (SDS Knowledge component + UX Book-4 journey:
   "Agent opens ticket → AI suggests knowledge → insert into reply").

   Real suggestions from the existing KB (no LLM needed): ranks published
   articles by keyword overlap with the ticket subject/description, and lets
   the agent insert an article link into the reply composer in one click.
─────────────────────────────────────────────────────────────── */

const STOP = new Set(['the', 'a', 'an', 'is', 'are', 'to', 'of', 'in', 'on', 'for', 'and', 'or', 'my', 'i', 'it', 'this', 'that', 'with', 'not', 'no', 'cant', "can't", 'how', 'do', 'does', 'when', 'why', 'was', 'has'])
const words = s => (s || '').toLowerCase().replace(/[^a-z0-9\s]/g, ' ').split(/\s+/).filter(w => w.length > 2 && !STOP.has(w))

export default function KnowledgeSuggestions({ ticket, onInsert }) {
  const navigate = useNavigate()
  const { data: raw = [] } = useQuery({ queryKey: ['kb-articles-all'], queryFn: () => helpdeskApi.kb.articles() })
  const articles = Array.isArray(raw) ? raw : raw?.data || []

  const matches = useMemo(() => {
    const terms = new Set([...words(ticket?.subject), ...words(ticket?.description)])
    if (terms.size === 0) return []
    return articles
      .filter(a => a.is_published)
      .map(a => {
        const hay = new Set([...words(a.title), ...words(a.excerpt), ...words(a.content)])
        let score = 0; terms.forEach(t => { if (hay.has(t)) score++ })
        return { a, score }
      })
      .filter(m => m.score > 0)
      .sort((x, y) => y.score - x.score)
      .slice(0, 3)
      .map(m => m.a)
  }, [articles, ticket?.subject, ticket?.description])

  if (matches.length === 0) return null

  const insert = (a) => {
    const url = `${window.location.origin}/app/helpdesk/knowledge-base/${a.id}`
    onInsert?.(`\n\nYou may find this article helpful: ${a.title} — ${url}\n`)
  }

  return (
    <div className="mb-4 rounded-2xl p-4" style={{ border: '1px solid var(--border)', background: 'var(--bg-card)', boxShadow: 'var(--shadow-card)' }}>
      <div className="flex items-center gap-2 mb-3">
        <div className="w-6 h-6 rounded-lg flex items-center justify-center" style={{ background: 'color-mix(in srgb, var(--color-knowledge-500) 16%, transparent)' }}>
          <BookOpen size={13} style={{ color: 'var(--color-knowledge-500)' }} />
        </div>
        <span className="text-xs font-black" style={{ color: 'var(--color-knowledge-500)' }}>Suggested knowledge</span>
        <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>matched from your KB</span>
      </div>
      <ul className="space-y-1.5">
        {matches.map(a => (
          <li key={a.id} className="flex items-center gap-2 rounded-xl px-2.5 py-2 group transition-colors" style={{ border: '1px solid var(--border)' }}>
            <button onClick={() => navigate(`/app/helpdesk/knowledge-base/${a.id}`)}
              className="flex-1 flex items-center gap-2 min-w-0 text-left hover:underline" style={{ color: 'var(--text-body)' }}>
              <span className="text-sm truncate">{a.title}</span>
              <ExternalLink size={11} className="shrink-0 opacity-0 group-hover:opacity-60" />
            </button>
            <button onClick={() => insert(a)}
              className="flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg shrink-0 transition-opacity hover:opacity-80"
              style={{ background: 'color-mix(in srgb, var(--color-support-500) 14%, transparent)', color: 'var(--color-support-500)' }}>
              <CornerDownLeft size={11} /> Insert
            </button>
          </li>
        ))}
      </ul>
    </div>
  )
}

import { useState } from 'react'
import { useQuery, useMutation } from '@tanstack/react-query'
import { useParams } from 'react-router-dom'
import { ThumbsUp, ThumbsDown, BookOpen } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/**
 * Public, no-auth article page — the shareable link minted on publish.
 * Content is sanitized server-side, so we render it as HTML.
 */
export default function PublicArticle() {
  const { slug } = useParams()
  const [voted, setVoted] = useState(null)

  const { data: article, isLoading, isError } = useQuery({
    queryKey: ['public-article', slug],
    queryFn: () => helpdeskApi.public.article(slug),
  })

  const vote = useMutation({
    mutationFn: (direction) => helpdeskApi.public.vote(slug, direction),
    onSuccess: (_r, direction) => setVoted(direction),
  })

  if (isLoading) return <Shell><div className="animate-pulse text-slate-400">Loading…</div></Shell>
  if (isError || !article) return <Shell><p className="text-slate-500">This article is not available.</p></Shell>

  return (
    <Shell>
      <div className="text-xs text-cyan-700 font-semibold mb-2">
        {article.category?.name}{article.subcategory ? ` · ${article.subcategory.name}` : ''}
      </div>
      <h1 className="text-2xl font-black text-slate-900 mb-4">{article.title}</h1>
      <article className="prose prose-slate max-w-none text-slate-700" dangerouslySetInnerHTML={{ __html: article.content }} />

      <div className="mt-10 pt-6 border-t border-slate-200">
        <p className="text-sm text-slate-500 mb-2">Was this article helpful?</p>
        {voted ? (
          <p className="text-sm text-emerald-600 font-medium">Thanks for your feedback!</p>
        ) : (
          <div className="flex gap-2">
            <button onClick={() => vote.mutate('up')} disabled={vote.isPending}
              className="flex items-center gap-1.5 text-sm px-4 py-2 rounded-xl border border-slate-200 hover:bg-emerald-50 hover:border-emerald-200 text-slate-600">
              <ThumbsUp size={15} /> Yes ({article.thumbs_up})
            </button>
            <button onClick={() => vote.mutate('down')} disabled={vote.isPending}
              className="flex items-center gap-1.5 text-sm px-4 py-2 rounded-xl border border-slate-200 hover:bg-red-50 hover:border-red-200 text-slate-600">
              <ThumbsDown size={15} /> No ({article.thumbs_down})
            </button>
          </div>
        )}
      </div>
    </Shell>
  )
}

function Shell({ children }) {
  return (
    <div style={{ minHeight: '100vh', background: '#f8fafc' }}>
      <div className="max-w-2xl mx-auto px-5 py-10">
        <div className="flex items-center gap-2 mb-6 text-cyan-700">
          <BookOpen size={18} /><span className="font-bold">Help Center</span>
        </div>
        <div className="bg-white rounded-2xl shadow-sm border border-slate-200 p-8">{children}</div>
      </div>
    </div>
  )
}

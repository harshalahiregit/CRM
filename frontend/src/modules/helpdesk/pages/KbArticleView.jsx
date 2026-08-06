import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import {
  ChevronRight, ThumbsUp, ThumbsDown, ArrowLeft,
  FileText, Check, Calendar, Clock, Link2, Copy
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const fmtDate = d =>
  d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''

const normalize = (raw) => (Array.isArray(raw) ? raw : raw?.data || [])

export default function KbArticleView() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [voted, setVoted] = useState(null)
  const [copied, setCopied] = useState(false)

  const copyPublicLink = (url) => {
    navigator.clipboard?.writeText(url).then(() => {
      setCopied(true)
      setTimeout(() => setCopied(false), 1800)
    }).catch(() => {})
  }

  const { data: article, isLoading, isError } = useQuery({
    queryKey: ['kb-article', id],
    queryFn: () => helpdeskApi.kb.article(id),
  })
  const { data: artsRaw = [] } = useQuery({
    queryKey: ['kb-articles-all'],
    queryFn: () => helpdeskApi.kb.articles(),
  })

  const vote = useMutation({
    mutationFn: (direction) => helpdeskApi.kb.vote(id, direction),
    onSuccess: (updated) => qc.setQueryData(['kb-article', id], updated),
  })
  const castVote = (dir) => { if (!voted) { setVoted(dir); vote.mutate(dir) } }

  if (isLoading) {
    return (
      <div style={{ margin: '-1rem -1.5rem', background: 'var(--bg-global)', minHeight: 'calc(100vh - 120px)', padding: '32px 24px' }}>
        <div className="max-w-3xl mx-auto space-y-4">
          <div className="h-4 w-40 rounded-lg animate-pulse" style={{ background: 'var(--border)' }} />
          <div className="h-52 rounded-2xl animate-pulse" style={{ background: 'var(--border)' }} />
        </div>
      </div>
    )
  }

  if (isError || !article) {
    return (
      <div style={{ margin: '-1rem -1.5rem', background: 'var(--bg-global)', minHeight: 'calc(100vh - 120px)', padding: '32px 24px' }}>
        <div className="max-w-3xl mx-auto text-center py-16">
          <p className="font-semibold mb-2" style={{ color: 'var(--text-h)' }}>Article not found</p>
          <button
            onClick={() => navigate('/app/helpdesk/knowledge-base')}
            className="text-sm font-bold hover:opacity-70 transition-opacity"
            style={{ color: '#22d3ee' }}
          >
            ← Back to Help Center
          </button>
        </div>
      </div>
    )
  }

  const related = normalize(artsRaw)
    .filter(a => a.is_published && a.category_id === article.category_id && a.id !== article.id)
    .slice(0, 5)

  return (
    <div style={{ margin: '-1rem -1.5rem', background: 'var(--bg-global)', minHeight: 'calc(100vh - 120px)', padding: '28px 24px 56px' }}>

      {/* KB article prose styles */}
      <style>{`
        .kb-prose h2{font-size:19px;font-weight:800;color:var(--text-h);margin:24px 0 10px;letter-spacing:-0.01em}
        .kb-prose h3{font-size:16px;font-weight:700;color:var(--text-h);margin:20px 0 8px}
        .kb-prose p{margin:0 0 14px;color:var(--text-body);line-height:1.75}
        .kb-prose ul,.kb-prose ol{margin:0 0 14px 22px;color:var(--text-body)}
        .kb-prose li{margin:5px 0;line-height:1.7}
        .kb-prose a{color:#22d3ee;text-decoration:underline}
        .kb-prose code{background:var(--bg-input);padding:2px 7px;border-radius:6px;font-size:13px;color:#67e8f9;border:1px solid var(--border)}
        .kb-prose pre{background:var(--bg-section);padding:14px 16px;border-radius:12px;overflow:auto;border:1px solid var(--border)}
        .kb-prose blockquote{border-left:3px solid #22d3ee;padding-left:14px;margin:16px 0;opacity:0.8}
      `}</style>

      <div className="max-w-3xl mx-auto">

        {/* Breadcrumb */}
        <nav
          className="flex items-center gap-1.5 flex-wrap mb-5"
          style={{ fontSize: 13, color: 'var(--text-muted)' }}
        >
          <button
            onClick={() => navigate('/app/helpdesk/knowledge-base')}
            className="font-bold hover:opacity-70 transition-opacity"
            style={{ color: '#22d3ee' }}
          >
            Help Center
          </button>
          {article.category?.name && (
            <>
              <ChevronRight size={12} style={{ opacity: 0.5 }} />
              <span>{article.category.name}</span>
            </>
          )}
          {article.subcategory?.name && (
            <>
              <ChevronRight size={12} style={{ opacity: 0.5 }} />
              <span>{article.subcategory.name}</span>
            </>
          )}
        </nav>

        {/* Article card */}
        <article
          className="rounded-2xl overflow-hidden"
          style={{
            background: 'var(--bg-card)',
            border: '1px solid var(--border)',
            boxShadow: 'var(--shadow-card)',
          }}
        >
          {/* Article header */}
          <div
            className="px-8 py-7"
            style={{ borderBottom: '1px solid var(--border)' }}
          >
            <h1
              style={{
                fontSize: 'clamp(1.4rem,3vw,1.8rem)',
                fontWeight: 900,
                color: 'var(--text-h)',
                letterSpacing: '-0.025em',
                lineHeight: 1.25,
              }}
            >
              {article.title}
            </h1>
            <div className="flex items-center gap-4 mt-3 flex-wrap" style={{ color: 'var(--text-muted)', fontSize: 12 }}>
              <span className="flex items-center gap-1">
                <Calendar size={12} />
                Updated {fmtDate(article.updated_at)}
              </span>
              {article.category?.name && (
                <span
                  className="px-2 py-0.5 rounded-full text-[11px] font-semibold"
                  style={{ background: 'rgba(34,211,238,0.1)', color: '#22d3ee' }}
                >
                  {article.category.name}
                </span>
              )}
            </div>

            {/* Public link — shareable URL of this article, with a copy button. */}
            {article.public_slug ? (() => {
              const publicUrl = `${window.location.origin}/kb/a/${article.public_slug}`
              return (
                <div className="flex items-center gap-2 mt-4 rounded-xl px-3 py-2"
                  style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                  <Link2 size={13} style={{ color: '#22d3ee' }} className="shrink-0" />
                  <a href={publicUrl} target="_blank" rel="noreferrer"
                    className="flex-1 text-xs font-mono truncate hover:underline" style={{ color: 'var(--text-body)' }}>
                    {publicUrl}
                  </a>
                  <button onClick={() => copyPublicLink(publicUrl)}
                    className="shrink-0 flex items-center gap-1 text-[11px] font-bold px-2 py-1 rounded-lg"
                    style={{ background: 'rgba(34,211,238,0.12)', color: '#22d3ee' }}>
                    {copied ? <><Check size={12} /> Copied</> : <><Copy size={12} /> Copy link</>}
                  </button>
                </div>
              )
            })() : (
              <p className="mt-3 text-[11px]" style={{ color: 'var(--text-muted)' }}>
                Publish this article to get a shareable public link.
              </p>
            )}
          </div>

          {/* Content */}
          <div className="px-8 py-7">
            <div
              className="kb-prose"
              style={{ fontSize: 15, lineHeight: 1.75 }}
              dangerouslySetInnerHTML={{ __html: article.content || '' }}
            />
          </div>

          {/* Feedback section */}
          <div
            className="px-8 py-6 text-center"
            style={{ borderTop: '1px solid var(--border)', background: 'var(--bg-input)' }}
          >
            {!voted ? (
              <>
                <p
                  className="text-sm font-semibold mb-3"
                  style={{ color: 'var(--text-h)' }}
                >
                  Was this article helpful?
                </p>
                <div className="flex items-center justify-center gap-3">
                  <VoteBtn onClick={() => castVote('up')} icon={ThumbsUp} label="Yes, helpful" positive />
                  <VoteBtn onClick={() => castVote('down')} icon={ThumbsDown} label="Not really" />
                </div>
              </>
            ) : (
              <div className="flex items-center justify-center gap-2 text-sm font-semibold" style={{ color: '#10b981' }}>
                <div
                  className="w-6 h-6 rounded-full flex items-center justify-center"
                  style={{ background: 'rgba(16,185,129,0.15)' }}
                >
                  <Check size={14} />
                </div>
                Thanks for your feedback!
              </div>
            )}
            <p className="text-xs mt-3" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>
              {article.thumbs_up || 0} found this helpful · {article.thumbs_down || 0} did not
            </p>
          </div>
        </article>

        {/* Related articles */}
        {related.length > 0 && (
          <div
            className="mt-5 rounded-2xl overflow-hidden"
            style={{
              background: 'var(--bg-card)',
              border: '1px solid var(--border)',
              boxShadow: 'var(--shadow-card)',
            }}
          >
            <div
              className="flex items-center gap-2 px-5 py-4"
              style={{ borderBottom: '1px solid var(--border)' }}
            >
              <FileText size={14} style={{ color: '#22d3ee' }} />
              <h2 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Related Articles</h2>
            </div>
            <ul>
              {related.map(a => (
                <li key={a.id}>
                  <button
                    onClick={() => navigate(`/app/helpdesk/knowledge-base/${a.id}`)}
                    className="w-full flex items-center gap-3 px-5 py-3 text-left transition-all duration-150"
                    onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-card-hover)'}
                    onMouseLeave={e => e.currentTarget.style.background = 'transparent'}
                  >
                    <FileText size={14} style={{ color: 'var(--text-muted)' }} />
                    <span className="flex-1 text-sm" style={{ color: 'var(--text-h)' }}>{a.title}</span>
                    <ChevronRight size={13} style={{ color: 'var(--text-muted)', opacity: 0.5 }} />
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}

        {/* Back button */}
        <button
          onClick={() => navigate('/app/helpdesk/knowledge-base')}
          className="inline-flex items-center gap-1.5 mt-6 text-sm font-semibold hover:opacity-70 transition-opacity"
          style={{ color: '#22d3ee' }}
        >
          <ArrowLeft size={14} />
          Back to Help Center
        </button>
      </div>
    </div>
  )
}

function VoteBtn({ onClick, icon: Icon, label, positive }) {
  return (
    <button
      onClick={onClick}
      className="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold transition-all duration-150 hover:-translate-y-0.5"
      style={{
        border: '1px solid var(--border)',
        background: positive ? 'rgba(16,185,129,0.06)' : 'transparent',
        color: positive ? '#10b981' : 'var(--text-muted)',
      }}
      onMouseEnter={e => {
        e.currentTarget.style.borderColor = positive ? '#10b981' : '#ef4444'
        e.currentTarget.style.color = positive ? '#10b981' : '#ef4444'
      }}
      onMouseLeave={e => {
        e.currentTarget.style.borderColor = 'var(--border)'
        e.currentTarget.style.color = positive ? '#10b981' : 'var(--text-muted)'
      }}
    >
      <Icon size={15} />
      {label}
    </button>
  )
}

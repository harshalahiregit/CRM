import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { useParams, useNavigate } from 'react-router-dom'
import { ChevronRight, ThumbsUp, ThumbsDown, ArrowLeft, FileText, Check } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const fmtDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''
const normalize = (raw) => (Array.isArray(raw) ? raw : raw?.data || [])

export default function KbArticleView() {
  const { id } = useParams()
  const navigate = useNavigate()
  const qc = useQueryClient()
  const [voted, setVoted] = useState(null)

  const { data: article, isLoading, isError } = useQuery({ queryKey: ['kb-article', id], queryFn: () => helpdeskApi.kb.article(id) })
  const { data: artsRaw = [] } = useQuery({ queryKey: ['kb-articles-all'], queryFn: () => helpdeskApi.kb.articles() })

  const vote = useMutation({
    mutationFn: (direction) => helpdeskApi.kb.vote(id, direction),
    onSuccess: (updated) => { qc.setQueryData(['kb-article', id], updated) },
  })
  const castVote = (dir) => { if (!voted) { setVoted(dir); vote.mutate(dir) } }

  if (isLoading) return <Light><div style={{ height: 300, borderRadius: 16, background: '#e9edf5' }} className="animate-pulse max-w-3xl mx-auto" /></Light>
  if (isError || !article) return <Light><div className="max-w-3xl mx-auto" style={{ color: '#7a879e' }}>Article not found. <button onClick={() => navigate('/app/helpdesk/knowledge-base')} style={{ color: '#3b6fed', fontWeight: 600 }}>Back to Help Center</button></div></Light>

  const related = normalize(artsRaw).filter(a => a.is_published && a.category_id === article.category_id && a.id !== article.id).slice(0, 5)

  return (
    <Light>
      <div className="max-w-3xl mx-auto">
        {/* Breadcrumb */}
        <nav style={{ display: 'flex', alignItems: 'center', gap: 6, fontSize: 13, color: '#7a879e', marginBottom: 18, flexWrap: 'wrap' }}>
          <button onClick={() => navigate('/app/helpdesk/knowledge-base')} style={{ color: '#3b6fed', fontWeight: 600 }}>Help Center</button>
          {article.category?.name && <><ChevronRight size={13} /><span>{article.category.name}</span></>}
          {article.subcategory?.name && <><ChevronRight size={13} /><span>{article.subcategory.name}</span></>}
        </nav>

        <article style={{ background: '#fff', border: '1px solid #e7eaf2', borderRadius: 16, padding: '32px 36px', boxShadow: '0 1px 2px rgba(20,30,60,0.04)' }}>
          <h1 style={{ fontSize: 26, fontWeight: 800, color: '#111d33', letterSpacing: '-0.02em', lineHeight: 1.25 }}>{article.title}</h1>
          <p style={{ fontSize: 12.5, color: '#9aa4ba', marginTop: 8 }}>Last updated {fmtDate(article.updated_at)}</p>

          <div className="kb-prose" style={{ marginTop: 22, color: '#38455f', fontSize: 15.5, lineHeight: 1.75 }}
            dangerouslySetInnerHTML={{ __html: article.content || '' }} />

          {/* Was this helpful */}
          <div style={{ marginTop: 32, paddingTop: 22, borderTop: '1px solid #eef1f7', textAlign: 'center' }}>
            {!voted ? (
              <>
                <p style={{ fontSize: 14, fontWeight: 600, color: '#38455f', marginBottom: 12 }}>Was this article helpful?</p>
                <div style={{ display: 'flex', gap: 10, justifyContent: 'center' }}>
                  <VoteBtn onClick={() => castVote('up')} icon={ThumbsUp} label="Yes" />
                  <VoteBtn onClick={() => castVote('down')} icon={ThumbsDown} label="No" />
                </div>
              </>
            ) : (
              <p style={{ fontSize: 14, color: '#16a34a', fontWeight: 600, display: 'flex', gap: 6, justifyContent: 'center', alignItems: 'center' }}>
                <Check size={16} /> Thanks for your feedback!
              </p>
            )}
            <p style={{ fontSize: 12, color: '#b3bccd', marginTop: 12 }}>
              {article.thumbs_up || 0} found this helpful · {article.thumbs_down || 0} did not
            </p>
          </div>
        </article>

        {/* Related */}
        {related.length > 0 && (
          <div style={{ background: '#fff', border: '1px solid #e7eaf2', borderRadius: 16, padding: 20, marginTop: 20 }}>
            <h2 style={{ fontWeight: 700, fontSize: 14, color: '#1a2b4a', marginBottom: 10 }}>Related articles</h2>
            <ul>
              {related.map(a => (
                <li key={a.id}>
                  <button onClick={() => navigate(`/app/helpdesk/knowledge-base/${a.id}`)}
                    style={{ width: '100%', display: 'flex', alignItems: 'center', gap: 10, padding: '9px 8px', borderRadius: 10, textAlign: 'left', color: '#38455f', fontSize: 14 }}
                    onMouseEnter={e => e.currentTarget.style.background = '#f2f5fb'}
                    onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                    <FileText size={15} style={{ color: '#9aa4ba' }} />
                    <span style={{ flex: 1 }}>{a.title}</span>
                    <ChevronRight size={15} style={{ color: '#c2cbdc' }} />
                  </button>
                </li>
              ))}
            </ul>
          </div>
        )}

        <button onClick={() => navigate('/app/helpdesk/knowledge-base')}
          style={{ display: 'inline-flex', alignItems: 'center', gap: 6, marginTop: 20, fontSize: 13, fontWeight: 600, color: '#3b6fed' }}>
          <ArrowLeft size={14} /> Back to Help Center
        </button>
      </div>
    </Light>
  )
}

function VoteBtn({ onClick, icon: Icon, label }) {
  return (
    <button onClick={onClick}
      style={{ display: 'inline-flex', alignItems: 'center', gap: 7, padding: '9px 20px', borderRadius: 10, border: '1px solid #dfe4ee', background: '#fff', fontSize: 14, fontWeight: 600, color: '#38455f' }}
      onMouseEnter={e => { e.currentTarget.style.borderColor = '#3b6fed'; e.currentTarget.style.color = '#3b6fed' }}
      onMouseLeave={e => { e.currentTarget.style.borderColor = '#dfe4ee'; e.currentTarget.style.color = '#38455f' }}>
      <Icon size={15} /> {label}
    </button>
  )
}

/* Light canvas wrapper — the help center is always light, regardless of app theme. */
function Light({ children }) {
  return (
    <div className="-m-4 md:-m-6" style={{ background: '#f4f6fb', minHeight: 'calc(100vh - 120px)', padding: '28px 24px 48px' }}>
      <style>{`.kb-prose h2{font-size:19px;font-weight:700;color:#1a2b4a;margin:22px 0 8px}.kb-prose h3{font-size:16px;font-weight:700;color:#1a2b4a;margin:18px 0 6px}.kb-prose p{margin:0 0 14px}.kb-prose ul,.kb-prose ol{margin:0 0 14px 22px}.kb-prose li{margin:4px 0}.kb-prose a{color:#3b6fed;text-decoration:underline}.kb-prose code{background:#eef1f7;padding:2px 6px;border-radius:5px;font-size:13.5px}`}</style>
      {children}
    </div>
  )
}

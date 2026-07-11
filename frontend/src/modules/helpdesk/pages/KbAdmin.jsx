import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import ReactQuill from 'react-quill'
import 'react-quill/dist/quill.snow.css'
import { FolderPlus, Plus, Trash2, Globe, Link as LinkIcon, Check } from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

const EMPTY_ARTICLE = { id: null, title: '', excerpt: '', content: '', subcategory_id: null, department_id: null }

export default function KbAdmin() {
  const qc = useQueryClient()
  const [selSub, setSelSub] = useState(null)
  const [article, setArticle] = useState(null)   // null | EMPTY_ARTICLE | existing
  const [copied, setCopied] = useState(false)

  const { data: categories = [] } = useQuery({ queryKey: ['kb-admin-cats'], queryFn: helpdeskApi.kb.categories })
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })
  const departments = settings?.departments || []
  const { data: articles = [] } = useQuery({
    queryKey: ['kb-admin-articles', selSub],
    queryFn: () => helpdeskApi.kb.articles({ subcategory_id: selSub }),
    enabled: !!selSub,
  })

  const refetchCats = () => qc.invalidateQueries({ queryKey: ['kb-admin-cats'] })
  const refetchArticles = () => qc.invalidateQueries({ queryKey: ['kb-admin-articles', selSub] })

  const addCategory = useMutation({ mutationFn: (name) => helpdeskApi.kb.createCategory({ name }), onSuccess: refetchCats })
  const addSub = useMutation({ mutationFn: ({ category_id, name }) => helpdeskApi.kb.createSubcategory({ category_id, name }), onSuccess: refetchCats })
  const delCategory = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteCategory(id), onSuccess: refetchCats })
  const delSub = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteSubcategory(id), onSuccess: refetchCats })

  const saveArticle = useMutation({
    mutationFn: (a) => a.id
      ? helpdeskApi.kb.updateArticle(a.id, { title: a.title, excerpt: a.excerpt, content: a.content, subcategory_id: a.subcategory_id, department_id: a.department_id || null })
      : helpdeskApi.kb.createArticle({ title: a.title, excerpt: a.excerpt, content: a.content, subcategory_id: a.subcategory_id, department_id: a.department_id || null }),
    onSuccess: (saved) => { setArticle(saved); refetchArticles() },
  })
  const publish = useMutation({
    mutationFn: (id) => helpdeskApi.kb.publish(id),
    onSuccess: (res) => { setArticle(a => ({ ...a, ...res.article, public_slug: res.public_slug, public_url: res.public_url })); refetchArticles() },
  })
  const delArticle = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteArticle(id), onSuccess: () => { setArticle(null); refetchArticles() } })

  const promptAdd = (label, cb) => { const v = window.prompt(label); if (v && v.trim()) cb(v.trim()) }

  const publicUrl = article?.public_url || (article?.public_slug ? `${location.origin}/kb/a/${article.public_slug}` : null)

  return (
    <div className="text-slate-200">
      <div className="flex items-center justify-between mb-5">
        <h1 className="text-lg font-bold" style={{ color: 'var(--text-h)' }}>Knowledge Base — Admin</h1>
        <button onClick={() => promptAdd('New category name', v => addCategory.mutate(v))}
          className="flex items-center gap-1.5 text-xs font-semibold px-3 py-2 rounded-xl" style={{ background: 'rgba(6,182,212,0.15)', color: '#22d3ee' }}>
          <FolderPlus size={14} /> Category
        </button>
      </div>

      <div className="grid gap-5 lg:grid-cols-[300px_1fr]">
        {/* ── Tree ── */}
        <div className="rounded-2xl border p-3 space-y-3" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          {categories.length === 0 && <p className="text-xs text-slate-500 px-1">No categories yet.</p>}
          {categories.map(cat => (
            <div key={cat.id}>
              <div className="flex items-center gap-1 group">
                <span className="font-semibold text-sm flex-1 truncate" style={{ color: 'var(--text-h)' }}>{cat.name}</span>
                <button title="Add sub-category" onClick={() => promptAdd(`New sub-category under "${cat.name}"`, v => addSub.mutate({ category_id: cat.id, name: v }))}
                  className="opacity-60 hover:opacity-100"><Plus size={13} /></button>
                <button title="Delete category" onClick={() => delCategory.mutate(cat.id)} className="opacity-40 hover:opacity-100 hover:text-red-400"><Trash2 size={12} /></button>
              </div>
              <ul className="mt-1 ml-2 space-y-0.5">
                {(cat.subcategories || []).map(sub => (
                  <li key={sub.id} className="flex items-center gap-1 group">
                    <button onClick={() => { setSelSub(sub.id); setArticle(null) }}
                      className={`flex-1 text-left text-xs px-2 py-1.5 rounded-lg truncate ${selSub === sub.id ? 'bg-white/[0.06]' : 'hover:bg-white/[0.03]'}`}
                      style={{ color: selSub === sub.id ? '#67e8f9' : 'var(--text-muted)' }}>
                      {sub.name} <span className="opacity-50">({sub.articles_count ?? 0})</span>
                    </button>
                    <button onClick={() => delSub.mutate(sub.id)} className="opacity-0 group-hover:opacity-60 hover:!opacity-100 hover:text-red-400"><Trash2 size={11} /></button>
                  </li>
                ))}
                {(cat.subcategories || []).length === 0 && <li className="text-[11px] text-slate-600 px-2">no sub-categories</li>}
              </ul>
            </div>
          ))}
        </div>

        {/* ── Articles + editor ── */}
        <div className="rounded-2xl border p-4" style={{ borderColor: 'var(--border)', background: 'var(--bg-card)' }}>
          {!selSub && <p className="text-sm text-slate-500">Select a sub-category to manage its articles.</p>}

          {selSub && !article && (
            <>
              <div className="flex items-center justify-between mb-3">
                <h2 className="font-semibold text-sm" style={{ color: 'var(--text-h)' }}>Articles</h2>
                <button onClick={() => setArticle({ ...EMPTY_ARTICLE, subcategory_id: selSub })}
                  className="flex items-center gap-1.5 text-xs font-semibold px-3 py-1.5 rounded-xl" style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff' }}>
                  <Plus size={13} /> New article
                </button>
              </div>
              <ul className="space-y-1">
                {articles.map(a => (
                  <li key={a.id}>
                    <button onClick={() => setArticle(a)} className="w-full flex items-center gap-2 px-2 py-2 rounded-lg text-left text-sm hover:bg-white/[0.03]" style={{ color: 'var(--text-h)' }}>
                      <span className="flex-1 truncate">{a.title}</span>
                      {a.is_published
                        ? <span className="text-[10px] px-2 py-0.5 rounded-lg" style={{ background: 'rgba(16,185,129,0.12)', color: '#10b981' }}>Published</span>
                        : <span className="text-[10px] px-2 py-0.5 rounded-lg" style={{ background: 'rgba(100,116,139,0.12)', color: '#94a3b8' }}>Draft</span>}
                    </button>
                  </li>
                ))}
                {articles.length === 0 && <li className="text-xs text-slate-500 px-2 py-3">No articles in this sub-category.</li>}
              </ul>
            </>
          )}

          {article && (
            <div className="space-y-3">
              <input value={article.title} onChange={e => setArticle({ ...article, title: e.target.value })} placeholder="Article title"
                className="w-full bg-transparent text-lg font-bold outline-none border-b pb-2" style={{ color: 'var(--text-h)', borderColor: 'var(--border)' }} />
              <input value={article.excerpt || ''} onChange={e => setArticle({ ...article, excerpt: e.target.value })} placeholder="Short excerpt (optional)"
                className="w-full bg-transparent text-sm outline-none" style={{ color: 'var(--text-muted)' }} />

              {/* Optional department scoping (Phase 5) */}
              <label className="flex items-center gap-2 text-xs" style={{ color: 'var(--text-muted)' }}>
                Department
                <select value={article.department_id || ''} onChange={e => setArticle({ ...article, department_id: e.target.value ? Number(e.target.value) : null })}
                  className="bg-transparent border rounded-lg px-2 py-1 outline-none" style={{ borderColor: 'var(--border)', color: 'var(--text-h)' }}>
                  <option value="" style={{ color: '#000' }}>— none —</option>
                  {departments.map(d => <option key={d.id} value={d.id} style={{ color: '#000' }}>{d.name}</option>)}
                </select>
              </label>

              <div className="bg-white rounded-lg overflow-hidden text-black">
                <ReactQuill theme="snow" value={article.content} onChange={v => setArticle({ ...article, content: v })} />
              </div>

              {publicUrl && (
                <div className="flex items-center gap-2 text-xs p-2 rounded-lg" style={{ background: 'rgba(16,185,129,0.1)', color: '#10b981' }}>
                  <Globe size={13} /> <span className="truncate flex-1">{publicUrl}</span>
                  <button onClick={() => { navigator.clipboard?.writeText(publicUrl); setCopied(true); setTimeout(() => setCopied(false), 1500) }} className="flex items-center gap-1 hover:underline">
                    {copied ? <Check size={12} /> : <LinkIcon size={12} />}{copied ? 'Copied' : 'Copy'}
                  </button>
                </div>
              )}

              <div className="flex items-center gap-2">
                <button disabled={!article.title.trim() || saveArticle.isPending} onClick={() => saveArticle.mutate(article)}
                  className="text-xs font-semibold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: 'linear-gradient(135deg,#22d3ee,#0891b2)', color: '#fff' }}>
                  {saveArticle.isPending ? 'Saving…' : 'Save'}
                </button>
                {article.id && !article.is_published && (
                  <button disabled={publish.isPending} onClick={() => publish.mutate(article.id)}
                    className="flex items-center gap-1.5 text-xs font-semibold px-4 py-2 rounded-xl" style={{ background: 'rgba(16,185,129,0.15)', color: '#10b981' }}>
                    <Globe size={13} /> {publish.isPending ? 'Publishing…' : 'Publish'}
                  </button>
                )}
                {article.id && (
                  <button onClick={() => delArticle.mutate(article.id)} className="text-xs px-3 py-2 rounded-xl hover:text-red-400" style={{ color: 'var(--text-muted)' }}>Delete</button>
                )}
                <button onClick={() => setArticle(null)} className="ml-auto text-xs px-3 py-2 rounded-xl" style={{ color: 'var(--text-muted)' }}>Close</button>
              </div>
              {saveArticle.isError && <p className="text-xs text-red-400">{saveArticle.error?.message}</p>}
            </div>
          )}
        </div>
      </div>
    </div>
  )
}

import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import ReactQuill from 'react-quill'
import 'react-quill/dist/quill.snow.css'
import {
  FolderPlus, Plus, Trash2, Globe, Link as LinkIcon, Check, FileText, ChevronRight,
  BookOpen, HelpCircle, Wrench, Megaphone, MessageSquareText, ExternalLink, X, Pencil, Zap, EyeOff,
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'

/* ───────────────────────────────────────────────────────────────
   KB / Content admin — two modes: Knowledge Base + Canned Responses.
   Professional publish workflow, starter article templates, and a
   reply-snippet manager. Token-driven (light + dark).
─────────────────────────────────────────────────────────────── */

const ACCENT = 'var(--color-support-500)'

export default function KbAdmin() {
  const [tab, setTab] = useState('kb')
  return (
    <div className="space-y-5">
      <div>
        <p className="label-caps mb-0.5">Helpdesk</p>
        <h1 className="font-display font-black" style={{ fontSize: 'clamp(1.3rem,2.2vw,1.7rem)', color: 'var(--text-h)', letterSpacing: '-0.02em' }}>
          Content Manager
        </h1>
      </div>

      {/* Mode tabs */}
      <div className="flex items-center gap-2">
        {[['kb', 'Knowledge Base', BookOpen], ['canned', 'Canned Responses', MessageSquareText]].map(([k, label, Icon]) => (
          <button key={k} onClick={() => setTab(k)} className="flex items-center gap-2 text-sm font-bold px-4 py-2 rounded-xl transition-colors"
            style={{ background: tab === k ? ACCENT : 'var(--bg-card)', color: tab === k ? '#fff' : 'var(--text-muted)', border: `1px solid ${tab === k ? 'transparent' : 'var(--border)'}` }}>
            <Icon size={15} /> {label}
          </button>
        ))}
      </div>

      {tab === 'kb' ? <KbManager /> : <CannedManager />}
    </div>
  )
}

/* ═══════════════════════ Knowledge Base ═══════════════════════ */

const EMPTY_ARTICLE = { id: null, title: '', excerpt: '', content: '', subcategory_id: null, department_id: null }

const TEMPLATES = [
  { key: 'howto', name: 'How-to Guide', icon: BookOpen, desc: 'Step-by-step instructions',
    content: `<h2>Overview</h2><p>Briefly explain what this guide helps the reader accomplish.</p><h2>Before you begin</h2><ul><li>Prerequisite one</li><li>Prerequisite two</li></ul><h2>Steps</h2><ol><li>First step…</li><li>Second step…</li><li>Third step…</li></ol><blockquote>Tip: add a helpful shortcut or warning here.</blockquote>` },
  { key: 'faq', name: 'FAQ', icon: HelpCircle, desc: 'Common questions & answers',
    content: `<h2>Frequently asked questions</h2><h3>First question?</h3><p>Answer…</p><h3>Second question?</h3><p>Answer…</p><h3>Third question?</h3><p>Answer…</p>` },
  { key: 'trouble', name: 'Troubleshooting', icon: Wrench, desc: 'Diagnose & fix an issue',
    content: `<h2>Symptoms</h2><p>Describe what the user sees when this problem occurs.</p><h2>Common causes</h2><ul><li>Cause one</li><li>Cause two</li></ul><h2>How to fix it</h2><ol><li>First thing to try…</li><li>Next thing to try…</li></ol><h2>Still stuck?</h2><p>What to do if none of the above works.</p>` },
  { key: 'announce', name: 'Announcement', icon: Megaphone, desc: 'Share a new feature or update',
    content: `<h2>What's new</h2><p>Summarize the update in a sentence or two.</p><h2>Details</h2><p>Explain the change and why it matters to the reader.</p><h2>What you need to do</h2><ul><li>Action item (if any)</li></ul>` },
  { key: 'blank', name: 'Blank', icon: FileText, desc: 'Start from scratch', content: '' },
]

function KbManager() {
  const qc = useQueryClient()
  const [selSub, setSelSub] = useState(null)
  const [article, setArticle] = useState(null)
  const [copied, setCopied] = useState(false)
  const [tplOpen, setTplOpen] = useState(false)

  const { data: categories = [] } = useQuery({ queryKey: ['kb-admin-cats'], queryFn: helpdeskApi.kb.categories })
  const { data: settings } = useQuery({ queryKey: ['helpdesk-settings'], queryFn: helpdeskApi.settings.all })
  const departments = settings?.departments || []
  const { data: articles = [] } = useQuery({ queryKey: ['kb-admin-articles', selSub], queryFn: () => helpdeskApi.kb.articles({ subcategory_id: selSub }), enabled: !!selSub })

  const refetchCats = () => qc.invalidateQueries({ queryKey: ['kb-admin-cats'] })
  const refetchArticles = () => qc.invalidateQueries({ queryKey: ['kb-admin-articles', selSub] })

  const addCategory = useMutation({ mutationFn: (name) => helpdeskApi.kb.createCategory({ name }), onSuccess: refetchCats })
  const addSub = useMutation({ mutationFn: ({ category_id, name }) => helpdeskApi.kb.createSubcategory({ category_id, name }), onSuccess: refetchCats })
  const delCategory = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteCategory(id), onSuccess: refetchCats })
  const delSub = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteSubcategory(id), onSuccess: refetchCats })

  const saveArticle = useMutation({
    mutationFn: (a) => {
      const body = { title: a.title, excerpt: a.excerpt, content: a.content, subcategory_id: a.subcategory_id, department_id: a.department_id || null }
      return a.id ? helpdeskApi.kb.updateArticle(a.id, body) : helpdeskApi.kb.createArticle(body)
    },
    onSuccess: (saved) => { setArticle(saved); refetchArticles() },
  })
  const publish = useMutation({ mutationFn: (id) => helpdeskApi.kb.publish(id), onSuccess: (res) => { setArticle(a => ({ ...a, ...(res.article || res), public_slug: res.public_slug || res.public_slug, public_url: res.public_url })); refetchArticles() } })
  const unpublish = useMutation({ mutationFn: (id) => helpdeskApi.kb.unpublish(id), onSuccess: (res) => { setArticle(a => ({ ...a, ...(res.article || res), is_published: false })); refetchArticles() } })
  const delArticle = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteArticle(id), onSuccess: () => { setArticle(null); refetchArticles() } })

  const promptAdd = (label, cb) => { const v = window.prompt(label); if (v && v.trim()) cb(v.trim()) }
  const publicUrl = article?.public_url || (article?.public_slug ? `${location.origin}/kb/a/${article.public_slug}` : null)

  const startNew = (tpl) => { setArticle({ ...EMPTY_ARTICLE, subcategory_id: selSub, content: tpl.content }); setTplOpen(false) }

  return (
    <div className="grid gap-5 lg:grid-cols-[280px_1fr]">
      {/* Category tree */}
      <div className="rounded-2xl p-3 space-y-1.5 h-fit" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between px-2 py-2 mb-1" style={{ borderBottom: '1px solid var(--border)' }}>
          <span className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Categories</span>
          <button title="New category" onClick={() => promptAdd('New category name', v => addCategory.mutate(v))} className="hover:opacity-70" style={{ color: ACCENT }}><FolderPlus size={15} /></button>
        </div>
        {categories.length === 0 && <p className="text-xs px-2 py-3 text-center" style={{ color: 'var(--text-muted)' }}>No categories yet.</p>}
        {categories.map(cat => (
          <div key={cat.id}>
            <div className="flex items-center gap-1.5 group rounded-xl px-2 py-1.5" style={{ background: 'var(--bg-input)' }}>
              <span className="font-bold text-sm flex-1 truncate" style={{ color: 'var(--text-h)' }}>{cat.name}</span>
              <button title="Add sub-category" onClick={() => promptAdd(`Sub-category under "${cat.name}"`, v => addSub.mutate({ category_id: cat.id, name: v }))} className="opacity-0 group-hover:opacity-60 hover:!opacity-100" style={{ color: 'var(--text-muted)' }}><Plus size={13} /></button>
              <button title="Delete category" onClick={() => delCategory.mutate(cat.id)} className="opacity-0 group-hover:opacity-40 hover:!opacity-100 hover:text-red-400" style={{ color: 'var(--text-muted)' }}><Trash2 size={11} /></button>
            </div>
            <ul className="mt-0.5 ml-3 space-y-0.5 mb-1">
              {(cat.subcategories || []).map(sub => (
                <li key={sub.id} className="flex items-center gap-1 group">
                  <button onClick={() => { setSelSub(sub.id); setArticle(null) }} className="flex-1 flex items-center gap-1.5 text-left text-sm px-2.5 py-1.5 rounded-lg truncate transition-colors"
                    style={{ background: selSub === sub.id ? `color-mix(in srgb, ${ACCENT} 12%, transparent)` : 'transparent', color: selSub === sub.id ? ACCENT : 'var(--text-muted)', borderLeft: `2px solid ${selSub === sub.id ? ACCENT : 'transparent'}` }}>
                    <ChevronRight size={10} style={{ opacity: 0.5 }} /> {sub.name}
                    <span className="ml-auto opacity-50 shrink-0 text-xs">({sub.articles_count ?? 0})</span>
                  </button>
                  <button onClick={() => delSub.mutate(sub.id)} className="opacity-0 group-hover:opacity-40 hover:!opacity-100 hover:text-red-400" style={{ color: 'var(--text-muted)' }}><Trash2 size={11} /></button>
                </li>
              ))}
              {(cat.subcategories || []).length === 0 && <li className="text-[11px] px-3 py-1" style={{ color: 'var(--text-muted)', opacity: 0.5 }}>no sub-categories</li>}
            </ul>
          </div>
        ))}
      </div>

      {/* Articles + editor */}
      <div className="rounded-2xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)', minHeight: 420 }}>
        {!selSub && (
          <div className="flex flex-col items-center justify-center h-72 gap-3">
            <div className="w-14 h-14 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${ACCENT} 10%, transparent)` }}><BookOpen size={26} style={{ color: ACCENT }} /></div>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Select a sub-category to manage its articles</p>
          </div>
        )}

        {/* Article list */}
        {selSub && !article && (
          <div className="p-6">
            <div className="flex items-center justify-between mb-4">
              <h2 className="font-display font-bold" style={{ fontSize: 17, color: 'var(--text-h)' }}>Articles</h2>
              <button onClick={() => setTplOpen(true)} className="flex items-center gap-1.5 text-sm font-bold px-4 py-2 rounded-xl" style={{ background: ACCENT, color: '#fff' }}><Plus size={15} /> New Article</button>
            </div>
            <ul className="space-y-1">
              {articles.map(a => (
                <li key={a.id}>
                  <button onClick={() => setArticle(a)} className="w-full flex items-center gap-3 px-3 py-3 rounded-xl text-left transition-colors"
                    onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                    <FileText size={15} style={{ color: 'var(--text-muted)' }} />
                    <span className="flex-1 truncate" style={{ fontSize: 15, color: 'var(--text-h)' }}>{a.title}</span>
                    <StatusPill published={a.is_published} />
                    <ChevronRight size={14} style={{ color: 'var(--text-muted)', opacity: 0.4 }} />
                  </button>
                </li>
              ))}
              {articles.length === 0 && <li className="text-sm text-center py-10" style={{ color: 'var(--text-muted)' }}>No articles yet. Click “New Article” to create one from a template.</li>}
            </ul>
          </div>
        )}

        {/* Editor */}
        {article && (
          <div className="p-6 space-y-4">
            <input value={article.title} onChange={e => setArticle({ ...article, title: e.target.value })} placeholder="Article title…"
              className="w-full bg-transparent font-display font-black outline-none pb-3" style={{ fontSize: 24, color: 'var(--text-h)', borderBottom: '2px solid var(--border)', letterSpacing: '-0.02em' }} />
            <input value={article.excerpt || ''} onChange={e => setArticle({ ...article, excerpt: e.target.value })} placeholder="Short excerpt shown in listings (optional)…"
              className="w-full bg-transparent text-sm outline-none" style={{ color: 'var(--text-muted)', fontSize: 14.5 }} />

            <div className="flex items-center gap-3">
              <label className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Department</label>
              <select value={article.department_id || ''} onChange={e => setArticle({ ...article, department_id: e.target.value ? Number(e.target.value) : null })}
                className="text-sm rounded-xl px-3 py-1.5 outline-none" style={{ border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }}>
                <option value="">— none —</option>
                {departments.map(d => <option key={d.id} value={d.id}>{d.name}</option>)}
              </select>
            </div>

            <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
              <div className="bg-white text-black"><ReactQuill theme="snow" value={article.content} onChange={v => setArticle({ ...article, content: v })} style={{ minHeight: 300 }} /></div>
            </div>

            {/* Publish / status section */}
            {article.id && (
              <div className="rounded-2xl p-4" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
                <div className="flex items-center gap-2 mb-3">
                  <span className="w-7 h-7 rounded-lg flex items-center justify-center" style={{ background: article.is_published ? 'rgba(16,185,129,0.15)' : 'rgba(100,116,139,0.15)' }}>
                    <Globe size={14} style={{ color: article.is_published ? 'var(--color-success-500)' : 'var(--text-muted)' }} />
                  </span>
                  <div className="flex-1">
                    <p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{article.is_published ? 'Published & live' : 'Draft — not visible to customers'}</p>
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>{article.is_published ? 'Anyone with the link can read this article.' : 'Publish to generate a shareable public link.'}</p>
                  </div>
                  {!article.is_published
                    ? <button disabled={publish.isPending} onClick={() => publish.mutate(article.id)} className="flex items-center gap-1.5 text-sm font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: 'var(--color-success-500)', color: '#fff' }}><Globe size={14} />{publish.isPending ? 'Publishing…' : 'Publish'}</button>
                    : <button disabled={unpublish.isPending} onClick={() => unpublish.mutate(article.id)} className="flex items-center gap-1.5 text-sm font-semibold px-3 py-2 rounded-xl disabled:opacity-40" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}><EyeOff size={14} /> Unpublish</button>}
                </div>
                {article.is_published && publicUrl && (
                  <div className="flex items-center gap-2 p-2.5 rounded-xl" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                    <LinkIcon size={13} style={{ color: 'var(--color-success-500)', flexShrink: 0 }} />
                    <span className="flex-1 text-xs truncate font-mono" style={{ color: 'var(--text-body)' }}>{publicUrl}</span>
                    <button onClick={() => { navigator.clipboard?.writeText(publicUrl); setCopied(true); setTimeout(() => setCopied(false), 1500) }} className="flex items-center gap-1 text-xs font-bold" style={{ color: 'var(--color-success-500)' }}>{copied ? <Check size={12} /> : <LinkIcon size={12} />}{copied ? 'Copied' : 'Copy'}</button>
                    <a href={publicUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-xs font-bold" style={{ color: ACCENT }}><ExternalLink size={12} /> Open</a>
                  </div>
                )}
              </div>
            )}

            <div className="flex items-center gap-2 pt-1">
              <button disabled={!article.title.trim() || saveArticle.isPending} onClick={() => saveArticle.mutate(article)} className="text-sm font-bold px-5 py-2 rounded-xl disabled:opacity-40" style={{ background: ACCENT, color: '#fff' }}>{saveArticle.isPending ? 'Saving…' : 'Save'}</button>
              {article.id && <button onClick={() => delArticle.mutate(article.id)} className="text-sm px-3 py-2 rounded-xl hover:text-red-400" style={{ color: 'var(--text-muted)' }}>Delete</button>}
              <button onClick={() => setArticle(null)} className="ml-auto text-sm px-3 py-2 rounded-xl hover:opacity-70" style={{ color: 'var(--text-muted)' }}>Close</button>
            </div>
            {saveArticle.isError && <p className="text-xs" style={{ color: 'var(--color-danger-500)' }}>{saveArticle.error?.message}</p>}
          </div>
        )}
      </div>

      {/* Template picker modal */}
      {tplOpen && (
        <div className="fixed inset-0 z-[70] flex items-center justify-center p-4" style={{ background: 'rgba(0,0,0,0.5)' }} onClick={() => setTplOpen(false)}>
          <div className="w-full max-w-lg rounded-2xl p-6" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }} onClick={e => e.stopPropagation()}>
            <div className="flex items-center justify-between mb-1"><h2 className="font-display font-bold" style={{ fontSize: 18, color: 'var(--text-h)' }}>Start from a template</h2><button onClick={() => setTplOpen(false)}><X size={18} style={{ color: 'var(--text-muted)' }} /></button></div>
            <p className="text-sm mb-4" style={{ color: 'var(--text-muted)' }}>Pick a structure to start with — you can edit everything.</p>
            <div className="grid grid-cols-1 gap-2">
              {TEMPLATES.map(t => (
                <button key={t.key} onClick={() => startNew(t)} className="flex items-center gap-3 p-3 rounded-xl text-left transition-colors" style={{ border: '1px solid var(--border)' }}
                  onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-input)'} onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                  <span className="w-9 h-9 rounded-lg flex items-center justify-center shrink-0" style={{ background: `color-mix(in srgb, ${ACCENT} 13%, transparent)`, color: ACCENT }}><t.icon size={17} /></span>
                  <div><p className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{t.name}</p><p className="text-xs" style={{ color: 'var(--text-muted)' }}>{t.desc}</p></div>
                </button>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  )
}

function StatusPill({ published }) {
  return (
    <span className="text-[10px] px-2 py-0.5 rounded-full font-bold" style={published ? { background: 'rgba(16,185,129,0.14)', color: 'var(--color-success-500)' } : { background: 'rgba(100,116,139,0.14)', color: 'var(--text-muted)' }}>
      {published ? 'Published' : 'Draft'}
    </span>
  )
}

/* ═══════════════════════ Canned Responses ═══════════════════════ */

const EMPTY_CR = { id: null, title: '', category: '', shortcut: '', content: '' }

function CannedManager() {
  const qc = useQueryClient()
  const [editing, setEditing] = useState(null)
  const { data: list = [] } = useQuery({ queryKey: ['canned-responses'], queryFn: helpdeskApi.cannedResponses.list })
  const refetch = () => qc.invalidateQueries({ queryKey: ['canned-responses'] })

  const save = useMutation({
    mutationFn: (cr) => cr.id ? helpdeskApi.cannedResponses.update(cr.id, cr) : helpdeskApi.cannedResponses.create(cr),
    onSuccess: () => { setEditing(null); refetch() },
  })
  const remove = useMutation({ mutationFn: (id) => helpdeskApi.cannedResponses.remove(id), onSuccess: refetch })

  const grouped = list.reduce((acc, cr) => { (acc[cr.category || 'General'] ||= []).push(cr); return acc }, {})
  const INP = { border: '1px solid var(--border)', color: 'var(--text-h)', background: 'var(--bg-input)' }

  return (
    <div className="grid gap-5 lg:grid-cols-[1fr_360px]">
      {/* List */}
      <div className="rounded-2xl p-5" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between mb-4">
          <h2 className="font-display font-bold" style={{ fontSize: 17, color: 'var(--text-h)' }}>Saved replies</h2>
          <button onClick={() => setEditing({ ...EMPTY_CR })} className="flex items-center gap-1.5 text-sm font-bold px-4 py-2 rounded-xl" style={{ background: ACCENT, color: '#fff' }}><Plus size={15} /> New reply</button>
        </div>
        {list.length === 0 && <p className="text-sm text-center py-10" style={{ color: 'var(--text-muted)' }}>No canned responses yet. Create reusable replies your team can insert into tickets.</p>}
        {Object.entries(grouped).map(([cat, items]) => (
          <div key={cat} className="mb-4">
            <p className="text-xs font-bold uppercase tracking-wider mb-2" style={{ color: 'var(--text-muted)' }}>{cat}</p>
            <ul className="space-y-1.5">
              {items.map(cr => (
                <li key={cr.id} className="flex items-start gap-3 p-3 rounded-xl group" style={{ border: '1px solid var(--border)' }}>
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-2 mb-0.5">
                      <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{cr.title}</span>
                      {cr.shortcut && <span className="text-[11px] font-mono px-1.5 py-0.5 rounded" style={{ background: `color-mix(in srgb, ${ACCENT} 12%, transparent)`, color: ACCENT }}>{cr.shortcut}</span>}
                    </div>
                    <p className="text-xs line-clamp-2" style={{ color: 'var(--text-muted)' }}>{cr.content}</p>
                  </div>
                  <button onClick={() => setEditing(cr)} className="opacity-0 group-hover:opacity-100 hover:opacity-70" style={{ color: 'var(--text-muted)' }}><Pencil size={14} /></button>
                  <button onClick={() => remove.mutate(cr.id)} className="opacity-0 group-hover:opacity-100 hover:text-red-400" style={{ color: 'var(--text-muted)' }}><Trash2 size={14} /></button>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>

      {/* Editor */}
      <div className="rounded-2xl p-5 h-fit" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        {!editing ? (
          <div className="flex flex-col items-center justify-center py-12 gap-3 text-center">
            <div className="w-12 h-12 rounded-2xl flex items-center justify-center" style={{ background: `color-mix(in srgb, ${ACCENT} 10%, transparent)` }}><Zap size={22} style={{ color: ACCENT }} /></div>
            <p className="text-sm" style={{ color: 'var(--text-muted)' }}>Select a reply to edit, or create a new one.</p>
          </div>
        ) : (
          <div className="space-y-3">
            <h3 className="font-display font-bold" style={{ fontSize: 16, color: 'var(--text-h)' }}>{editing.id ? 'Edit reply' : 'New reply'}</h3>
            <Field label="Title"><input value={editing.title} onChange={e => setEditing({ ...editing, title: e.target.value })} placeholder="e.g. Password reset steps" className="w-full text-sm rounded-lg px-3 py-2 outline-none" style={INP} /></Field>
            <div className="grid grid-cols-2 gap-2">
              <Field label="Category"><input value={editing.category || ''} onChange={e => setEditing({ ...editing, category: e.target.value })} placeholder="General" className="w-full text-sm rounded-lg px-3 py-2 outline-none" style={INP} /></Field>
              <Field label="Shortcut"><input value={editing.shortcut || ''} onChange={e => setEditing({ ...editing, shortcut: e.target.value })} placeholder="/reset" className="w-full text-sm rounded-lg px-3 py-2 outline-none" style={INP} /></Field>
            </div>
            <Field label="Reply text"><textarea value={editing.content} onChange={e => setEditing({ ...editing, content: e.target.value })} rows={7} placeholder="Type the reusable reply…" className="w-full text-sm rounded-lg px-3 py-2 outline-none resize-none" style={INP} /></Field>
            <div className="flex items-center gap-2">
              <button disabled={!editing.title.trim() || !editing.content.trim() || save.isPending} onClick={() => save.mutate(editing)} className="text-sm font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: ACCENT, color: '#fff' }}>{save.isPending ? 'Saving…' : 'Save reply'}</button>
              <button onClick={() => setEditing(null)} className="text-sm px-3 py-2 rounded-xl hover:opacity-70" style={{ color: 'var(--text-muted)' }}>Cancel</button>
            </div>
            {save.isError && <p className="text-xs" style={{ color: 'var(--color-danger-500)' }}>{save.error?.message}</p>}
          </div>
        )}
      </div>
    </div>
  )
}

function Field({ label, children }) {
  return <label className="block"><span className="text-[10px] uppercase tracking-widest block mb-1 font-bold" style={{ color: 'var(--text-muted)' }}>{label}</span>{children}</label>
}

import { useState, useEffect } from 'react'
import { useLocation } from 'react-router-dom'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import ReactQuill from 'react-quill'
import 'react-quill/dist/quill.snow.css'
import {
  FolderPlus, Plus, Trash2, Globe, Link as LinkIcon, Check, FileText, ChevronRight,
  BookOpen, HelpCircle, Wrench, Megaphone, MessageSquareText, ExternalLink, X, Pencil, Zap, EyeOff,
  Tag, Save, LayoutTemplate, FolderTree, Eye, Star, MessageSquare,
} from 'lucide-react'
import { helpdeskApi } from '@/services/helpdeskApi'
import Select from '../components/ui/Select'
import { InputModal, ConfirmModal } from '../components/ui/SearchPicker'

/* ───────────────────────────────────────────────────────────────
   KB / Content admin — two modes: Knowledge Base + Canned Responses.
   Professional publish workflow, starter article templates, and a
   reply-snippet manager. Token-driven (light + dark).
─────────────────────────────────────────────────────────────── */

const ACCENT = 'var(--color-support-500)'

// Rich-text toolbar for KB articles — headings, formatting, lists, and crucially
// image + video embeds so guides can include screenshots and how-to videos
// (the "Step 1..5 with images and guide videos" the KB is meant to hold).
const QUILL_MODULES = {
  toolbar: [
    [{ header: [2, 3, 4, false] }],
    ['bold', 'italic', 'underline', 'strike', 'blockquote', 'code-block'],
    [{ list: 'ordered' }, { list: 'bullet' }],
    ['link', 'image', 'video'],
    ['clean'],
  ],
}
const QUILL_FORMATS = [
  'header', 'bold', 'italic', 'underline', 'strike', 'blockquote', 'code-block',
  'list', 'bullet', 'link', 'image', 'video',
]

/**
 * Quill ships a hard-coded light skin — the editor used to be forced to
 * `bg-white text-black`, which punched a white hole through the dark theme.
 * These overrides repaint the toolbar, canvas and icons from design tokens, so
 * the editor follows light/dark like everything else.
 */
const QUILL_THEME_CSS = `
  .kb-editor .ql-toolbar.ql-snow{border:0;border-bottom:1px solid var(--border);background:var(--bg-input);padding:10px 12px}
  .kb-editor .ql-container.ql-snow{border:0;background:transparent;font-family:inherit}
  .kb-editor .ql-editor{min-height:340px;color:var(--text-body);font-size:15px;line-height:1.75}
  .kb-editor .ql-editor.ql-blank::before{color:var(--text-muted);opacity:.6;font-style:normal}
  .kb-editor .ql-editor h2{color:var(--text-h);font-size:1.35rem;font-weight:800;margin:1.4rem 0 .6rem}
  .kb-editor .ql-editor h3{color:var(--text-h);font-size:1.1rem;font-weight:700;margin:1.1rem 0 .4rem}
  .kb-editor .ql-editor strong{color:var(--text-h)}
  .kb-editor .ql-editor a{color:var(--color-support-500)}
  .kb-editor .ql-editor blockquote{border-left:3px solid var(--color-support-500);background:color-mix(in srgb,var(--color-support-500) 7%,transparent);padding:.5rem .9rem;border-radius:0 8px 8px 0;color:var(--text-body)}
  .kb-editor .ql-editor pre.ql-syntax{background:var(--bg-global);color:var(--text-body);border:1px solid var(--border);border-radius:10px}
  .kb-editor .ql-editor img{max-width:100%;border-radius:10px;border:1px solid var(--border)}
  .kb-editor .ql-editor iframe.ql-video{width:100%;aspect-ratio:16/9;height:auto;border:0;border-radius:10px}
  /* toolbar icons + labels */
  .kb-editor .ql-snow .ql-stroke{stroke:var(--text-muted)}
  .kb-editor .ql-snow .ql-fill{fill:var(--text-muted)}
  .kb-editor .ql-snow .ql-picker-label{color:var(--text-muted)}
  .kb-editor .ql-snow button:hover .ql-stroke,.kb-editor .ql-snow button.ql-active .ql-stroke,
  .kb-editor .ql-snow .ql-picker-label:hover,.kb-editor .ql-snow .ql-picker-label.ql-active{stroke:var(--color-support-500);color:var(--color-support-500)}
  .kb-editor .ql-snow button:hover .ql-fill,.kb-editor .ql-snow button.ql-active .ql-fill{fill:var(--color-support-500)}
  /* dropdown menus inside the toolbar */
  .kb-editor .ql-snow .ql-picker-options{background:var(--bg-card);border:1px solid var(--border);border-radius:10px;box-shadow:var(--shadow-card-3d)}
  .kb-editor .ql-snow .ql-picker-item{color:var(--text-body)}
  .kb-editor .ql-snow .ql-picker-item:hover{color:var(--color-support-500)}
  /* link/video tooltip */
  .kb-editor .ql-snow .ql-tooltip{background:var(--bg-card);border:1px solid var(--border);box-shadow:var(--shadow-card-3d);color:var(--text-body);border-radius:10px}
  .kb-editor .ql-snow .ql-tooltip input[type=text]{background:var(--bg-input);border:1px solid var(--border);color:var(--text-h);border-radius:6px}
  .kb-editor .ql-snow .ql-tooltip a.ql-action,.kb-editor .ql-snow .ql-tooltip a.ql-remove{color:var(--color-support-500)}
`

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

const EMPTY_ARTICLE = { id: null, title: '', excerpt: '', content: '', subcategory_id: null, department_id: null, tags: [] }

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
  const [tagDraft, setTagDraft] = useState('')
  // Replaces window.prompt() — { kind: 'category' | 'sub', categoryId? }
  const [addModal, setAddModal] = useState(null)
  const [tplConfirm, setTplConfirm] = useState(null)   // template pending confirmation

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
      const body = { title: a.title, excerpt: a.excerpt, content: a.content, subcategory_id: a.subcategory_id, department_id: a.department_id || null, tags: a.tags || [] }
      return a.id ? helpdeskApi.kb.updateArticle(a.id, body) : helpdeskApi.kb.createArticle(body)
    },
    onSuccess: (saved) => { setArticle(saved); refetchArticles() },
  })
  const publish = useMutation({ mutationFn: (id) => helpdeskApi.kb.publish(id), onSuccess: (res) => { setArticle(a => ({ ...a, ...(res.article || res), public_slug: res.public_slug || (res.article || res)?.public_slug })); refetchArticles() } })
  const unpublish = useMutation({ mutationFn: (id) => helpdeskApi.kb.unpublish(id), onSuccess: (res) => { setArticle(a => ({ ...a, ...(res.article || res), is_published: false })); refetchArticles() } })
  const delArticle = useMutation({ mutationFn: (id) => helpdeskApi.kb.deleteArticle(id), onSuccess: () => { setArticle(null); refetchArticles() } })

  // Tag helpers — free-text keywords stored on the article.
  const addTag = (raw) => {
    const t = (raw ?? tagDraft).trim()
    if (!t) return
    const existing = article?.tags || []
    if (existing.some(x => x.toLowerCase() === t.toLowerCase()) || existing.length >= 12) { setTagDraft(''); return }
    setArticle(a => ({ ...a, tags: [...(a.tags || []), t] }))
    setTagDraft('')
  }
  const removeTag = (t) => setArticle(a => ({ ...a, tags: (a.tags || []).filter(x => x !== t) }))
  // Always build the public link from the slug against the frontend origin — the
  // public article is a frontend route (/kb/a/:slug). The API's public_url points
  // at the JSON endpoint, so trusting it opened raw JSON for freshly-published
  // articles ("black screen"); the slug form is correct for new and old alike.
  const publicUrl = article?.public_slug ? `${location.origin}/kb/a/${article.public_slug}` : null

  const startNew = (tpl) => { setArticle({ ...EMPTY_ARTICLE, subcategory_id: selSub, content: tpl.content }); setTplOpen(false) }

  // "Convert reply to KB" from a ticket lands here with the reply prefilled — open
  // the editor with its title/content; the author just picks a sub-category & saves.
  const routerLocation = useLocation()
  useEffect(() => {
    const draft = routerLocation.state?.draftArticle
    if (draft) {
      setArticle({ ...EMPTY_ARTICLE, title: draft.title || '', content: draft.content || '', subcategory_id: selSub })
      window.history.replaceState({}, '')   // consume it so a refresh doesn't re-open
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [routerLocation.state])

  // Every sub-category across all categories, labelled "Category › Sub" so the
  // editor's picker is unambiguous when names repeat.
  const subOptions = categories.flatMap(c =>
    (c.subcategories || []).map(s => ({ value: s.id, label: `${c.name} › ${s.name}` }))
  )

  return (
    <div className="grid gap-5 lg:grid-cols-[280px_1fr]">
      {/* Category tree */}
      <div className="rounded-2xl p-3 space-y-1.5 h-fit" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card)' }}>
        <div className="flex items-center justify-between px-2 py-2 mb-1" style={{ borderBottom: '1px solid var(--border)' }}>
          <span className="text-xs font-bold uppercase tracking-wider" style={{ color: 'var(--text-muted)' }}>Categories</span>
          <button title="New category" onClick={() => setAddModal({ kind: 'category' })} className="hover:opacity-70" style={{ color: ACCENT }}><FolderPlus size={15} /></button>
        </div>
        {categories.length === 0 && <p className="text-xs px-2 py-3 text-center" style={{ color: 'var(--text-muted)' }}>No categories yet.</p>}
        {categories.map(cat => (
          <div key={cat.id}>
            <div className="flex items-center gap-1.5 group rounded-xl px-2 py-1.5" style={{ background: 'var(--bg-input)' }}>
              <span className="font-bold text-sm flex-1 truncate" style={{ color: 'var(--text-h)' }}>{cat.name}</span>
              <button title="Add sub-category" onClick={() => setAddModal({ kind: 'sub', categoryId: cat.id, categoryName: cat.name })} className="opacity-0 group-hover:opacity-60 hover:!opacity-100" style={{ color: 'var(--text-muted)' }}><Plus size={13} /></button>
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
                    {a.total_ratings > 0 && <RatingBadge avg={a.avg_rating} total={a.total_ratings} />}
                    <StatusPill published={a.is_published} />
                    <ChevronRight size={14} style={{ color: 'var(--text-muted)', opacity: 0.4 }} />
                  </button>
                </li>
              ))}
              {articles.length === 0 && <li className="text-sm text-center py-10" style={{ color: 'var(--text-muted)' }}>No articles yet. Click “New Article” to create one from a template.</li>}
            </ul>
          </div>
        )}

        {/* Editor — main canvas + metadata sidebar */}
        {article && (
          <div>
            <style>{QUILL_THEME_CSS}</style>

            {/* Editor toolbar row */}
            <div className="flex items-center gap-2 px-5 py-3 flex-wrap" style={{ borderBottom: '1px solid var(--border)' }}>
              <button onClick={() => setArticle(null)} className="flex items-center gap-1.5 text-xs font-bold px-2.5 py-1.5 rounded-lg hover:opacity-70" style={{ color: 'var(--text-muted)' }}>
                <ChevronRight size={13} style={{ transform: 'rotate(180deg)' }} /> Articles
              </button>
              <StatusPill published={!!article.is_published} />
              <div className="ml-auto flex items-center gap-2">
                {article.id && article.is_published && publicUrl && (
                  <a href={publicUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1.5 text-xs font-bold px-3 py-1.5 rounded-lg" style={{ border: '1px solid var(--border)', color: ACCENT }}>
                    <Eye size={13} /> Preview
                  </a>
                )}
                {article.id && (
                  <button onClick={() => delArticle.mutate(article.id)} title="Delete article" className="p-1.5 rounded-lg hover:opacity-100 opacity-60" style={{ color: 'var(--color-danger-500)' }}><Trash2 size={14} /></button>
                )}
                <button disabled={!article.title.trim() || saveArticle.isPending} onClick={() => saveArticle.mutate(article)}
                  className="flex items-center gap-1.5 text-xs font-bold px-4 py-2 rounded-xl disabled:opacity-40" style={{ background: ACCENT, color: '#fff' }}>
                  <Save size={13} /> {saveArticle.isPending ? 'Saving…' : 'Save'}
                </button>
              </div>
            </div>

            <div className="flex flex-col lg:flex-row items-start">
              {/* ── Main canvas */}
              <div className="flex-1 min-w-0 w-full p-5">
                <input value={article.title} onChange={e => setArticle({ ...article, title: e.target.value })} placeholder="Article title…"
                  className="w-full bg-transparent font-display font-black outline-none pb-2 mb-2" style={{ fontSize: 26, color: 'var(--text-h)', letterSpacing: '-0.02em' }} />
                <input value={article.excerpt || ''} onChange={e => setArticle({ ...article, excerpt: e.target.value })} placeholder="Short excerpt shown in listings and search results (optional)…"
                  className="w-full bg-transparent outline-none pb-4 mb-4" style={{ color: 'var(--text-muted)', fontSize: 14.5, borderBottom: '1px solid var(--border)' }} />

                <div className="kb-editor rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)', background: 'var(--bg-input)' }}>
                  <ReactQuill theme="snow" modules={QUILL_MODULES} formats={QUILL_FORMATS} value={article.content} onChange={v => setArticle({ ...article, content: v })} />
                </div>
                {saveArticle.isError && <p className="text-xs mt-2" style={{ color: 'var(--color-danger-500)' }}>{saveArticle.error?.message}</p>}
              </div>

              {/* ── Metadata sidebar */}
              <aside className="w-full lg:w-[290px] shrink-0 p-5 lg:pl-0 space-y-4">

                {/* Publish */}
                <SideCard icon={Globe} title="Publish" accent={article.is_published ? 'var(--color-success-500)' : 'var(--text-muted)'}>
                  {!article.id ? (
                    <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Save the article first, then you can publish it.</p>
                  ) : (
                    <>
                      <p className="text-xs mb-3" style={{ color: 'var(--text-muted)' }}>
                        {article.is_published ? 'Live — anyone with the link can read this.' : 'Draft — not visible to customers yet.'}
                      </p>
                      {!article.is_published ? (
                        <button disabled={publish.isPending} onClick={() => publish.mutate(article.id)} className="w-full flex items-center justify-center gap-1.5 text-xs font-bold py-2 rounded-xl disabled:opacity-40" style={{ background: 'var(--color-success-500)', color: '#fff' }}>
                          <Globe size={13} /> {publish.isPending ? 'Publishing…' : 'Publish article'}
                        </button>
                      ) : (
                        <button disabled={unpublish.isPending} onClick={() => unpublish.mutate(article.id)} className="w-full flex items-center justify-center gap-1.5 text-xs font-semibold py-2 rounded-xl disabled:opacity-40" style={{ border: '1px solid var(--border)', color: 'var(--text-muted)' }}>
                          <EyeOff size={13} /> Unpublish
                        </button>
                      )}
                      {article.is_published && publicUrl && (
                        <div className="mt-3 p-2 rounded-lg" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
                          <p className="text-[10px] font-mono truncate mb-1.5" style={{ color: 'var(--text-muted)' }}>{publicUrl}</p>
                          <div className="flex items-center gap-2">
                            <button onClick={() => { navigator.clipboard?.writeText(publicUrl); setCopied(true); setTimeout(() => setCopied(false), 1500) }} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: 'var(--color-success-500)' }}>
                              {copied ? <Check size={11} /> : <LinkIcon size={11} />}{copied ? 'Copied' : 'Copy link'}
                            </button>
                            <a href={publicUrl} target="_blank" rel="noreferrer" className="flex items-center gap-1 text-[11px] font-bold ml-auto" style={{ color: ACCENT }}><ExternalLink size={11} /> Open</a>
                          </div>
                        </div>
                      )}
                    </>
                  )}
                </SideCard>

                {/* Ratings (REQ-11) — star feedback left by readers */}
                {article.id && (
                  <RatingsCard articleId={article.id} avg={article.avg_rating} total={article.total_ratings} />
                )}

                {/* Organize */}
                <SideCard icon={FolderTree} title="Organize">
                  <Field label="Sub-category">
                    <Select
                      value={article.subcategory_id ?? ''}
                      onChange={v => setArticle({ ...article, subcategory_id: v ? Number(v) : null })}
                      options={subOptions}
                      placeholder="Choose a sub-category"
                      size="sm"
                      ariaLabel="Sub-category"
                    />
                  </Field>
                  <Field label="Department">
                    <Select
                      value={article.department_id ?? ''}
                      onChange={v => setArticle({ ...article, department_id: v ? Number(v) : null })}
                      options={[{ value: '', label: '— none —' }, ...departments.map(d => ({ value: d.id, label: d.name }))]}
                      placeholder="— none —"
                      size="sm"
                      ariaLabel="Department"
                    />
                  </Field>
                </SideCard>

                {/* Tags */}
                <SideCard icon={Tag} title="Tags" count={(article.tags || []).length}>
                  {(article.tags || []).length > 0 && (
                    <div className="flex flex-wrap gap-1.5 mb-2">
                      {(article.tags || []).map(t => (
                        <span key={t} className="inline-flex items-center gap-1 text-[11px] font-semibold px-2 py-1 rounded-lg"
                          style={{ background: `color-mix(in srgb, ${ACCENT} 13%, transparent)`, color: ACCENT }}>
                          {t}
                          <button onClick={() => removeTag(t)} className="hover:opacity-60" aria-label={`Remove ${t}`}><X size={10} /></button>
                        </span>
                      ))}
                    </div>
                  )}
                  <div className="flex items-center gap-1.5">
                    <input
                      value={tagDraft}
                      onChange={e => setTagDraft(e.target.value)}
                      onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addTag() } }}
                      placeholder={(article.tags || []).length >= 12 ? 'Tag limit reached' : 'add a tag…'}
                      disabled={(article.tags || []).length >= 12}
                      className="flex-1 rounded-lg outline-none"
                      style={{ padding: '6px 10px', fontSize: 12, background: 'var(--bg-card)', border: '1px solid var(--border)', color: 'var(--text-h)' }}
                    />
                    <button onClick={() => addTag()} disabled={!tagDraft.trim()} className="p-1.5 rounded-lg disabled:opacity-30" style={{ background: `color-mix(in srgb, ${ACCENT} 13%, transparent)`, color: ACCENT }}><Plus size={13} /></button>
                  </div>
                  <p className="text-[10px] mt-1.5" style={{ color: 'var(--text-muted)', opacity: 0.7 }}>Keywords that help readers find this article.</p>
                </SideCard>

                {/* Templates */}
                <SideCard icon={LayoutTemplate} title="Template">
                  <p className="text-[11px] mb-2" style={{ color: 'var(--text-muted)' }}>Replace the body with a starter structure.</p>
                  <div className="grid grid-cols-2 gap-1.5">
                    {TEMPLATES.filter(t => t.key !== 'blank').map(t => (
                      <button key={t.key}
                        onClick={() => setTplConfirm(t)}
                        className="flex items-center gap-1.5 px-2 py-1.5 rounded-lg text-left transition-colors"
                        style={{ border: '1px solid var(--border)', color: 'var(--text-body)' }}
                        onMouseEnter={e => e.currentTarget.style.background = 'var(--bg-card)'}
                        onMouseLeave={e => e.currentTarget.style.background = 'transparent'}>
                        <t.icon size={12} style={{ color: ACCENT, flexShrink: 0 }} />
                        <span className="text-[11px] font-semibold truncate">{t.name}</span>
                      </button>
                    ))}
                  </div>
                </SideCard>
              </aside>
            </div>
          </div>
        )}
      </div>

      {/* Applying a template overwrites the body — confirm first */}
      <ConfirmModal
        open={!!tplConfirm}
        onClose={() => setTplConfirm(null)}
        onConfirm={() => setArticle(a => ({ ...a, content: tplConfirm.content }))}
        title={`Use the “${tplConfirm?.name}” template?`}
        message="This replaces the current article body. Anything you've written will be lost."
        confirmLabel="Replace body"
        danger
      />

      {/* Add category / sub-category — replaces window.prompt */}
      <InputModal
        open={!!addModal}
        onClose={() => setAddModal(null)}
        title={addModal?.kind === 'sub' ? 'New sub-category' : 'New category'}
        subtitle={addModal?.kind === 'sub' ? `Inside “${addModal?.categoryName}”` : 'A top-level group in your knowledge base'}
        placeholder={addModal?.kind === 'sub' ? 'e.g. Payments' : 'e.g. Billing & Plans'}
        submitLabel="Create"
        accent={ACCENT}
        onSubmit={(name) => {
          if (addModal?.kind === 'sub') addSub.mutate({ category_id: addModal.categoryId, name })
          else addCategory.mutate(name)
        }}
      />

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

const fmtFeedbackDate = d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : ''

/** Compact amber star badge for the article list (REQ-11). */
function RatingBadge({ avg, total }) {
  return (
    <span className="inline-flex items-center gap-1 text-[11px] font-bold px-2 py-0.5 rounded-full" style={{ background: 'rgba(245,158,11,0.14)', color: '#b45309' }}>
      <Star size={11} style={{ fill: '#f59e0b', color: '#f59e0b' }} /> {(Number(avg) || 0).toFixed(1)} ({total})
    </span>
  )
}

/** Read-only 5-star display. */
function Stars({ value = 0, size = 14 }) {
  return (
    <span className="inline-flex" style={{ gap: 1 }}>
      {[1, 2, 3, 4, 5].map(n => (
        <Star key={n} size={size} style={{ color: n <= value ? '#f59e0b' : 'var(--border)', fill: n <= value ? '#f59e0b' : 'none' }} />
      ))}
    </span>
  )
}

/**
 * Editor-sidebar card summarising an article's star ratings, with an expandable
 * list of each {rating, comment, date}. Feedback is only fetched once expanded.
 */
function RatingsCard({ articleId, avg, total }) {
  const [open, setOpen] = useState(false)
  const { data: list = [], isLoading } = useQuery({
    queryKey: ['kb-feedback', articleId],
    queryFn: () => helpdeskApi.kb.articleFeedback(articleId),
    enabled: open,
  })
  const count = total ?? list.length
  const average = Number(avg) || 0

  return (
    <SideCard icon={Star} title="Ratings" accent="#f59e0b">
      {count > 0 ? (
        <div className="flex items-center gap-2 mb-2">
          <Stars value={Math.round(average)} />
          <span className="text-sm font-bold" style={{ color: 'var(--text-h)' }}>{average.toFixed(1)}</span>
          <span className="text-xs" style={{ color: 'var(--text-muted)' }}>· {count} rating{count === 1 ? '' : 's'}</span>
        </div>
      ) : (
        <p className="text-xs mb-2" style={{ color: 'var(--text-muted)' }}>No star ratings yet.</p>
      )}

      <button onClick={() => setOpen(o => !o)} className="flex items-center gap-1 text-[11px] font-bold" style={{ color: 'var(--color-support-500)' }}>
        <MessageSquare size={11} /> {open ? 'Hide feedback' : 'View feedback'}
      </button>

      {open && (
        <div className="mt-2 space-y-2" style={{ maxHeight: 260, overflow: 'auto' }}>
          {isLoading && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>Loading…</p>}
          {!isLoading && list.length === 0 && <p className="text-xs" style={{ color: 'var(--text-muted)' }}>No feedback yet.</p>}
          {list.map(f => (
            <div key={f.id} className="rounded-lg p-2" style={{ background: 'var(--bg-card)', border: '1px solid var(--border)' }}>
              <div className="flex items-center justify-between mb-0.5">
                <Stars value={f.rating} size={12} />
                <span className="text-[10px]" style={{ color: 'var(--text-muted)' }}>{fmtFeedbackDate(f.created_at)}</span>
              </div>
              {f.comment && <p className="text-xs" style={{ color: 'var(--text-body)' }}>{f.comment}</p>}
            </div>
          ))}
        </div>
      )}
    </SideCard>
  )
}

/** A metadata card in the article editor's right rail. */
function SideCard({ icon: Icon, title, count, accent = 'var(--color-support-500)', children }) {
  return (
    <section className="rounded-2xl overflow-hidden" style={{ background: 'var(--bg-input)', border: '1px solid var(--border)' }}>
      <header className="flex items-center gap-2 px-3.5 py-2.5" style={{ borderBottom: '1px solid var(--border)' }}>
        <Icon size={13} style={{ color: accent }} />
        <h3 className="text-[11px] font-black uppercase tracking-wider flex-1" style={{ color: 'var(--text-h)' }}>{title}</h3>
        {count !== undefined && (
          <span className="text-[10px] font-bold px-1.5 py-0.5 rounded-md" style={{ background: 'var(--bg-card)', color: 'var(--text-muted)' }}>{count}</span>
        )}
      </header>
      <div className="p-3.5 space-y-3">{children}</div>
    </section>
  )
}

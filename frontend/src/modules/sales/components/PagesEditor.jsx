import { useState } from 'react'
import { Plus, Trash2, ChevronUp, ChevronDown, FileText } from 'lucide-react'
import RichTextEditor from '@/components/ui/RichTextEditor'

/**
 * Multi-page rich-text editor (proposal/template/contract content).
 * `pages` = [{ title, content }] — fully controlled via onChange.
 */
export default function PagesEditor({ pages = [], onChange, minHeight = 320 }) {
  const [active, setActive] = useState(0)
  const list = pages.length ? pages : [{ title: 'Page 1', content: '' }]
  const idx = Math.min(active, list.length - 1)

  const commit = (next, nextActive = idx) => {
    onChange(next)
    setActive(Math.max(0, Math.min(nextActive, next.length - 1)))
  }

  const addPage = () => commit([...list, { title: `Page ${list.length + 1}`, content: '' }], list.length)
  const removePage = (i) => {
    if (list.length === 1) return
    commit(list.filter((_, x) => x !== i), i > 0 ? i - 1 : 0)
  }
  const move = (i, dir) => {
    const j = i + dir
    if (j < 0 || j >= list.length) return
    const next = [...list]
    ;[next[i], next[j]] = [next[j], next[i]]
    commit(next, j)
  }
  const patch = (i, field, value) => {
    commit(list.map((p, x) => x === i ? { ...p, [field]: value } : p))
  }

  return (
    <div className="flex gap-4 flex-col lg:flex-row">
      {/* Page list */}
      <div className="lg:w-56 flex-shrink-0 space-y-1.5">
        {list.map((p, i) => (
          <div key={i}
            onClick={() => setActive(i)}
            className="flex items-center gap-2 px-3 py-2.5 rounded-xl cursor-pointer transition-colors"
            style={{
              background: i === idx ? 'rgba(124,58,237,0.1)' : 'var(--bg-input)',
              border: `1px solid ${i === idx ? 'var(--border-purple)' : 'var(--border)'}`,
            }}>
            <FileText size={13} style={{ color: i === idx ? 'var(--accent)' : 'var(--text-muted)', flexShrink: 0 }} />
            <span className="text-xs font-bold truncate flex-1" style={{ color: i === idx ? 'var(--accent)' : 'var(--text-h)' }}>{p.title || `Page ${i + 1}`}</span>
            <span className="flex gap-0.5" onClick={e => e.stopPropagation()}>
              <button onClick={() => move(i, -1)} disabled={i === 0} className="p-0.5 rounded disabled:opacity-25" title="Move up"><ChevronUp size={11} style={{ color: 'var(--text-muted)' }} /></button>
              <button onClick={() => move(i, 1)} disabled={i === list.length - 1} className="p-0.5 rounded disabled:opacity-25" title="Move down"><ChevronDown size={11} style={{ color: 'var(--text-muted)' }} /></button>
              <button onClick={() => removePage(i)} disabled={list.length === 1} className="p-0.5 rounded disabled:opacity-25" title="Delete page"><Trash2 size={11} style={{ color: '#f87171' }} /></button>
            </span>
          </div>
        ))}
        <button onClick={addPage} className="w-full flex items-center justify-center gap-1.5 px-3 py-2.5 rounded-xl text-xs font-bold transition-colors"
          style={{ border: '1px dashed var(--border-purple)', color: 'var(--accent)', background: 'transparent' }}>
          <Plus size={12} /> Add page
        </button>
      </div>

      {/* Active page */}
      <div className="flex-1 min-w-0 space-y-3">
        <input
          className="input-3d text-sm font-bold"
          placeholder="Page title"
          value={list[idx].title}
          onChange={e => patch(idx, 'title', e.target.value)}
        />
        <RichTextEditor
          key={idx}
          value={list[idx].content}
          onChange={v => patch(idx, 'content', v)}
          placeholder="Write this page's content — text, tables, images…"
          minHeight={minHeight}
        />
      </div>
    </div>
  )
}

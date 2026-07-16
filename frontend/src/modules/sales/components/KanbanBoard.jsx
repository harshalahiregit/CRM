import { useState } from 'react'

/**
 * Reusable kanban board. Desktop uses native HTML5 drag-drop; touch devices
 * (where HTML5 drag doesn't fire) get a tap-to-move menu fallback.
 *
 *   <KanbanBoard
 *     columns={[{ key, label, color, items: [...] }]}
 *     getId={(item) => item.id}
 *     renderCard={(item) => <Card .../>}
 *     onCardMove={(cardId, toColumnKey) => ...}
 *   />
 */
export default function KanbanBoard({ columns = [], getId, renderCard, onCardMove }) {
  const [dragId, setDragId] = useState(null)
  const [overCol, setOverCol] = useState(null)
  const [moveMenuFor, setMoveMenuFor] = useState(null) // card id whose touch move-menu is open

  const handleDrop = (colKey) => {
    if (dragId != null && onCardMove) onCardMove(dragId, colKey)
    setDragId(null)
    setOverCol(null)
  }

  return (
    <div className="flex gap-3 overflow-x-auto pb-2">
      {columns.map(col => (
        <div
          key={col.key}
          onDragOver={(e) => { e.preventDefault(); setOverCol(col.key) }}
          onDragLeave={() => setOverCol(c => (c === col.key ? null : c))}
          onDrop={() => handleDrop(col.key)}
          className="flex-shrink-0 w-72 rounded-2xl p-2 transition-colors"
          style={{
            background: overCol === col.key ? 'rgba(124,58,237,0.08)' : 'var(--bg-input)',
            border: '1px solid var(--border)',
          }}
        >
          <div className="flex items-center justify-between px-2 py-2">
            <div className="flex items-center gap-2">
              <span className="w-2.5 h-2.5 rounded-full" style={{ background: col.color || '#7C3AED' }} />
              <span className="text-xs font-bold" style={{ color: 'var(--text-body)' }}>{col.label}</span>
            </div>
            <span className="text-[11px] font-bold px-1.5 py-0.5 rounded-md"
              style={{ background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>
              {col.items?.length || 0}
            </span>
          </div>

          <div className="space-y-2 min-h-[40px]">
            {(col.items || []).map(item => {
              const id = getId(item)
              return (
                <div
                  key={id}
                  draggable
                  onDragStart={() => setDragId(id)}
                  onDragEnd={() => { setDragId(null); setOverCol(null) }}
                  className="relative rounded-xl cursor-grab active:cursor-grabbing"
                  style={{ opacity: dragId === id ? 0.5 : 1 }}
                >
                  {renderCard(item)}
                  {/* Touch fallback: tap ⋯ to move to another column */}
                  <button
                    type="button"
                    onClick={(e) => { e.stopPropagation(); setMoveMenuFor(m => (m === id ? null : id)) }}
                    className="absolute top-1.5 right-1.5 md:hidden text-[10px] font-bold px-1.5 rounded"
                    style={{ background: 'var(--bg-card)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}
                    aria-label="Move card"
                  >⋯</button>
                  {moveMenuFor === id && (
                    <div className="absolute z-10 top-7 right-1.5 rounded-lg p-1 md:hidden"
                      style={{ background: 'var(--bg-card)', border: '1px solid var(--border)', boxShadow: 'var(--shadow-card-3d)' }}>
                      {columns.filter(c => c.key !== col.key).map(c => (
                        <button key={c.key} type="button"
                          onClick={(e) => { e.stopPropagation(); onCardMove?.(id, c.key); setMoveMenuFor(null) }}
                          className="block w-full text-left text-[11px] px-2 py-1 rounded"
                          style={{ color: 'var(--text-body)' }}>
                          → {c.label}
                        </button>
                      ))}
                    </div>
                  )}
                </div>
              )
            })}
          </div>
        </div>
      ))}
    </div>
  )
}

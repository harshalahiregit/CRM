/**
 * MediaLightbox — global click-to-enlarge for images and videos embedded in any
 * rich-content area (.rich-content, .rte-body, .task-comment-html,
 * .proj-note-html, .reply-html, .pub-prose, .hc-prose …).
 *
 * Uses event delegation on document so it requires no per-component wiring:
 * mount this once at the app root and every img/video in a rich-content area
 * gets the lightbox automatically.
 */
import { useState, useEffect, useCallback } from 'react'
import { createPortal } from 'react-dom'
import { X, ZoomIn, Download, ChevronLeft, ChevronRight } from 'lucide-react'

// CSS class names that flag a container as rich content — clicks on img/video
// inside these get intercepted and shown in the lightbox.
const RICH_SELECTORS = [
  '.rich-content',
  '.rte-body',
  '.task-comment-html',
  '.proj-note-html',
  '.reply-html',
  '.pub-prose',
  '.hc-prose',
  '[data-rich]',
].join(', ')

function isInsideRichArea(el) {
  return !!el.closest(RICH_SELECTORS)
}

// Collect all sibling images inside the same rich container for prev/next navigation.
function gatherSiblingImages(clickedImg) {
  const container = clickedImg.closest(RICH_SELECTORS)
  if (!container) return { items: [{ type: 'image', src: clickedImg.src, alt: clickedImg.alt }], index: 0 }
  const imgs = Array.from(container.querySelectorAll('img'))
  const index = imgs.indexOf(clickedImg)
  return { items: imgs.map(i => ({ type: 'image', src: i.src, alt: i.alt })), index: Math.max(0, index) }
}

export default function MediaLightbox() {
  const [media, setMedia] = useState(null) // { type:'image'|'video', src, alt, items?, index? }

  const open = useCallback((e) => {
    const tgt = e.target
    // Image click
    if (tgt.tagName === 'IMG' && isInsideRichArea(tgt)) {
      e.preventDefault()
      e.stopPropagation()
      const { items, index } = gatherSiblingImages(tgt)
      setMedia({ type: 'image', src: tgt.src, alt: tgt.alt, items, index })
      return
    }
    // Video click — pop up if video has a src and is paused (not playing through controls)
    if (tgt.tagName === 'VIDEO' && isInsideRichArea(tgt)) {
      const src = tgt.src || tgt.querySelector('source')?.src
      if (src && tgt.paused) {
        e.preventDefault()
        setMedia({ type: 'video', src })
      }
    }
  }, [])

  const close = useCallback(() => setMedia(null), [])

  const navigate = useCallback((dir) => {
    setMedia(prev => {
      if (!prev?.items || prev.items.length <= 1) return prev
      const next = (prev.index + dir + prev.items.length) % prev.items.length
      const item = prev.items[next]
      return { ...prev, ...item, index: next }
    })
  }, [])

  useEffect(() => {
    document.addEventListener('click', open, true)
    return () => document.removeEventListener('click', open, true)
  }, [open])

  useEffect(() => {
    if (!media) return
    const onKey = (e) => {
      if (e.key === 'Escape') close()
      if (e.key === 'ArrowLeft') navigate(-1)
      if (e.key === 'ArrowRight') navigate(1)
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [media, close, navigate])

  // Inside rich-content areas, render inserted images/videos as a size-capped
  // PREVIEW (not a giant full-bleed block) with a zoom cursor — clicking opens
  // the full-size lightbox. Height/width are capped and scaled proportionally
  // (width:auto + max constraints) so a big square image no longer swallows the
  // whole card; an author's explicit inline width (System B: width:33%/66%/…)
  // still wins because these rules aren't !important on width.
  useEffect(() => {
    const id = 'media-lb-cursor-style'
    if (!document.getElementById(id)) {
      const el = document.createElement('style')
      el.id = id
      const richImg = [
        '.rich-content img', '.rte-body img', '.task-comment-html img',
        '.proj-note-html img', '.reply-html img', '.pub-prose img', '.hc-prose img',
      ].join(',')
      const richVid = [
        '.rich-content video', '.rte-body video', '.task-comment-html video',
        '.proj-note-html video', '.reply-html video', '.pub-prose video', '.hc-prose video',
      ].join(',')
      el.textContent = [
        `${richImg} { cursor:zoom-in !important; width:auto; height:auto; max-width:100%; max-height:320px; border-radius:8px; object-fit:contain; }`,
        `${richVid} { cursor:pointer; max-width:100%; max-height:360px; border-radius:8px; }`,
      ].join('\n')
      document.head.appendChild(el)
    }
  }, [])

  if (!media) return null

  return createPortal(
    <div
      role="dialog"
      aria-modal="true"
      aria-label="Media viewer"
      onClick={close}
      style={{
        position: 'fixed', inset: 0, zIndex: 9999,
        background: 'rgba(0,0,0,0.88)',
        display: 'flex', alignItems: 'center', justifyContent: 'center',
        backdropFilter: 'blur(6px)',
        animation: 'lb-fade-in 0.18s ease',
      }}
    >
      <style>{`
        @keyframes lb-fade-in { from { opacity:0; } to { opacity:1; } }
        @keyframes lb-scale-in { from { transform:scale(0.93); opacity:0; } to { transform:scale(1); opacity:1; } }
        .lb-media { animation: lb-scale-in 0.2s cubic-bezier(.22,.68,0,1.2); }
        .lb-btn {
          position:absolute; top:16px;
          width:40px; height:40px; border-radius:50%;
          display:flex; align-items:center; justify-content:center;
          background:rgba(255,255,255,0.13); backdrop-filter:blur(8px);
          border:1px solid rgba(255,255,255,0.18);
          color:#fff; cursor:pointer; transition:background 0.15s;
          text-decoration:none;
        }
        .lb-btn:hover { background:rgba(255,255,255,0.25); }
        .lb-nav {
          position:absolute; top:50%; transform:translateY(-50%);
          width:44px; height:44px; border-radius:50%;
          display:flex; align-items:center; justify-content:center;
          background:rgba(255,255,255,0.13); backdrop-filter:blur(8px);
          border:1px solid rgba(255,255,255,0.2);
          color:#fff; cursor:pointer; transition:background 0.15s;
        }
        .lb-nav:hover { background:rgba(255,255,255,0.28); }
        .lb-counter {
          position:absolute; bottom:20px; left:50%; transform:translateX(-50%);
          color:rgba(255,255,255,0.7); font-size:13px; font-weight:600;
          background:rgba(0,0,0,0.45); padding:4px 12px; border-radius:20px;
          backdrop-filter:blur(6px);
          pointer-events:none;
        }
        .lb-caption {
          position:absolute; bottom:52px; left:50%; transform:translateX(-50%);
          color:rgba(255,255,255,0.85); font-size:12px; max-width:80vw;
          text-align:center; text-shadow:0 1px 4px rgba(0,0,0,0.5);
          pointer-events:none;
        }
      `}</style>

      {/* Close */}
      <button className="lb-btn" onClick={close} title="Close (Esc)"
        style={{ right: 16 }} aria-label="Close">
        <X size={18} />
      </button>

      {/* Download (images only) */}
      {media.type === 'image' && (
        <a className="lb-btn" href={media.src} download target="_blank"
          rel="noopener noreferrer" title="Download"
          onClick={e => e.stopPropagation()} style={{ right: 64 }} aria-label="Download">
          <Download size={16} />
        </a>
      )}

      {/* Zoom hint */}
      {media.type === 'image' && (
        <span className="lb-btn" style={{ right: 112, cursor: 'default' }} title="Click outside to close">
          <ZoomIn size={16} />
        </span>
      )}

      {/* Prev */}
      {media.items?.length > 1 && (
        <button className="lb-nav" style={{ left: 16 }}
          onClick={e => { e.stopPropagation(); navigate(-1) }} aria-label="Previous">
          <ChevronLeft size={22} />
        </button>
      )}

      {/* Content */}
      {media.type === 'image' ? (
        <img
          className="lb-media"
          src={media.src}
          alt={media.alt || ''}
          onClick={e => e.stopPropagation()}
          style={{
            maxWidth: '90vw', maxHeight: '88vh',
            objectFit: 'contain', borderRadius: 12,
            boxShadow: '0 32px 80px rgba(0,0,0,0.7)',
            cursor: 'default',
          }}
        />
      ) : (
        <video
          className="lb-media"
          src={media.src}
          controls
          autoPlay
          onClick={e => e.stopPropagation()}
          style={{
            maxWidth: '90vw', maxHeight: '88vh',
            borderRadius: 12,
            boxShadow: '0 32px 80px rgba(0,0,0,0.7)',
          }}
        />
      )}

      {/* Next */}
      {media.items?.length > 1 && (
        <button className="lb-nav" style={{ right: 16 }}
          onClick={e => { e.stopPropagation(); navigate(1) }} aria-label="Next">
          <ChevronRight size={22} />
        </button>
      )}

      {/* Caption */}
      {media.alt && <p className="lb-caption">{media.alt}</p>}

      {/* Counter */}
      {media.items?.length > 1 && (
        <div className="lb-counter">{media.index + 1} / {media.items.length}</div>
      )}
    </div>,
    document.body,
  )
}

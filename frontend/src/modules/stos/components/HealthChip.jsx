import { toneOf } from '@/services/stosApi'

/**
 * The traffic light, rendered. Green = healthy, amber = attention, red = blocked.
 *
 * Colour is never the only signal — the tone's word ("Blocked") sits beside the
 * dot, because a red/green dot alone is invisible to a colour-blind operator
 * and meaningless in a screenshot pasted into WhatsApp.
 */
export default function HealthChip({ tone = 'green', children, size = 'md' }) {
  const t = toneOf(tone)
  const small = size === 'sm'

  return (
    <span
      className={`inline-flex items-center gap-1.5 rounded-lg font-bold ${small ? 'px-1.5 py-0.5 text-[10px]' : 'px-2 py-1 text-[11px]'}`}
      style={{
        background: `color-mix(in srgb, ${t.dot} 14%, transparent)`,
        color: t.dot,
      }}
    >
      <span className="rounded-full shrink-0" style={{ width: small ? 5 : 6, height: small ? 5 : 6, background: t.dot }} />
      {children ?? t.label}
    </span>
  )
}

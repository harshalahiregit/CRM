/**
 * On/off toggle switch (slider) — mirrors the old CRM's active/inactive switch.
 * `checked` = on (green), unchecked = off (grey). Fires onChange on click.
 */
export default function ToggleSwitch({ checked, onChange, title, size = 'md' }) {
  const w = size === 'sm' ? 34 : 38
  const h = size === 'sm' ? 20 : 22
  const knob = h - 6
  return (
    <button
      type="button"
      role="switch"
      aria-checked={checked}
      title={title}
      onClick={onChange}
      className="relative inline-flex items-center rounded-full transition-colors duration-200 shrink-0"
      style={{ width: w, height: h, background: checked ? '#10b981' : 'rgba(148,163,184,0.45)' }}
    >
      <span
        className="absolute rounded-full bg-white transition-all duration-200"
        style={{ width: knob, height: knob, left: checked ? w - knob - 3 : 3, boxShadow: '0 1px 3px rgba(0,0,0,0.25)' }}
      />
    </button>
  )
}

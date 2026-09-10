/**
 * SIRE — the ten tiles.
 *
 * Every tile is a button: its number and the list you get by clicking it come
 * from the SAME scope on the server, so they cannot disagree. A dashboard whose
 * tile says 12 and whose drill-down shows 9 is worse than no dashboard.
 *
 * STYLING NOTE. These used raw Tailwind greys (border-gray-200 / dark:border-
 * gray-700), which is precisely what made SIRE read as a module bolted onto the
 * CRM rather than part of it: the same surface was a different grey here than
 * three pixels away in Helpdesk. Everything now resolves through the app's own
 * tokens — var(--bg-card), var(--border), var(--text-h) — so SIRE follows the
 * theme, including the light/dark switch, without naming a colour of its own.
 *
 * Tone stays semantic (a breach is red wherever it appears) and is applied to
 * the NUMBER only. The tile itself is never washed in colour: ten tinted boxes
 * side by side is a harder thing to read than ten plain ones with a coloured
 * figure.
 */
import { TILES } from '../../../lib/sire/dashboardFilters';

/** Semantic tone → the app's own status colours. */
const TONE = {
  slate:  'var(--text-h)',
  red:    'var(--color-danger-500)',
  orange: 'var(--color-warning-500)',
  blue:   'var(--color-info-500)',
  violet: 'var(--color-primary-400)',
  green:  'var(--color-success-500)',
};

export default function DashboardTiles({ counts = {}, active, onSelect, loading }) {
  return (
    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
      {TILES.map((tile) => {
        const value = counts[tile.scope];
        const isActive = active === tile.scope;
        const tone = TONE[tile.tone] ?? TONE.slate;

        return (
          <button
            key={tile.key}
            type="button"
            onClick={() => onSelect(isActive ? null : tile.scope)}
            aria-pressed={isActive}
            className="group relative overflow-hidden rounded-2xl p-4 text-left transition-all duration-200"
            style={{
              background: 'var(--bg-card)',
              // The selected tile is marked by its own accent, not by a heavier
              // grey — so which set the table is showing is readable at a glance.
              border: `1px solid ${isActive ? tone : 'var(--border)'}`,
              boxShadow: isActive
                ? `0 8px 24px -12px ${tone}, inset 0 1px 0 var(--card-shine)`
                : 'var(--shadow-card)',
              transform: isActive ? 'translateY(-1px)' : undefined,
            }}
          >
            {/* The same decorative orb the module headers use, in the tile's own
                tone. Very low opacity: it should register as depth, not colour. */}
            <span
              aria-hidden
              className="pointer-events-none absolute -right-5 -top-5 h-16 w-16 rounded-full opacity-[0.10] transition-opacity group-hover:opacity-[0.18]"
              style={{ background: tone }}
            />

            <div
              className="relative z-10 font-black tabular-nums"
              style={{ fontSize: '1.75rem', lineHeight: 1, letterSpacing: '-0.03em', color: loading ? 'var(--text-faint)' : tone }}
            >
              {loading ? '—' : (value ?? 0)}
            </div>

            <div
              className="relative z-10 mt-1.5 text-[11px] font-medium leading-tight"
              style={{ color: isActive ? 'var(--text-body)' : 'var(--text-muted)' }}
            >
              {tile.label}
            </div>
          </button>
        );
      })}
    </div>
  );
}
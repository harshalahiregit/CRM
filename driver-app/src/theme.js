// Sangoé Driver — the design system's tokens.
//
// One source of truth for colour, space, radius, type and elevation, so every
// screen shares the same rhythm and the app reads as one product, not a set of
// prototypes. Dark "fleet tower" brand, made premium.

// ── Colour roles ────────────────────────────────────────────────────────────
export const theme = {
  // Surfaces, from the page up to the most raised element.
  bg: '#0a0f1c',          // app background
  surface: '#111a2e',     // cards, sheets
  surfaceAlt: '#0e1626',  // subtle alternate block
  elevated: '#18233b',    // raised / pressed surfaces
  input: '#152039',       // fields
  border: '#243250',      // hairlines
  borderStrong: '#33456b',

  // Text, on dark.
  text: '#eef3fb',
  textMuted: '#93a4c2',
  textFaint: '#63739a',
  onPrimary: '#04121a',

  // Brand + status. Each has a soft tint for backgrounds.
  primary: '#22d3ee',
  primaryDark: '#0891b2',
  primaryTint: 'rgba(34,211,238,0.14)',
  success: '#34d399',
  successTint: 'rgba(52,211,153,0.15)',
  warning: '#fbbf24',
  warningTint: 'rgba(251,191,36,0.15)',
  danger: '#f87171',
  dangerTint: 'rgba(248,113,113,0.15)',

  // Legacy aliases (older screens referenced these names).
  card: '#111a2e',
  accent: '#22d3ee',
  accentDark: '#0891b2',
}

// ── Spacing — a 4pt rhythm ──────────────────────────────────────────────────
export const space = { xs: 4, sm: 8, md: 12, lg: 16, xl: 20, xxl: 28, xxxl: 40 }

// ── Radius ──────────────────────────────────────────────────────────────────
export const radius = { sm: 8, md: 12, lg: 16, xl: 20, pill: 999 }

// ── Type scale (base sizes; screens scale them for the device) ──────────────
export const type = {
  display: { size: 30, weight: '900', line: 36 },
  title:   { size: 23, weight: '900', line: 29 },
  heading: { size: 18, weight: '800', line: 24 },
  body:    { size: 15, weight: '500', line: 21 },
  label:   { size: 14, weight: '700', line: 18 },
  caption: { size: 12.5, weight: '600', line: 16 },
}

// ── Elevation (Android + iOS shadow) ────────────────────────────────────────
export const shadow = {
  card: {
    shadowColor: '#000', shadowOpacity: 0.35, shadowRadius: 16,
    shadowOffset: { width: 0, height: 8 }, elevation: 6,
  },
  bar: {
    shadowColor: '#000', shadowOpacity: 0.25, shadowRadius: 10,
    shadowOffset: { width: 0, height: 4 }, elevation: 8,
  },
}

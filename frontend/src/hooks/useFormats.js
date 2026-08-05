import { useState, useEffect } from 'react'
import api from '@/lib/api'

/**
 * The tenant's currency + localization formatting contract, fetched once per
 * session from /settings/formats (the same values the backend SettingsFormatter
 * uses). Every module should format money/numbers/dates through this hook — no
 * module should hardcode a currency symbol or date pattern.
 *
 *   const { money, date, dateTime, number } = useFormats()
 *   money(1234.5)   // ₹1,234.50
 */
const FALLBACK = {
  currency: {
    code: 'INR', symbol: '₹', symbol_position: 'before', decimal_places: 2,
    decimal_separator: '.', thousands_separator: ',', negative_format: 'minus', grouping: 'indian',
  },
  localization: {
    language: 'en', locale: 'en_IN', timezone: 'Asia/Kolkata',
    date_format: 'd/m/Y', time_format: '12', week_start_day: 1,
  },
}

let cache = null // module-level: formats change rarely; one fetch per session

/** Indian grouping (1,23,456.78) vs western (1,234,567.89). */
function group(intPart, sep, style) {
  if (style === 'indian' && intPart.length > 3) {
    const last3 = intPart.slice(-3)
    const rest = intPart.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, sep)
    return rest + sep + last3
  }
  return intPart.replace(/\B(?=(\d{3})+(?!\d))/g, sep)
}

export function formatNumberWith(c, value) {
  const n = Number(value ?? 0)
  const decimals = Number(c.decimal_places ?? 2)
  const fixed = Math.abs(n).toFixed(decimals)
  const [int, frac] = fixed.split('.')
  const grouped = group(int, c.thousands_separator ?? ',', c.grouping)
  const body = frac ? grouped + (c.decimal_separator ?? '.') + frac : grouped
  return { body, negative: n < 0 }
}

export function formatMoneyWith(c, value) {
  const { body, negative } = formatNumberWith(c, value)
  const s = c.symbol ?? ''
  const withSymbol =
    c.symbol_position === 'after' ? body + s
      : c.symbol_position === 'before_space' ? `${s} ${body}`
      : c.symbol_position === 'after_space' ? `${body} ${s}`
      : s + body
  if (!negative) return withSymbol
  return c.negative_format === 'parentheses' ? `(${withSymbol})` : `-${withSymbol}`
}

// PHP date_format tokens → Intl parts. Only the formats the registry allows.
function formatDateWith(l, value) {
  if (!value) return '—'
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  const tz = l.timezone || undefined
  const p = new Intl.DateTimeFormat('en-GB', { timeZone: tz, day: '2-digit', month: '2-digit', year: 'numeric' })
    .formatToParts(d).reduce((a, x) => ({ ...a, [x.type]: x.value }), {})
  const mon = new Intl.DateTimeFormat('en-GB', { timeZone: tz, month: 'short' }).format(d)
  switch (l.date_format) {
    case 'm/d/Y': return `${p.month}/${p.day}/${p.year}`
    case 'Y-m-d': return `${p.year}-${p.month}-${p.day}`
    case 'd-m-Y': return `${p.day}-${p.month}-${p.year}`
    case 'd M Y': return `${p.day} ${mon} ${p.year}`
    case 'M d Y': return `${mon} ${p.day} ${p.year}`
    default:      return `${p.day}/${p.month}/${p.year}`   // d/m/Y
  }
}

function formatTimeWith(l, value) {
  if (!value) return '—'
  const d = value instanceof Date ? value : new Date(value)
  if (Number.isNaN(d.getTime())) return '—'
  return new Intl.DateTimeFormat('en-GB', {
    timeZone: l.timezone || undefined,
    hour: '2-digit', minute: '2-digit', hour12: String(l.time_format) !== '24',
  }).format(d)
}

export function useFormats() {
  const [fmt, setFmt] = useState(cache || FALLBACK)

  useEffect(() => {
    if (cache) return
    api.get('/settings/formats')
      .then(r => {
        const d = r.data
        if (d?.currency && d?.localization) {
          cache = { currency: { ...FALLBACK.currency, ...d.currency }, localization: { ...FALLBACK.localization, ...d.localization } }
          setFmt(cache)
        }
      })
      .catch(() => {})
  }, [])

  return {
    ...fmt,
    money:    (v) => formatMoneyWith(fmt.currency, v),
    number:   (v) => formatNumberWith(fmt.currency, v).body,
    date:     (v) => formatDateWith(fmt.localization, v),
    time:     (v) => formatTimeWith(fmt.localization, v),
    dateTime: (v) => (v ? `${formatDateWith(fmt.localization, v)} ${formatTimeWith(fmt.localization, v)}` : '—'),
  }
}

export const invalidateFormats = () => { cache = null }

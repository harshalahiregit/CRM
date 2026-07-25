/**
 * Indian-format number-to-words for cheque rendering, e.g.
 *   125340.50 → "Rupees One Lakh Twenty Five Thousand Three Hundred Forty and Fifty Paise Only"
 * Uses the lakh/crore grouping expected on Indian cheques.
 */
const ONES = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
  'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen']
const TENS = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety']

function twoDigits(n) {
  if (n < 20) return ONES[n]
  const t = Math.floor(n / 10)
  const o = n % 10
  return TENS[t] + (o ? ' ' + ONES[o] : '')
}

/** Words for an integer 0..999 (a single Indian group). */
function threeDigits(n) {
  const h = Math.floor(n / 100)
  const rest = n % 100
  let out = ''
  if (h) out += ONES[h] + ' Hundred'
  if (rest) out += (out ? ' ' : '') + twoDigits(rest)
  return out
}

/** Convert a non-negative integer to Indian-grouped words (crore/lakh/thousand). */
function integerToWords(num) {
  if (num === 0) return 'Zero'
  const crore = Math.floor(num / 10000000)
  const lakh = Math.floor((num % 10000000) / 100000)
  const thousand = Math.floor((num % 100000) / 1000)
  const hundred = num % 1000

  const parts = []
  if (crore) parts.push(threeDigits(crore) + ' Crore')
  if (lakh) parts.push(twoDigits(lakh) + ' Lakh')
  if (thousand) parts.push(twoDigits(thousand) + ' Thousand')
  if (hundred) parts.push(threeDigits(hundred))
  return parts.join(' ')
}

/**
 * Full cheque-style amount in words. Returns e.g. "Rupees One Thousand and
 * Fifty Paise Only". Paise are rounded to 2 decimals.
 */
export function amountToWords(value) {
  const n = Number(value)
  if (!isFinite(n) || n < 0) return ''
  const rupees = Math.floor(n)
  const paise = Math.round((n - rupees) * 100)

  let out = 'Rupees ' + integerToWords(rupees)
  if (paise > 0) out += ' and ' + twoDigits(paise) + ' Paise'
  out += ' Only'
  return out
}

export default amountToWords

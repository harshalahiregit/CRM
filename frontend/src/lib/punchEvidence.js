/**
 * Gather what a browser can prove about a punch: where it happened, and who
 * made it.
 *
 * The governing rule is that a punch is never blocked. A laptop may have no
 * camera, a browser may refuse location, a user may say no — none of that means
 * somebody is not at work, and refusing the punch would cost them a day over a
 * device problem that is not theirs.
 *
 * What we do instead is refuse to be silent. Every path returns a note saying
 * what happened, because "no coordinates" and "refused to share coordinates"
 * read identically in the database and are completely different conversations.
 */

/** Browser geolocation, with a deadline. Never rejects — it reports instead. */
export function getLocation({ timeout = 8000 } = {}) {
  return new Promise((resolve) => {
    if (!('geolocation' in navigator)) {
      resolve({ ok: false, reason: 'location unavailable on this device' })
      return
    }

    // Safari and Chrome both hang indefinitely when a permission prompt is
    // ignored, so the clock is ours, not theirs.
    let settled = false
    const done = (v) => { if (!settled) { settled = true; resolve(v) } }
    const timer = setTimeout(() => done({ ok: false, reason: 'location timed out' }), timeout)

    navigator.geolocation.getCurrentPosition(
      (pos) => {
        clearTimeout(timer)
        done({
          ok: true,
          latitude: pos.coords.latitude.toFixed(6),
          longitude: pos.coords.longitude.toFixed(6),
        })
      },
      (err) => {
        clearTimeout(timer)
        done({
          ok: false,
          reason: err?.code === 1 ? 'location declined' : 'location unavailable',
        })
      },
      { enableHighAccuracy: true, timeout, maximumAge: 60000 },
    )
  })
}

/** Is there a camera at all? Asked before opening a capture dialog on a desktop with none. */
export async function hasCamera() {
  try {
    if (!navigator.mediaDevices?.enumerateDevices) return false
    const devices = await navigator.mediaDevices.enumerateDevices()
    return devices.some(d => d.kind === 'videoinput')
  } catch {
    return false
  }
}

/**
 * Fold the parts into one sentence for the register.
 *
 * Positive first when everything arrived, because a verified punch should read
 * as verified rather than as the absence of complaints.
 */
export function buildNote({ location, selfie, selfieReason, requireSelfie, requireLocation }) {
  const bits = []

  if (requireLocation) {
    if (location?.ok) bits.push('location ✓')
    else bits.push(location?.reason || 'no location')
  }

  if (requireSelfie) {
    if (selfie) bits.push('selfie ✓')
    else bits.push(selfieReason || 'no selfie')
  }

  if (bits.length === 0) return ''
  const verified = (!requireLocation || location?.ok) && (!requireSelfie || !!selfie)
  return `${verified ? 'Verified' : 'Unverified'} — ${bits.join(', ')}`
}

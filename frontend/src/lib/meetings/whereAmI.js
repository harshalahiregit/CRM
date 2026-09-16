/**
 * The coordinates the browser is willing to give us, or nothing.
 *
 * Used when somebody marks attendance, so the meeting record can say where the
 * join came from. Three rules, and all three matter:
 *
 *  - It NEVER rejects. A refused permission, a device with no GPS, a page not
 *    served over HTTPS — all of these resolve to an empty object, because a
 *    person declining to share their location must still be able to join the
 *    meeting. Attendance is the point; the location is evidence beside it.
 *
 *  - It NEVER waits long. `getCurrentPosition` on a desktop without a GPS can
 *    sit for thirty seconds before failing, and the person is looking at a
 *    button that says "Recording…". Six seconds, then carry on without it.
 *
 *  - It asks for nothing better than it needs. `enableHighAccuracy` turns on the
 *    GPS radio for a street-level fix; the record wants to know which city
 *    somebody joined from, not which room, so a coarse fix from the network is
 *    both faster and less intrusive.
 *
 * The server treats a missing location as "not shared" and prints it that way,
 * which is honest. It does not look the address up: a city guessed from an IP
 * by a third party would print identically to one the person actually gave, and
 * the two are not the same claim.
 */
export function whereAmI({ timeoutMs = 6000 } = {}) {
  return new Promise(resolve => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      resolve({})
      return
    }

    let settled = false
    const done = (value) => { if (!settled) { settled = true; resolve(value) } }

    // Our own deadline as well as the browser's: some implementations ignore
    // the option entirely when a permission prompt is left open.
    const timer = setTimeout(() => done({}), timeoutMs)

    navigator.geolocation.getCurrentPosition(
      pos => {
        clearTimeout(timer)
        done({
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
        })
      },
      () => { clearTimeout(timer); done({}) },
      { enableHighAccuracy: false, timeout: timeoutMs, maximumAge: 300000 },
    )
  })
}

export default whereAmI

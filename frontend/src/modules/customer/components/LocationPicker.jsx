import { useEffect, useRef, useState } from 'react'

/**
 * Interactive map (Leaflet + OpenStreetMap tiles) used to view and pick a
 * customer's exact location.
 *
 * Leaflet is loaded lazily on first mount so it lands in its own chunk and
 * never weighs on the initial app bundle — the Map tab is rarely the first
 * thing opened. The marker is a `divIcon` (inline SVG) rather than Leaflet's
 * default PNG so there are no bundler asset-path issues and no extra requests.
 */

let leafletPromise
function loadLeaflet() {
  if (!leafletPromise) {
    leafletPromise = Promise.all([
      import('leaflet'),
      import('leaflet/dist/leaflet.css'),
    ]).then(([mod]) => mod.default ?? mod)
  }
  return leafletPromise
}

const PIN_SVG = `
<svg width="30" height="30" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"
     style="filter: drop-shadow(0 2px 4px rgba(0,0,0,.35))">
  <path d="M12 22s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11z" fill="#7C3AED"/>
  <circle cx="12" cy="11" r="2.6" fill="#fff"/>
</svg>`

// India-ish default view when nothing is pinned yet.
const DEFAULT_CENTER = [20.5937, 78.9629]
const DEFAULT_ZOOM = 4
const PIN_ZOOM = 16

export default function LocationPicker({ lat, lon, onChange, interactive = true, height = 340 }) {
  const elRef = useRef(null)
  const mapRef = useRef(null)
  const markerRef = useRef(null)
  const LRef = useRef(null)
  const onChangeRef = useRef(onChange)
  const [loading, setLoading] = useState(true)

  // Keep the latest callback without re-initialising the map.
  useEffect(() => { onChangeRef.current = onChange }, [onChange])

  const hasPin = Number.isFinite(lat) && Number.isFinite(lon)

  /* ── Init once ── */
  useEffect(() => {
    let cancelled = false

    loadLeaflet().then((L) => {
      if (cancelled || !elRef.current || mapRef.current) return
      LRef.current = L

      const map = L.map(elRef.current, {
        center: hasPin ? [lat, lon] : DEFAULT_CENTER,
        zoom: hasPin ? PIN_ZOOM : DEFAULT_ZOOM,
        scrollWheelZoom: interactive,
        zoomControl: true,
      })
      L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        maxZoom: 19,
        attribution: '&copy; OpenStreetMap contributors',
      }).addTo(map)

      if (interactive) {
        map.on('click', (e) => {
          const { lat: la, lng: ln } = e.latlng
          onChangeRef.current?.({ lat: +la.toFixed(6), lon: +ln.toFixed(6) })
        })
      }

      mapRef.current = map
      if (hasPin) placeMarker(L, map, lat, lon, interactive)
      // Tiles can render blank if the container sized after init.
      setTimeout(() => map.invalidateSize(), 60)
      setLoading(false)
    })

    return () => {
      cancelled = true
      mapRef.current?.remove()
      mapRef.current = null
      markerRef.current = null
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [interactive])

  /* ── Sync marker when coordinates change from outside (search, manual entry) ── */
  useEffect(() => {
    const L = LRef.current
    const map = mapRef.current
    if (!L || !map) return

    if (!hasPin) {
      if (markerRef.current) { markerRef.current.remove(); markerRef.current = null }
      return
    }

    if (markerRef.current) {
      markerRef.current.setLatLng([lat, lon])
    } else {
      placeMarker(L, map, lat, lon, interactive)
    }

    // Only recentre when the pin is off-screen, so manual nudges don't jump.
    if (!map.getBounds().contains([lat, lon])) {
      map.setView([lat, lon], Math.max(map.getZoom(), PIN_ZOOM))
    }
  }, [lat, lon, hasPin, interactive])

  function placeMarker(L, map, la, ln, draggable) {
    const icon = L.divIcon({ className: 'cust-map-pin', html: PIN_SVG, iconSize: [30, 30], iconAnchor: [15, 29] })
    const m = L.marker([la, ln], { icon, draggable }).addTo(map)
    if (draggable) {
      m.on('dragend', () => {
        const { lat: dLat, lng: dLon } = m.getLatLng()
        onChangeRef.current?.({ lat: +dLat.toFixed(6), lon: +dLon.toFixed(6) })
      })
    }
    markerRef.current = m
  }

  return (
    <div className="relative rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
      <div ref={elRef} style={{ height, width: '100%', background: 'var(--bg-input)' }} />
      {loading && (
        <div className="absolute inset-0 flex items-center justify-center text-xs" style={{ color: 'var(--text-muted)' }}>
          Loading map…
        </div>
      )}
      {interactive && !loading && (
        <div className="absolute bottom-2 left-2 px-2.5 py-1 rounded-lg text-[11px] font-semibold pointer-events-none"
          style={{ background: 'rgba(255,255,255,0.92)', color: '#334155', zIndex: 500 }}>
          {hasPin ? 'Drag the pin or click the map to move it' : 'Click anywhere on the map to drop a pin'}
        </div>
      )}
    </div>
  )
}

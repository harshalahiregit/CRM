import { useState } from 'react'
import { MapPin, Search, ExternalLink, X, LocateFixed, Pencil, Copy, Crosshair } from 'lucide-react'
import { customerApi } from '@/services/customerApi'
import LocationPicker from './LocationPicker'

/**
 * Customer location: an interactive map where you can search an address,
 * click/drag to drop a pin, or use your current position. The pin is stored
 * separately from the postal address so it can be the actual site entrance.
 */

const num = (v) => {
  if (v === null || v === undefined || v === '') return null
  const n = Number(v)
  return Number.isFinite(n) ? n : null
}

export default function MapTab({ client, reload, toast }) {
  const pinnedLat = num(client.latitude)
  const pinnedLon = num(client.longitude)
  const hasPin = pinnedLat !== null && pinnedLon !== null
  const postal = [client.address, client.city, client.state, client.zip, client.country].filter(Boolean).join(', ')

  const [editing, setEditing] = useState(false)
  const [address, setAddress] = useState('')
  const [lat, setLat] = useState(null)
  const [lon, setLon] = useState(null)
  const [results, setResults] = useState(null)
  const [searching, setSearching] = useState(false)
  const [saving, setSaving] = useState(false)

  const startEdit = () => {
    setAddress(client.map_address || postal || '')
    setLat(pinnedLat); setLon(pinnedLon)
    setResults(null); setEditing(true)
  }

  const search = async () => {
    const q = address.trim()
    if (q.length < 3) return toast.error('Type at least 3 characters to search')
    setSearching(true); setResults(null)
    try {
      const found = await customerApi.geocode(q)
      setResults(found)
      // Jump straight to the best match so the map is immediately useful.
      if (found.length) { setLat(found[0].lat); setLon(found[0].lon) }
    } catch (e) { toast.error(e.message) } finally { setSearching(false) }
  }

  const useMyLocation = () => {
    if (!navigator.geolocation) return toast.error('Location is not available in this browser')
    navigator.geolocation.getCurrentPosition(
      (pos) => { setLat(+pos.coords.latitude.toFixed(6)); setLon(+pos.coords.longitude.toFixed(6)) },
      () => toast.error('Could not get your location — allow location access or pick on the map'),
      { enableHighAccuracy: true, timeout: 8000 },
    )
  }

  const save = async () => {
    setSaving(true)
    try {
      await customerApi.updateLocation(client.id, {
        map_address: address.trim() || null,
        latitude: lat, longitude: lon,
      })
      toast.success('Location saved')
      setEditing(false); setResults(null)
      reload?.()
    } catch (e) { toast.error(e.message) } finally { setSaving(false) }
  }

  /* ── Editor ── */
  if (editing) {
    return (
      <div className="card-3d space-y-4">
        <div className="flex items-start justify-between gap-3">
          <div>
            <h3 className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>Set location</h3>
            <p className="text-xs mt-0.5" style={{ color: 'var(--text-muted)' }}>
              Search an address, or just click the map to drop the pin — drag it to fine-tune.
            </p>
          </div>
          <button onClick={() => setEditing(false)} className="w-8 h-8 rounded-xl flex items-center justify-center flex-shrink-0" style={{ border: '1px solid var(--border)' }}>
            <X size={14} style={{ color: 'var(--text-muted)' }} />
          </button>
        </div>

        <div className="flex gap-2">
          <input
            className="input-3d text-sm flex-1"
            placeholder="Search an address, e.g. MIDC Industrial Area, Pune"
            value={address}
            onChange={e => setAddress(e.target.value)}
            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); search() } }}
          />
          <button onClick={search} disabled={searching}
            className="px-4 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 flex-shrink-0 disabled:opacity-60"
            style={{ background: 'rgba(124,58,237,0.1)', color: 'var(--accent)', border: '1px solid var(--border-purple)' }}>
            <Search size={13} /> {searching ? 'Searching…' : 'Search'}
          </button>
          <button onClick={useMyLocation} title="Use my current location"
            className="w-9 h-9 rounded-xl flex items-center justify-center flex-shrink-0"
            style={{ border: '1px solid var(--border)', color: 'var(--accent)' }}>
            <LocateFixed size={14} />
          </button>
        </div>

        {results && results.length > 0 && (
          <div className="rounded-xl overflow-hidden" style={{ border: '1px solid var(--border)' }}>
            {results.map((r, i) => (
              <button key={i} onClick={() => { setLat(r.lat); setLon(r.lon); setAddress(r.label); setResults(null) }}
                className="w-full text-left px-3 py-2.5 text-xs flex items-start gap-2 hover:bg-[rgba(124,58,237,0.06)] transition-colors"
                style={{ borderBottom: i < results.length - 1 ? '1px solid var(--border)' : 'none', color: 'var(--text-h)' }}>
                <MapPin size={13} className="flex-shrink-0 mt-0.5" style={{ color: 'var(--accent)' }} />
                <span>{r.label}</span>
              </button>
            ))}
          </div>
        )}
        {results && !results.length && (
          <p className="text-xs" style={{ color: '#f59e0b' }}>No matches — click the map to place the pin manually.</p>
        )}

        <LocationPicker lat={lat} lon={lon} onChange={({ lat: la, lon: ln }) => { setLat(la); setLon(ln) }} height={380} />

        <div className="flex flex-wrap items-center justify-between gap-2">
          <span className="text-[11px]" style={{ color: 'var(--text-muted)' }}>
            {lat !== null && lon !== null
              ? <>Pinned at <b style={{ color: 'var(--text-h)' }}>{lat.toFixed(6)}, {lon.toFixed(6)}</b> · <button onClick={() => { setLat(null); setLon(null) }} className="font-bold" style={{ color: '#f87171' }}>Clear pin</button></>
              : 'No pin yet — click the map to place one.'}
          </span>
          <div className="flex gap-2">
            <button onClick={() => setEditing(false)} className="px-4 py-2 rounded-xl text-xs font-bold" style={{ background: 'var(--bg-input)', color: 'var(--text-muted)', border: '1px solid var(--border)' }}>Cancel</button>
            <button onClick={save} disabled={saving} className="px-5 py-2 rounded-xl text-xs font-bold text-white disabled:opacity-60" style={{ background: 'linear-gradient(135deg,#7C3AED,#5b21b6)' }}>
              {saving ? 'Saving…' : 'Save Location'}
            </button>
          </div>
        </div>
      </div>
    )
  }

  /* ── Read-only view ── */
  const copyCoords = async () => {
    try {
      await navigator.clipboard.writeText(`${pinnedLat}, ${pinnedLon}`)
      toast.success('Coordinates copied')
    } catch { toast.error('Could not copy') }
  }

  if (!hasPin) {
    return (
      <div className="card-3d text-center" style={{ padding: '48px 24px' }}>
        <div className="w-14 h-14 rounded-2xl mx-auto mb-3 flex items-center justify-center" style={{ background: 'rgba(124,58,237,0.1)' }}>
          <MapPin size={24} style={{ color: 'var(--accent)' }} />
        </div>
        <p className="font-bold text-sm" style={{ color: 'var(--text-h)' }}>No location pinned yet</p>
        <p className="text-xs mt-1 mb-5 max-w-sm mx-auto" style={{ color: 'var(--text-muted)' }}>
          {postal
            ? 'Pin the exact site on the map — a postal address alone can be off by whole streets.'
            : 'Pin this customer on the map so the team can find the exact site.'}
        </p>
        <button onClick={startEdit} className="px-5 py-2.5 rounded-xl text-xs font-bold text-white inline-flex items-center gap-2" style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 6px 18px rgba(124,58,237,0.35)' }}>
          <MapPin size={14} /> Add location
        </button>
      </div>
    )
  }

  return (
    <div className="card-3d overflow-hidden" style={{ padding: 0 }}>
      {/* Header */}
      <div className="flex items-center justify-between gap-3 px-5 py-4">
        <div className="flex items-center gap-3 min-w-0">
          <div className="w-10 h-10 rounded-2xl flex items-center justify-center flex-shrink-0" style={{ background: 'rgba(124,58,237,0.12)' }}>
            <MapPin size={18} style={{ color: 'var(--accent)' }} />
          </div>
          <div className="min-w-0">
            <p className="font-bold text-sm truncate" style={{ color: 'var(--text-h)' }}>
              {client.map_address || postal || 'Pinned location'}
            </p>
            <p className="text-[11px] mt-0.5" style={{ color: 'var(--text-muted)' }}>Exact site location</p>
          </div>
        </div>
        <button onClick={startEdit}
          className="px-3.5 py-2 rounded-xl text-xs font-bold flex items-center gap-1.5 flex-shrink-0 transition-colors hover:bg-[rgba(124,58,237,0.12)]"
          style={{ background: 'rgba(124,58,237,0.08)', color: 'var(--accent)', border: '1px solid var(--border-purple)' }}>
          <Pencil size={12} /> Edit
        </button>
      </div>

      {/* Map */}
      <div className="px-5">
        <LocationPicker lat={pinnedLat} lon={pinnedLon} interactive={false} height={400} />
      </div>

      {/* Footer */}
      <div className="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
        <button onClick={copyCoords} title="Copy coordinates"
          className="flex items-center gap-2 px-3 py-1.5 rounded-lg text-[11px] font-semibold transition-colors hover:bg-[rgba(124,58,237,0.08)]"
          style={{ background: 'var(--bg-input)', border: '1px solid var(--border)', color: 'var(--text-muted)', fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace' }}>
          <Crosshair size={12} style={{ color: 'var(--accent)' }} />
          {pinnedLat.toFixed(6)}, {pinnedLon.toFixed(6)}
          <Copy size={11} />
        </button>

        <a href={`https://www.google.com/maps/search/?api=1&query=${pinnedLat},${pinnedLon}`} target="_blank" rel="noreferrer"
          className="px-4 py-2 rounded-xl text-xs font-bold text-white flex items-center gap-1.5"
          style={{ background: 'linear-gradient(135deg,#9f67ff,#7C3AED,#5b21b6)', boxShadow: '0 4px 14px rgba(124,58,237,0.3)', textDecoration: 'none' }}>
          Get Directions <ExternalLink size={12} />
        </a>
      </div>
    </div>
  )
}

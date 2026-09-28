import { useState, useEffect, useCallback } from 'react'
import { View, Text, TouchableOpacity, FlatList, RefreshControl, ActivityIndicator } from 'react-native'
import { theme } from '../theme'
import { api } from '../api'
import { signOut } from '../storage'
import { statusLabel, statusColor } from '../status'
import { useLayout } from '../responsive'

export default function TripsScreen({ user, onOpen, onSignOut }) {
  const { topInset, f, gutter, maxContent } = useLayout()
  const [trips, setTrips] = useState([])
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    setErr('')
    try {
      const result = await api.trips({ open: 1, per_page: 50 })
      // The list may arrive as a paginator ({ data: [...] }) or a bare array.
      const rows = Array.isArray(result) ? result : (result?.data ?? [])
      setTrips(rows)
    } catch (e) {
      setErr(e?.message || 'Could not load your trips.')
    } finally {
      setLoading(false)
      setRefreshing(false)
    }
  }, [])

  useEffect(() => { load() }, [load])

  const onRefresh = () => { setRefreshing(true); load() }

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <View style={{ paddingTop: f(14), paddingHorizontal: gutter, paddingBottom: 14, flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between' }}>
        <View style={{ flex: 1, paddingRight: 12 }}>
          <Text style={{ color: theme.text, fontSize: f(24), fontWeight: '900' }}>My trips</Text>
          {user?.name ? <Text style={{ color: theme.textMuted, fontSize: f(14), marginTop: 2 }} numberOfLines={1}>{user.name}</Text> : null}
        </View>
        <TouchableOpacity onPress={async () => { await signOut(); onSignOut() }} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
          <Text style={{ color: theme.textMuted, fontSize: f(14), fontWeight: '700' }}>Sign out</Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator color={theme.accent} /></View>
      ) : err ? (
        <Centered><Text style={{ color: theme.danger, fontSize: f(15), textAlign: 'center' }}>{err}</Text>
          <TouchableOpacity onPress={load} style={{ marginTop: 14 }}><Text style={{ color: theme.accent, fontWeight: '700', fontSize: f(15) }}>Try again</Text></TouchableOpacity>
        </Centered>
      ) : trips.length === 0 ? (
        <Centered><Text style={{ color: theme.textMuted, fontSize: f(15) }}>No trips right now.</Text></Centered>
      ) : (
        <FlatList
          data={trips}
          keyExtractor={(t) => String(t.id)}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: 4, paddingBottom: 40, width: '100%', maxWidth: maxContent, alignSelf: 'center' }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={theme.accent} />}
          renderItem={({ item }) => <TripCard trip={item} onPress={() => onOpen(item)} f={f} />}
        />
      )}
    </View>
  )
}

function TripCard({ trip, onPress, f }) {
  return (
    <TouchableOpacity onPress={onPress} activeOpacity={0.8}
      style={{ backgroundColor: theme.card, borderColor: theme.border, borderWidth: 1, borderRadius: 16, padding: 16, marginBottom: 12 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <Text style={{ color: theme.text, fontSize: f(17), fontWeight: '800', flex: 1, paddingRight: 10 }} numberOfLines={1}>{trip.trip_number || `Trip #${trip.id}`}</Text>
        <StatusPill status={trip.status} f={f} />
      </View>
      {trip.route ? <Text style={{ color: theme.textMuted, fontSize: f(14), marginTop: 6 }}>{trip.route}</Text> : null}
      {trip.dispatch_destination ? <Text style={{ color: theme.textMuted, fontSize: f(13), marginTop: 3 }}>To: {trip.dispatch_destination}</Text> : null}
    </TouchableOpacity>
  )
}

export function StatusPill({ status, f }) {
  const c = statusColor(status)
  const size = f ? f(12.5) : 12.5
  return (
    <View style={{ backgroundColor: c + '22', borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 }}>
      <Text style={{ color: c, fontSize: size, fontWeight: '800' }}>{statusLabel(status)}</Text>
    </View>
  )
}

function Centered({ children }) {
  return <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>{children}</View>
}

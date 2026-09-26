import { useState, useEffect, useCallback } from 'react'
import { View, Text, TouchableOpacity, FlatList, RefreshControl, ActivityIndicator } from 'react-native'
import { theme } from '../theme'
import { api } from '../api'
import { signOut } from '../storage'
import { statusLabel, statusColor } from '../status'

export default function TripsScreen({ user, onOpen, onSignOut }) {
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
    <View style={{ flex: 1, backgroundColor: theme.bg }}>
      <View style={{ paddingTop: 56, paddingHorizontal: 20, paddingBottom: 14, flexDirection: 'row', alignItems: 'flex-end', justifyContent: 'space-between' }}>
        <View>
          <Text style={{ color: theme.text, fontSize: 24, fontWeight: '900' }}>My trips</Text>
          {user?.name ? <Text style={{ color: theme.textMuted, fontSize: 14, marginTop: 2 }}>{user.name}</Text> : null}
        </View>
        <TouchableOpacity onPress={async () => { await signOut(); onSignOut() }}>
          <Text style={{ color: theme.textMuted, fontSize: 14, fontWeight: '700' }}>Sign out</Text>
        </TouchableOpacity>
      </View>

      {loading ? (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}><ActivityIndicator color={theme.accent} /></View>
      ) : err ? (
        <Centered><Text style={{ color: theme.danger, fontSize: 15, textAlign: 'center' }}>{err}</Text>
          <TouchableOpacity onPress={load} style={{ marginTop: 14 }}><Text style={{ color: theme.accent, fontWeight: '700' }}>Try again</Text></TouchableOpacity>
        </Centered>
      ) : trips.length === 0 ? (
        <Centered><Text style={{ color: theme.textMuted, fontSize: 15 }}>No trips right now.</Text></Centered>
      ) : (
        <FlatList
          data={trips}
          keyExtractor={(t) => String(t.id)}
          contentContainerStyle={{ padding: 16, paddingBottom: 40 }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={theme.accent} />}
          renderItem={({ item }) => <TripCard trip={item} onPress={() => onOpen(item)} />}
        />
      )}
    </View>
  )
}

function TripCard({ trip, onPress }) {
  return (
    <TouchableOpacity onPress={onPress} activeOpacity={0.8}
      style={{ backgroundColor: theme.card, borderColor: theme.border, borderWidth: 1, borderRadius: 16, padding: 16, marginBottom: 12 }}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
        <Text style={{ color: theme.text, fontSize: 17, fontWeight: '800' }}>{trip.trip_number || `Trip #${trip.id}`}</Text>
        <StatusPill status={trip.status} />
      </View>
      {trip.route ? <Text style={{ color: theme.textMuted, fontSize: 14, marginTop: 6 }}>{trip.route}</Text> : null}
      {trip.dispatch_destination ? <Text style={{ color: theme.textMuted, fontSize: 13, marginTop: 3 }}>To: {trip.dispatch_destination}</Text> : null}
    </TouchableOpacity>
  )
}

export function StatusPill({ status }) {
  const c = statusColor(status)
  return (
    <View style={{ backgroundColor: c + '22', borderRadius: 8, paddingHorizontal: 10, paddingVertical: 4 }}>
      <Text style={{ color: c, fontSize: 12.5, fontWeight: '800' }}>{statusLabel(status)}</Text>
    </View>
  )
}

function Centered({ children }) {
  return <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: 24 }}>{children}</View>
}

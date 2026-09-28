import { useState, useEffect, useCallback } from 'react'
import { View, Text, Pressable, FlatList, RefreshControl } from 'react-native'
import { theme, radius } from '../theme'
import { api } from '../api'
import { signOut } from '../storage'
import { statusLabel, statusColor } from '../status'
import { useLayout } from '../responsive'
import { AppBar, Card, Loading, EmptyState, Button } from '../ui'

export default function TripsScreen({ user, onOpen, onProfile, onSignOut }) {
  const { f, gutter, maxContent, topInset, bottomInset } = useLayout()
  const [trips, setTrips] = useState([])
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    setErr('')
    try {
      const result = await api.trips({ open: 1, per_page: 50 })
      const rows = Array.isArray(result) ? result : (result?.data ?? [])
      setTrips(rows)
    } catch (e) {
      setErr(e?.message || 'Could not load your trips.')
    } finally {
      setLoading(false); setRefreshing(false)
    }
  }, [])

  useEffect(() => { load() }, [load])
  const onRefresh = () => { setRefreshing(true); load() }

  const greeting = new Date().getHours() < 12 ? 'Good morning' : new Date().getHours() < 17 ? 'Good afternoon' : 'Good evening'

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <AppBar
        title="My trips"
        subtitle={user?.name ? `${greeting}, ${user.name.split(' ')[0]}` : greeting}
        right={<View style={{ flexDirection: 'row', alignItems: 'center', gap: f(14) }}>
          <Pressable onPress={onProfile} hitSlop={12} style={({ pressed }) => ({ opacity: pressed ? 0.6 : 1 })}>
            <View style={{ width: f(34), height: f(34), borderRadius: f(17), backgroundColor: theme.primaryTint, alignItems: 'center', justifyContent: 'center' }}>
              <Text style={{ color: theme.primary, fontSize: f(14), fontWeight: '900' }}>{(user?.name || '?').trim().charAt(0).toUpperCase()}</Text>
            </View>
          </Pressable>
          <Pressable onPress={async () => { await signOut(); onSignOut() }} hitSlop={12}
            style={({ pressed }) => ({ opacity: pressed ? 0.6 : 1 })}>
            <Text style={{ color: theme.textMuted, fontSize: f(13.5), fontWeight: '700' }}>Sign out</Text>
          </Pressable>
        </View>}
      />

      {loading ? (
        <Loading label="Loading your trips…" />
      ) : err ? (
        <EmptyState icon="⚠️" title="Couldn't load your trips" subtitle={err}
          action={<Button title="Try again" variant="secondary" onPress={load} />} />
      ) : trips.length === 0 ? (
        <EmptyState icon="🛣️" title="No active trips" subtitle="When the office assigns you a trip, it shows up here. Pull down to refresh." />
      ) : (
        <FlatList
          data={trips}
          keyExtractor={(t) => String(t.id)}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: f(4), paddingBottom: f(28) + bottomInset, width: '100%', maxWidth: maxContent, alignSelf: 'center' }}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={theme.primary} colors={[theme.primary]} />}
          ItemSeparatorComponent={() => <View style={{ height: f(12) }} />}
          renderItem={({ item }) => <TripCard trip={item} onPress={() => onOpen(item)} f={f} />}
        />
      )}
    </View>
  )
}

function TripCard({ trip, onPress, f }) {
  return (
    <Card onPress={onPress}>
      <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: f(10) }}>
        <Text style={{ color: theme.text, fontSize: f(18), fontWeight: '800', flex: 1 }} numberOfLines={1}>{trip.trip_number || `Trip #${trip.id}`}</Text>
        <StatusPill status={trip.status} f={f} />
      </View>
      {trip.route ? (
        <View style={{ flexDirection: 'row', alignItems: 'center', gap: f(8), marginTop: f(10) }}>
          <Text style={{ fontSize: f(13) }}>📍</Text>
          <Text style={{ color: theme.textMuted, fontSize: f(14), flex: 1 }} numberOfLines={1}>{trip.route}</Text>
        </View>
      ) : null}
      {trip.dispatch_destination ? (
        <Text style={{ color: theme.textFaint, fontSize: f(13), marginTop: f(4) }} numberOfLines={1}>To: {trip.dispatch_destination}</Text>
      ) : null}
      <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'flex-end', marginTop: f(12) }}>
        <Text style={{ color: theme.primary, fontSize: f(13.5), fontWeight: '800' }}>Open  ›</Text>
      </View>
    </Card>
  )
}

export function StatusPill({ status, f: ff }) {
  const c = statusColor(status)
  const size = ff ? ff(12) : 12
  return (
    <View style={{ backgroundColor: c + '22', borderRadius: radius.pill, paddingHorizontal: 11, paddingVertical: 5 }}>
      <Text style={{ color: c, fontSize: size, fontWeight: '800' }}>{statusLabel(status)}</Text>
    </View>
  )
}

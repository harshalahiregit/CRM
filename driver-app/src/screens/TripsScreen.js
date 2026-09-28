import { useState, useEffect, useCallback } from 'react'
import { View, Text, Pressable, ScrollView, RefreshControl } from 'react-native'
import { theme, radius } from '../theme'
import { api } from '../api'
import { signOut } from '../storage'
import { statusLabel, statusColor } from '../status'
import { useLayout } from '../responsive'
import { AppBar, Card, EmptyState, Button, Pill, Skeleton } from '../ui'

/**
 * "Driver Today" — the home dashboard. Readiness first (can I drive?), then the
 * active trip, then everything else. The readiness card is best-effort: if the
 * self-service endpoint is not reachable (e.g. not deployed on this server yet)
 * the home screen still works, it just hides that card.
 */
export default function TripsScreen({ user, onOpen, onProfile, onSignOut }) {
  const { f, gutter, maxContent, topInset, bottomInset } = useLayout()
  const [trips, setTrips] = useState([])
  const [me, setMe] = useState(null)
  const [loading, setLoading] = useState(true)
  const [refreshing, setRefreshing] = useState(false)
  const [err, setErr] = useState('')

  const load = useCallback(async () => {
    setErr('')
    try {
      const [tripResult, meResult] = await Promise.all([
        api.trips({ open: 1, per_page: 50 }),
        api.me().catch(() => null),   // best-effort — never fail the home on this
      ])
      const rows = Array.isArray(tripResult) ? tripResult : (tripResult?.data ?? [])
      setTrips(rows)
      setMe(meResult)
    } catch (e) {
      setErr(e?.message || 'Could not load your day.')
    } finally {
      setLoading(false); setRefreshing(false)
    }
  }, [])

  useEffect(() => { load() }, [load])
  const onRefresh = () => { setRefreshing(true); load() }

  const hour = new Date().getHours()
  const greeting = hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening'
  const active = trips[0]
  const rest = trips.slice(1)

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <AppBar
        title="Today"
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
        <View style={{ paddingHorizontal: gutter, paddingTop: f(8), width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>
          <Skeleton height={f(90)} radius={16} />
          <Skeleton height={f(14)} width="35%" style={{ marginTop: f(24) }} />
          <Skeleton height={f(150)} radius={16} style={{ marginTop: f(14) }} />
          <View style={{ flexDirection: 'row', gap: f(10), marginTop: f(20) }}>
            <Skeleton height={f(78)} radius={16} style={{ flex: 1 }} />
            <Skeleton height={f(78)} radius={16} style={{ flex: 1 }} />
          </View>
        </View>
      ) : err ? (
        <EmptyState icon="⚠️" title="Couldn't load" subtitle={err}
          action={<Button title="Try again" variant="secondary" onPress={() => { setLoading(true); load() }} />} />
      ) : (
        <ScrollView showsVerticalScrollIndicator={false}
          refreshControl={<RefreshControl refreshing={refreshing} onRefresh={onRefresh} tintColor={theme.primary} colors={[theme.primary]} />}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: f(4), paddingBottom: f(28) + bottomInset, width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>

          {/* Readiness (best-effort) */}
          {me?.eligibility ? <ReadinessCard elig={me.eligibility} onProfile={onProfile} f={f} /> : null}

          {/* Active trip */}
          <Text style={{ color: theme.textFaint, fontSize: f(12), fontWeight: '800', letterSpacing: 0.7, textTransform: 'uppercase', marginTop: me?.eligibility ? f(20) : 0, marginBottom: f(12) }}>
            {active ? 'Your trip' : 'Trips'}
          </Text>

          {active ? (
            <Card onPress={() => onOpen(active)}>
              <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: f(10) }}>
                <Text style={{ color: theme.text, fontSize: f(19), fontWeight: '900', flex: 1 }} numberOfLines={1}>{active.trip_number || `Trip #${active.id}`}</Text>
                <StatusPill status={active.status} f={f} />
              </View>
              {active.route ? (
                <View style={{ flexDirection: 'row', alignItems: 'center', gap: f(8), marginTop: f(10) }}>
                  <Text style={{ fontSize: f(13) }}>📍</Text>
                  <Text style={{ color: theme.textMuted, fontSize: f(14), flex: 1 }} numberOfLines={2}>{active.route}</Text>
                </View>
              ) : null}
              {active.dispatch_destination ? <Text style={{ color: theme.textFaint, fontSize: f(13), marginTop: f(4) }} numberOfLines={1}>To: {active.dispatch_destination}</Text> : null}
              <View style={{ marginTop: f(14) }}><Button title="Open trip" onPress={() => onOpen(active)} /></View>
            </Card>
          ) : (
            <Card>
              <View style={{ alignItems: 'center', paddingVertical: f(12) }}>
                <Text style={{ fontSize: f(34), marginBottom: f(8) }}>🛣️</Text>
                <Text style={{ color: theme.text, fontSize: f(15.5), fontWeight: '800' }}>No active trip</Text>
                <Text style={{ color: theme.textMuted, fontSize: f(13.5), textAlign: 'center', marginTop: f(4) }}>When the office assigns you a trip, it appears here. Pull to refresh.</Text>
              </View>
            </Card>
          )}

          {/* Quick actions */}
          <Text style={{ color: theme.textFaint, fontSize: f(12), fontWeight: '800', letterSpacing: 0.7, textTransform: 'uppercase', marginTop: f(20), marginBottom: f(12) }}>Quick actions</Text>
          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: f(10) }}>
            <QuickAction icon="👤" label="My profile" onPress={onProfile} f={f} />
            <QuickAction icon="📄" label="Documents" onPress={onProfile} f={f} />
          </View>

          {/* Other trips */}
          {rest.length > 0 ? (
            <>
              <Text style={{ color: theme.textFaint, fontSize: f(12), fontWeight: '800', letterSpacing: 0.7, textTransform: 'uppercase', marginTop: f(20), marginBottom: f(12) }}>More trips</Text>
              <View style={{ gap: f(12) }}>
                {rest.map((t) => (
                  <Card key={t.id} onPress={() => onOpen(t)}>
                    <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: f(10) }}>
                      <Text style={{ color: theme.text, fontSize: f(16), fontWeight: '800', flex: 1 }} numberOfLines={1}>{t.trip_number || `Trip #${t.id}`}</Text>
                      <StatusPill status={t.status} f={f} />
                    </View>
                    {t.route ? <Text style={{ color: theme.textMuted, fontSize: f(13.5), marginTop: f(6) }} numberOfLines={1}>{t.route}</Text> : null}
                  </Card>
                ))}
              </View>
            </>
          ) : null}
        </ScrollView>
      )}
    </View>
  )
}

function ReadinessCard({ elig, onProfile, f }) {
  const ok = elig.eligible
  return (
    <View style={{ backgroundColor: ok ? theme.successTint : theme.warningTint, borderRadius: radius.lg, padding: f(16) }}>
      <View style={{ flexDirection: 'row', gap: f(12), alignItems: 'flex-start' }}>
        <Text style={{ fontSize: f(22) }}>{ok ? '✅' : '⏳'}</Text>
        <View style={{ flex: 1 }}>
          <Text style={{ color: ok ? theme.success : theme.warning, fontSize: f(15.5), fontWeight: '900' }}>
            {ok ? 'Cleared to drive' : 'Not cleared yet'}
          </Text>
          <Text style={{ color: theme.textMuted, fontSize: f(13.5), marginTop: f(4), lineHeight: f(19) }}>{elig.message}</Text>
          {!ok && elig.blocking?.length ? (
            <View style={{ marginTop: f(8) }}>
              {elig.blocking.map((b, i) => (
                <Text key={i} style={{ color: theme.text, fontSize: f(13) }}>• {b.label}{b.awaiting ? '  (waiting for the office)' : ''}</Text>
              ))}
              <Pressable onPress={onProfile} style={({ pressed }) => ({ marginTop: f(10), opacity: pressed ? 0.6 : 1 })}>
                <Text style={{ color: theme.primary, fontSize: f(13.5), fontWeight: '800' }}>Go to my documents  ›</Text>
              </Pressable>
            </View>
          ) : null}
        </View>
      </View>
    </View>
  )
}

function QuickAction({ icon, label, onPress, f }) {
  return (
    <Pressable onPress={onPress} style={({ pressed }) => ({
      flexGrow: 1, minWidth: '46%', backgroundColor: theme.surface, borderColor: theme.border, borderWidth: 1,
      borderRadius: radius.lg, padding: f(16), opacity: pressed ? 0.85 : 1, transform: [{ scale: pressed ? 0.99 : 1 }],
    })}>
      <Text style={{ fontSize: f(22) }}>{icon}</Text>
      <Text style={{ color: theme.text, fontSize: f(14), fontWeight: '700', marginTop: f(8) }}>{label}</Text>
    </Pressable>
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

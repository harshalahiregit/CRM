import { useState, useEffect, useCallback } from 'react'
import { View, Text, TouchableOpacity, ScrollView, ActivityIndicator, Alert, Image } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import { theme } from '../theme'
import { api } from '../api'
import { StatusPill } from './TripsScreen'
import { JOURNEY, journeyIndex } from '../status'

export default function TripDetailScreen({ trip: initial, onBack }) {
  const [trip, setTrip] = useState(initial)
  const [docs, setDocs] = useState([])
  const [loading, setLoading] = useState(true)
  const [uploading, setUploading] = useState(false)

  const load = useCallback(async () => {
    try {
      const [t, d] = await Promise.all([
        api.trip(initial.id).catch(() => initial),
        api.tripDocuments(initial.id).catch(() => ({})),
      ])
      setTrip(t || initial)
      const rows = Array.isArray(d) ? d : (d?.documents ?? d?.data ?? [])
      setDocs(rows)
    } finally {
      setLoading(false)
    }
  }, [initial])

  useEffect(() => { load() }, [load])

  const capturePod = async () => {
    const perm = await ImagePicker.requestCameraPermissionsAsync()
    if (!perm.granted) return Alert.alert('Camera needed', 'Allow the camera to photograph the signed delivery sheet.')

    const shot = await ImagePicker.launchCameraAsync({ quality: 0.6 })
    if (shot.canceled || !shot.assets?.length) return

    const asset = shot.assets[0]
    setUploading(true)
    try {
      const form = new FormData()
      form.append('document_type', 'pod')
      form.append('file', { uri: asset.uri, name: `pod-${Date.now()}.jpg`, type: 'image/jpeg' })
      await api.uploadPod(trip.id, form)
      Alert.alert('Filed', 'The proof of delivery is on the trip. The office will verify it.')
      await load()
    } catch (e) {
      Alert.alert('Could not file it', e?.message || 'Try again in a moment.')
    } finally {
      setUploading(false)
    }
  }

  const step = journeyIndex(trip.status)

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg }}>
      <View style={{ paddingTop: 56, paddingHorizontal: 20, paddingBottom: 12, flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        <TouchableOpacity onPress={onBack}><Text style={{ color: theme.accent, fontSize: 16, fontWeight: '700' }}>‹ Back</Text></TouchableOpacity>
      </View>

      <ScrollView contentContainerStyle={{ padding: 20, paddingBottom: 48 }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
          <Text style={{ color: theme.text, fontSize: 24, fontWeight: '900' }}>{trip.trip_number || `Trip #${trip.id}`}</Text>
          <StatusPill status={trip.status} />
        </View>
        {trip.route ? <Text style={{ color: theme.textMuted, fontSize: 15, marginTop: 6 }}>{trip.route}</Text> : null}
        {trip.dispatch_destination ? <Row label="Destination" value={trip.dispatch_destination} /> : null}
        {trip.planned_arrival_at ? <Row label="Planned arrival" value={fmt(trip.planned_arrival_at)} /> : null}

        {/* Journey — read-only for now. Becomes tappable once Dev 1 adds
            driver-reportable departed/arrived/delivered events. */}
        <Section title="Journey">
          {JOURNEY.map((s, i) => (
            <View key={s.key} style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 10 }}>
              <View style={{ width: 22, height: 22, borderRadius: 11, alignItems: 'center', justifyContent: 'center',
                backgroundColor: i <= step ? theme.accent : theme.input, borderWidth: i <= step ? 0 : 1, borderColor: theme.border }}>
                {i <= step ? <Text style={{ color: '#fff', fontSize: 12, fontWeight: '900' }}>✓</Text> : null}
              </View>
              <Text style={{ color: i <= step ? theme.text : theme.textMuted, fontSize: 15, fontWeight: i === step ? '800' : '500' }}>{s.label}</Text>
            </View>
          ))}
          <Text style={{ color: theme.textMuted, fontSize: 12.5, marginTop: 4 }}>
            Progress is set by the office for now. Driver-tap updates are coming.
          </Text>
        </Section>

        {/* POD — the one the driver owns today. */}
        <Section title="Proof of delivery">
          {loading ? <ActivityIndicator color={theme.accent} /> : (
            <>
              {docs.filter((d) => d.document_type === 'pod').length === 0 ? (
                <Text style={{ color: theme.textMuted, fontSize: 14, marginBottom: 12 }}>No proof of delivery filed yet.</Text>
              ) : (
                docs.filter((d) => d.document_type === 'pod').map((d) => (
                  <View key={d.id} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 8 }}>
                    <Text style={{ color: theme.success, fontSize: 14 }}>✓</Text>
                    <Text style={{ color: theme.text, fontSize: 14, flex: 1 }} numberOfLines={1}>{d.file_name || 'POD'}</Text>
                    <Text style={{ color: theme.textMuted, fontSize: 12.5 }}>{d.verification_status === 'VERIFIED' ? 'verified' : 'to verify'}</Text>
                  </View>
                ))
              )}

              <TouchableOpacity onPress={capturePod} disabled={uploading} activeOpacity={0.85}
                style={{ backgroundColor: theme.accent, borderRadius: 14, paddingVertical: 15, alignItems: 'center', marginTop: 8, opacity: uploading ? 0.6 : 1 }}>
                {uploading ? <ActivityIndicator color="#fff" /> : <Text style={{ color: '#fff', fontSize: 16, fontWeight: '800' }}>📷  Capture POD</Text>}
              </TouchableOpacity>
            </>
          )}
        </Section>
      </ScrollView>
    </View>
  )
}

function Section({ title, children }) {
  return (
    <View style={{ backgroundColor: theme.card, borderColor: theme.border, borderWidth: 1, borderRadius: 16, padding: 16, marginTop: 18 }}>
      <Text style={{ color: theme.textMuted, fontSize: 13, fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 14 }}>{title}</Text>
      {children}
    </View>
  )
}
function Row({ label, value }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 10 }}>
      <Text style={{ color: theme.textMuted, fontSize: 14 }}>{label}</Text>
      <Text style={{ color: theme.text, fontSize: 14, fontWeight: '600' }}>{value}</Text>
    </View>
  )
}
function fmt(v) {
  try { return new Date(v).toLocaleString() } catch { return String(v) }
}

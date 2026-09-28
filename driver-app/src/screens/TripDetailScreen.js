import { useState, useEffect, useCallback } from 'react'
import { View, Text, ScrollView, ActivityIndicator, Alert } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import { theme, radius } from '../theme'
import { api } from '../api'
import { StatusPill } from './TripsScreen'
import { JOURNEY, journeyIndex } from '../status'
import { useLayout } from '../responsive'
import { AppBar, Card, SectionLabel, Button, Pill } from '../ui'

export default function TripDetailScreen({ trip: initial, onBack }) {
  const { f, gutter, maxContent, topInset, bottomInset } = useLayout()
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
      setDocs(Array.isArray(d) ? d : (d?.documents ?? d?.data ?? []))
    } finally { setLoading(false) }
  }, [initial])

  useEffect(() => { load() }, [load])

  const addPod = async (fromCamera) => {
    let shot
    try {
      if (fromCamera) {
        const perm = await ImagePicker.requestCameraPermissionsAsync()
        if (!perm.granted) return Alert.alert('Camera needed', 'Allow the camera in settings, or use "From gallery" instead.')
        shot = await ImagePicker.launchCameraAsync({ quality: 0.5 })
      } else {
        const perm = await ImagePicker.requestMediaLibraryPermissionsAsync()
        if (!perm.granted) return Alert.alert('Gallery needed', 'Allow photo access in settings to pick the delivery sheet.')
        shot = await ImagePicker.launchImageLibraryAsync({ quality: 0.5 })
      }
    } catch (e) {
      return Alert.alert('Could not open the ' + (fromCamera ? 'camera' : 'gallery'), String(e?.message || e))
    }
    if (!shot || shot.canceled || !shot.assets?.length) return

    const asset = shot.assets[0]
    setUploading(true)
    try {
      const form = new FormData()
      form.append('document_type', 'pod')
      form.append('file', { uri: asset.uri, name: asset.fileName || `pod-${Date.now()}.jpg`, type: asset.mimeType || 'image/jpeg' })
      await api.uploadPod(trip.id, form)
      Alert.alert('Filed', 'The proof of delivery is on the trip. The office will verify it.')
      await load()
    } catch (e) {
      const msg = e?.status === 0
        ? 'Upload failed — could not reach the server. Check your connection and try again.'
        : (e?.message || 'The server refused the file. Try again.')
      Alert.alert('Could not file the POD', msg)
    } finally { setUploading(false) }
  }

  const step = journeyIndex(trip.status)
  const pods = docs.filter((d) => d.document_type === 'pod')

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <AppBar title={trip.trip_number || `Trip #${trip.id}`} onBack={onBack}
        right={<StatusPill status={trip.status} f={f} />} />

      <ScrollView showsVerticalScrollIndicator={false}
        contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: f(4), paddingBottom: f(28) + bottomInset, width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>

        {/* Summary */}
        <Card>
          {trip.route ? (
            <View style={{ flexDirection: 'row', alignItems: 'center', gap: f(8) }}>
              <Text style={{ fontSize: f(15) }}>📍</Text>
              <Text style={{ color: theme.text, fontSize: f(15.5), fontWeight: '600', flex: 1 }}>{trip.route}</Text>
            </View>
          ) : null}
          {trip.dispatch_destination ? <Row label="Destination" value={trip.dispatch_destination} f={f} /> : null}
          {trip.planned_arrival_at ? <Row label="Planned arrival" value={fmt(trip.planned_arrival_at)} f={f} /> : null}
          {trip.vehicle_registration ? <Row label="Vehicle" value={trip.vehicle_registration} f={f} /> : null}
        </Card>

        {/* Journey */}
        <View style={{ marginTop: f(18) }}>
          <SectionLabel>Journey</SectionLabel>
          <Card>
            {JOURNEY.map((s, i) => {
              const done = i < step, current = i === step
              return (
                <View key={s.key} style={{ flexDirection: 'row', alignItems: 'center', gap: f(12), paddingVertical: f(4) }}>
                  <View style={{ alignItems: 'center' }}>
                    <View style={{ width: f(24), height: f(24), borderRadius: f(12), alignItems: 'center', justifyContent: 'center',
                      backgroundColor: done || current ? theme.primary : theme.input, borderWidth: done || current ? 0 : 1, borderColor: theme.border }}>
                      {done ? <Text style={{ color: theme.onPrimary, fontSize: f(12), fontWeight: '900' }}>✓</Text>
                        : current ? <View style={{ width: f(8), height: f(8), borderRadius: f(4), backgroundColor: theme.onPrimary }} /> : null}
                    </View>
                    {i < JOURNEY.length - 1 ? <View style={{ width: 2, height: f(16), backgroundColor: done ? theme.primary : theme.border, marginVertical: 2 }} /> : null}
                  </View>
                  <Text style={{ color: done || current ? theme.text : theme.textFaint, fontSize: f(15), fontWeight: current ? '800' : '500', flex: 1, marginBottom: f(14) }}>{s.label}</Text>
                </View>
              )
            })}
            <Text style={{ color: theme.textFaint, fontSize: f(12.5), marginTop: f(2), lineHeight: f(17) }}>
              Progress is set by the office for now. Driver-tap updates are coming.
            </Text>
          </Card>
        </View>

        {/* POD */}
        <View style={{ marginTop: f(18) }}>
          <SectionLabel>Proof of delivery</SectionLabel>
          <Card>
            {loading ? <ActivityIndicator color={theme.primary} /> : (
              <>
                {pods.length === 0 ? (
                  <Text style={{ color: theme.textMuted, fontSize: f(14), marginBottom: f(14) }}>No proof of delivery filed yet.</Text>
                ) : pods.map((d) => (
                  <View key={d.id} style={{ flexDirection: 'row', alignItems: 'center', gap: f(10), marginBottom: f(10) }}>
                    <Text style={{ color: theme.success, fontSize: f(15) }}>✓</Text>
                    <Text style={{ color: theme.text, fontSize: f(14), flex: 1 }} numberOfLines={1}>{d.file_name || 'POD'}</Text>
                    <Pill label={d.verification_status === 'VERIFIED' ? 'verified' : 'to verify'} tone={d.verification_status === 'VERIFIED' ? 'success' : 'warning'} />
                  </View>
                ))}

                {uploading ? (
                  <View style={{ paddingVertical: f(16), alignItems: 'center' }}><ActivityIndicator color={theme.primary} /></View>
                ) : (
                  <View style={{ flexDirection: 'row', gap: f(10), marginTop: f(6) }}>
                    <View style={{ flex: 1 }}><Button title="Take photo" icon="📷" onPress={() => addPod(true)} /></View>
                    <View style={{ flex: 1 }}><Button title="Gallery" icon="🖼️" variant="secondary" onPress={() => addPod(false)} /></View>
                  </View>
                )}
              </>
            )}
          </Card>
        </View>
      </ScrollView>
    </View>
  )
}

function Row({ label, value, f }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: f(12), gap: f(12) }}>
      <Text style={{ color: theme.textMuted, fontSize: f(14) }}>{label}</Text>
      <Text style={{ color: theme.text, fontSize: f(14), fontWeight: '600', flex: 1, textAlign: 'right' }} numberOfLines={2}>{value}</Text>
    </View>
  )
}
function fmt(v) { try { return new Date(v).toLocaleString() } catch { return String(v) } }

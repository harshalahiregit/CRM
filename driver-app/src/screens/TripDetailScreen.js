import { useState, useEffect, useCallback } from 'react'
import { View, Text, TouchableOpacity, ScrollView, ActivityIndicator, Alert } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import { theme } from '../theme'
import { api } from '../api'
import { StatusPill } from './TripsScreen'
import { JOURNEY, journeyIndex } from '../status'
import { useLayout } from '../responsive'

export default function TripDetailScreen({ trip: initial, onBack }) {
  const { topInset, f, gutter, maxContent } = useLayout()
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

  // Photograph or pick the signed sheet, then upload. Camera and gallery are
  // both offered because a camera can fail on some phones, and a driver may
  // already have the photo.
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
      form.append('file', {
        uri: asset.uri,
        name: asset.fileName || `pod-${Date.now()}.jpg`,
        type: asset.mimeType || 'image/jpeg',
      })
      await api.uploadPod(trip.id, form)
      Alert.alert('Filed', 'The proof of delivery is on the trip. The office will verify it.')
      await load()
    } catch (e) {
      // Distinguish "server refused it" from "could not reach the server".
      const msg = e?.status === 0
        ? 'Upload failed — could not reach the server. Check your connection and try again.'
        : (e?.message || 'The server refused the file. Try again.')
      Alert.alert('Could not file the POD', msg)
    } finally {
      setUploading(false)
    }
  }

  const step = journeyIndex(trip.status)
  const pods = docs.filter((d) => d.document_type === 'pod')

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <View style={{ paddingTop: f(12), paddingHorizontal: gutter, paddingBottom: 12, flexDirection: 'row', alignItems: 'center', gap: 14 }}>
        <TouchableOpacity onPress={onBack} hitSlop={{ top: 10, bottom: 10, left: 10, right: 10 }}>
          <Text style={{ color: theme.accent, fontSize: f(16), fontWeight: '700' }}>‹ Back</Text>
        </TouchableOpacity>
      </View>

      <ScrollView contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: 4, paddingBottom: 48, width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>
        <View style={{ flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center' }}>
          <Text style={{ color: theme.text, fontSize: f(24), fontWeight: '900', flex: 1, paddingRight: 10 }} numberOfLines={1}>{trip.trip_number || `Trip #${trip.id}`}</Text>
          <StatusPill status={trip.status} f={f} />
        </View>
        {trip.route ? <Text style={{ color: theme.textMuted, fontSize: f(15), marginTop: 6 }}>{trip.route}</Text> : null}
        {trip.dispatch_destination ? <Row label="Destination" value={trip.dispatch_destination} f={f} /> : null}
        {trip.planned_arrival_at ? <Row label="Planned arrival" value={fmt(trip.planned_arrival_at)} f={f} /> : null}

        {/* Journey — read-only for now. Becomes tappable once Dev 1 adds
            driver-reportable departed/arrived/delivered events. */}
        <Section title="Journey" f={f}>
          {JOURNEY.map((s, i) => (
            <View key={s.key} style={{ flexDirection: 'row', alignItems: 'center', gap: 12, marginBottom: 10 }}>
              <View style={{ width: 22, height: 22, borderRadius: 11, alignItems: 'center', justifyContent: 'center',
                backgroundColor: i <= step ? theme.accent : theme.input, borderWidth: i <= step ? 0 : 1, borderColor: theme.border }}>
                {i <= step ? <Text style={{ color: '#fff', fontSize: 12, fontWeight: '900' }}>✓</Text> : null}
              </View>
              <Text style={{ color: i <= step ? theme.text : theme.textMuted, fontSize: f(15), fontWeight: i === step ? '800' : '500', flex: 1 }}>{s.label}</Text>
            </View>
          ))}
          <Text style={{ color: theme.textMuted, fontSize: f(12.5), marginTop: 4 }}>
            Progress is set by the office for now. Driver-tap updates are coming.
          </Text>
        </Section>

        {/* POD — the one the driver owns today. */}
        <Section title="Proof of delivery" f={f}>
          {loading ? <ActivityIndicator color={theme.accent} /> : (
            <>
              {pods.length === 0 ? (
                <Text style={{ color: theme.textMuted, fontSize: f(14), marginBottom: 12 }}>No proof of delivery filed yet.</Text>
              ) : (
                pods.map((d) => (
                  <View key={d.id} style={{ flexDirection: 'row', alignItems: 'center', gap: 10, marginBottom: 8 }}>
                    <Text style={{ color: theme.success, fontSize: f(14) }}>✓</Text>
                    <Text style={{ color: theme.text, fontSize: f(14), flex: 1 }} numberOfLines={1}>{d.file_name || 'POD'}</Text>
                    <Text style={{ color: theme.textMuted, fontSize: f(12.5) }}>{d.verification_status === 'VERIFIED' ? 'verified' : 'to verify'}</Text>
                  </View>
                ))
              )}

              {uploading ? (
                <View style={{ paddingVertical: 15, alignItems: 'center' }}><ActivityIndicator color={theme.accent} /></View>
              ) : (
                <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
                  <TouchableOpacity onPress={() => addPod(true)} activeOpacity={0.85}
                    style={{ flex: 1, backgroundColor: theme.accent, borderRadius: 14, paddingVertical: f(15), alignItems: 'center' }}>
                    <Text style={{ color: '#fff', fontSize: f(15), fontWeight: '800' }}>📷  Take photo</Text>
                  </TouchableOpacity>
                  <TouchableOpacity onPress={() => addPod(false)} activeOpacity={0.85}
                    style={{ flex: 1, borderRadius: 14, paddingVertical: f(15), alignItems: 'center', borderWidth: 1, borderColor: theme.border }}>
                    <Text style={{ color: theme.text, fontSize: f(15), fontWeight: '700' }}>🖼  From gallery</Text>
                  </TouchableOpacity>
                </View>
              )}
            </>
          )}
        </Section>
      </ScrollView>
    </View>
  )
}

function Section({ title, children, f }) {
  return (
    <View style={{ backgroundColor: theme.card, borderColor: theme.border, borderWidth: 1, borderRadius: 16, padding: 16, marginTop: 18 }}>
      <Text style={{ color: theme.textMuted, fontSize: f(13), fontWeight: '800', textTransform: 'uppercase', letterSpacing: 0.5, marginBottom: 14 }}>{title}</Text>
      {children}
    </View>
  )
}
function Row({ label, value, f }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: 10, gap: 12 }}>
      <Text style={{ color: theme.textMuted, fontSize: f(14) }}>{label}</Text>
      <Text style={{ color: theme.text, fontSize: f(14), fontWeight: '600', flex: 1, textAlign: 'right' }}>{value}</Text>
    </View>
  )
}
function fmt(v) {
  try { return new Date(v).toLocaleString() } catch { return String(v) }
}

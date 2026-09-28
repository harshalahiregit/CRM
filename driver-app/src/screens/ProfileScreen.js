import { useState, useEffect, useCallback } from 'react'
import { View, Text, Pressable, ScrollView, ActivityIndicator, Alert, Modal } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import { theme, radius } from '../theme'
import { api } from '../api'
import { useLayout } from '../responsive'
import { AppBar, Card, SectionLabel, Button, Pill, Skeleton } from '../ui'

export default function ProfileScreen({ user, onBack }) {
  const { f, gutter, maxContent, topInset, bottomInset } = useLayout()
  const [data, setData] = useState(null)
  const [loading, setLoading] = useState(true)
  const [err, setErr] = useState('')
  const [picking, setPicking] = useState(false)   // type-picker sheet open
  const [uploading, setUploading] = useState(false)

  const load = useCallback(async () => {
    setErr('')
    try { setData(await api.me()) }
    catch (e) { setErr(e?.message || 'Could not load your profile.') }
    finally { setLoading(false) }
  }, [])

  useEffect(() => { load() }, [load])

  const upload = async (type, fromCamera) => {
    setPicking(false)
    let shot
    try {
      if (fromCamera) {
        const perm = await ImagePicker.requestCameraPermissionsAsync()
        if (!perm.granted) return Alert.alert('Camera needed', 'Allow the camera in settings, or use the gallery.')
        shot = await ImagePicker.launchCameraAsync({ quality: 0.5 })
      } else {
        const perm = await ImagePicker.requestMediaLibraryPermissionsAsync()
        if (!perm.granted) return Alert.alert('Gallery needed', 'Allow photo access to pick the document.')
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
      form.append('document_type', type.value)
      form.append('file', { uri: asset.uri, name: asset.fileName || `${type.value}-${Date.now()}.jpg`, type: asset.mimeType || 'image/jpeg' })
      await api.uploadDocument(form)
      Alert.alert('Uploaded', 'The office will review it. You can drive once your required documents are approved.')
      await load()
    } catch (e) {
      const msg = e?.status === 0 ? 'Could not reach the server. Check your connection.' : (e?.message || 'Upload failed. Try again.')
      Alert.alert('Could not upload', msg)
    } finally { setUploading(false) }
  }

  const docStatus = (d) => d.verification_status === 'VERIFIED'
    ? { label: 'approved', tone: 'success' }
    : d.verification_status === 'REJECTED'
      ? { label: 'rejected', tone: 'danger' }
      : { label: 'pending', tone: 'warning' }

  const p = data?.profile
  const elig = data?.eligibility

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <AppBar title="My profile" subtitle={p?.name} onBack={onBack} />

      {loading ? (
        <View style={{ paddingHorizontal: gutter, paddingTop: f(8), width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>
          <Skeleton height={f(84)} radius={16} />
          <Skeleton height={f(14)} width="30%" style={{ marginTop: f(24) }} />
          <Skeleton height={f(140)} radius={16} style={{ marginTop: f(14) }} />
          <Skeleton height={f(14)} width="30%" style={{ marginTop: f(20) }} />
          <Skeleton height={f(120)} radius={16} style={{ marginTop: f(14) }} />
        </View>
      ) : err ? (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: f(28) }}>
          <Text style={{ color: theme.danger, fontSize: f(15), textAlign: 'center', marginBottom: f(16) }}>{err}</Text>
          <Button title="Try again" variant="secondary" onPress={() => { setLoading(true); load() }} />
        </View>
      ) : (
        <ScrollView showsVerticalScrollIndicator={false}
          contentContainerStyle={{ paddingHorizontal: gutter, paddingTop: f(4), paddingBottom: f(28) + bottomInset, width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>

          {/* Eligibility banner */}
          <View style={{ backgroundColor: elig?.eligible ? theme.successTint : theme.warningTint, borderRadius: radius.lg, padding: f(16), flexDirection: 'row', gap: f(12), alignItems: 'flex-start' }}>
            <Text style={{ fontSize: f(20) }}>{elig?.eligible ? '✅' : '⏳'}</Text>
            <View style={{ flex: 1 }}>
              <Text style={{ color: elig?.eligible ? theme.success : theme.warning, fontSize: f(15), fontWeight: '800' }}>
                {elig?.eligible ? 'Cleared to drive' : 'Not cleared yet'}
              </Text>
              <Text style={{ color: theme.textMuted, fontSize: f(13.5), marginTop: f(4), lineHeight: f(19) }}>{elig?.message}</Text>
              {!elig?.eligible && elig?.blocking?.length ? (
                <View style={{ marginTop: f(8), gap: f(4) }}>
                  {elig.blocking.map((b, i) => (
                    <Text key={i} style={{ color: theme.text, fontSize: f(13) }}>• {b.label}{b.awaiting ? '  (waiting for the office)' : ''}</Text>
                  ))}
                </View>
              ) : null}
            </View>
          </View>

          {/* Identity */}
          <View style={{ marginTop: f(18) }}>
            <SectionLabel>Details</SectionLabel>
            <Card>
              <Row label="Name" value={p?.name} f={f} />
              {p?.phone ? <Row label="Phone" value={p.phone} f={f} /> : null}
              {p?.licence_number ? <Row label="Licence" value={p.licence_number} f={f} /> : null}
              {p?.licence_class ? <Row label="Class" value={p.licence_class} f={f} /> : null}
              {p?.licence_expiry ? <Row label="Licence expiry" value={p.licence_expiry} f={f} /> : null}
            </Card>
          </View>

          {/* Documents */}
          <View style={{ marginTop: f(18) }}>
            <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between' }}>
              <SectionLabel>My documents</SectionLabel>
              {uploading ? <ActivityIndicator color={theme.primary} /> : null}
            </View>
            <Card>
              {(data?.documents ?? []).length === 0 ? (
                <Text style={{ color: theme.textMuted, fontSize: f(14), marginBottom: f(14) }}>No documents uploaded yet. Add your licence, medical certificate and any others the office needs.</Text>
              ) : (data.documents.map((d) => {
                const s = docStatus(d)
                return (
                  <View key={d.id} style={{ flexDirection: 'row', alignItems: 'center', gap: f(10), paddingVertical: f(8), borderBottomWidth: 1, borderBottomColor: theme.border }}>
                    <Text style={{ fontSize: f(15) }}>📄</Text>
                    <View style={{ flex: 1 }}>
                      <Text style={{ color: theme.text, fontSize: f(14), fontWeight: '600' }} numberOfLines={1}>{labelFor(d.document_type, data.types)}</Text>
                      {d.document_number ? <Text style={{ color: theme.textFaint, fontSize: f(12) }} numberOfLines={1}>{d.document_number}</Text> : null}
                    </View>
                    <Pill label={s.label} tone={s.tone} />
                  </View>
                )
              }))}
              <View style={{ marginTop: f(14) }}>
                <Button title="Upload a document" icon="＋" onPress={() => setPicking(true)} loading={uploading} />
              </View>
            </Card>
          </View>
        </ScrollView>
      )}

      {/* Type picker sheet */}
      <TypePicker visible={picking} types={data?.types ?? []} onClose={() => setPicking(false)} onPick={upload} />
    </View>
  )
}

function TypePicker({ visible, types, onClose, onPick }) {
  const { f, gutter, bottomInset } = useLayout()
  const [chosen, setChosen] = useState(null)
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable onPress={onClose} style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.55)', justifyContent: 'flex-end' }}>
        <Pressable style={{ backgroundColor: theme.surface, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, padding: gutter, paddingBottom: gutter + bottomInset }}>
          <View style={{ alignItems: 'center', marginBottom: f(14) }}>
            <View style={{ width: 40, height: 4, borderRadius: 2, backgroundColor: theme.borderStrong }} />
          </View>
          <Text style={{ color: theme.text, fontSize: f(18), fontWeight: '800', marginBottom: f(4) }}>Which document?</Text>
          <Text style={{ color: theme.textMuted, fontSize: f(13.5), marginBottom: f(14) }}>Pick the type, then take a photo or choose one.</Text>

          <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: f(8), marginBottom: f(18) }}>
            {types.map((t) => {
              const on = chosen?.value === t.value
              return (
                <Pressable key={t.value} onPress={() => setChosen(t)}
                  style={{ paddingHorizontal: f(14), paddingVertical: f(10), borderRadius: radius.pill, borderWidth: 1.5,
                    backgroundColor: on ? theme.primaryTint : theme.input, borderColor: on ? theme.primary : theme.border }}>
                  <Text style={{ color: on ? theme.primary : theme.text, fontSize: f(13.5), fontWeight: '700' }}>{t.label}</Text>
                </Pressable>
              )
            })}
          </View>

          <View style={{ flexDirection: 'row', gap: f(10) }}>
            <View style={{ flex: 1 }}><Button title="Take photo" icon="📷" disabled={!chosen} onPress={() => onPick(chosen, true)} /></View>
            <View style={{ flex: 1 }}><Button title="Gallery" icon="🖼️" variant="secondary" disabled={!chosen} onPress={() => onPick(chosen, false)} /></View>
          </View>
        </Pressable>
      </Pressable>
    </Modal>
  )
}

function labelFor(type, types) {
  return (types || []).find((t) => t.value === type)?.label || type
}
function Row({ label, value, f }) {
  return (
    <View style={{ flexDirection: 'row', justifyContent: 'space-between', marginTop: f(10), gap: f(12) }}>
      <Text style={{ color: theme.textMuted, fontSize: f(14) }}>{label}</Text>
      <Text style={{ color: theme.text, fontSize: f(14), fontWeight: '600', flex: 1, textAlign: 'right' }} numberOfLines={2}>{value}</Text>
    </View>
  )
}

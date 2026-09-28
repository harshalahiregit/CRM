import { useState } from 'react'
import { View, Text, Pressable, Modal, ScrollView, TextInput, ActivityIndicator, Alert } from 'react-native'
import * as ImagePicker from 'expo-image-picker'
import { theme, radius } from '../theme'
import { api, isNotEnabled } from '../api'
import { useLayout } from '../responsive'
import { Button } from '../ui'

// ── Pre-trip inspection (F2) ────────────────────────────────────────────────
// Critical items (a defect blocks release) are marked; the rest are advisory.
const PRETRIP_ITEMS = [
  { key: 'brakes', label: 'Brakes', critical: true },
  { key: 'tyres', label: 'Tyres', critical: true },
  { key: 'coupling', label: 'Coupling / fifth wheel', critical: true },
  { key: 'lights', label: 'Lights & indicators', critical: false },
  { key: 'genset', label: 'Genset / reefer', critical: false },
  { key: 'fluids', label: 'Oil, coolant, fuel', critical: false },
  { key: 'documents', label: 'Documents on board', critical: false },
]

export function PretripModal({ tripId, visible, onClose, onDone }) {
  const { f, gutter, bottomInset } = useLayout()
  const [state, setState] = useState({}) // key -> { ok, note }
  const [busy, setBusy] = useState(false)

  const setItem = (key, ok) => setState((s) => ({ ...s, [key]: { ...(s[key] || {}), ok } }))
  const setNote = (key, note) => setState((s) => ({ ...s, [key]: { ...(s[key] || {}), note } }))

  const allAnswered = PRETRIP_ITEMS.every((i) => state[i.key]?.ok !== undefined)
  const criticalDefect = PRETRIP_ITEMS.some((i) => i.critical && state[i.key]?.ok === false)

  const submit = async () => {
    if (!allAnswered) return Alert.alert('Finish the check', 'Mark every item OK or Defect first.')
    setBusy(true)
    try {
      await api.submitPretrip(tripId, {
        checks: PRETRIP_ITEMS.map((i) => ({ key: i.key, label: i.label, ok: state[i.key].ok, note: state[i.key].note || null, critical: i.critical })),
        has_critical_defect: criticalDefect,
      })
      Alert.alert(criticalDefect ? 'Defect logged' : 'Inspection filed',
        criticalDefect ? 'A critical defect was reported — the office has been alerted and the vehicle is held.' : 'Pre-trip inspection recorded.',
        [{ text: 'OK', onPress: () => { onClose(); onDone?.() } }])
    } catch (e) {
      if (isNotEnabled(e)) Alert.alert('Not switched on yet', 'The office is enabling pre-trip inspections. Your check was not saved.')
      else Alert.alert('Could not file it', e?.message || 'Try again.')
    } finally { setBusy(false) }
  }

  return (
    <Sheet visible={visible} onClose={onClose} title="Pre-trip inspection" subtitle="Mark each item before you set off." gutter={gutter} bottomInset={bottomInset} f={f}>
      <ScrollView showsVerticalScrollIndicator={false} style={{ maxHeight: 420 }}>
        {PRETRIP_ITEMS.map((i) => {
          const v = state[i.key]?.ok
          return (
            <View key={i.key} style={{ marginBottom: f(14) }}>
              <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', gap: f(10) }}>
                <Text style={{ color: theme.text, fontSize: f(14.5), fontWeight: '600', flex: 1 }}>
                  {i.label}{i.critical ? <Text style={{ color: theme.danger }}>  *</Text> : null}
                </Text>
                <View style={{ flexDirection: 'row', gap: f(6) }}>
                  <Toggle label="OK" on={v === true} tone="success" onPress={() => setItem(i.key, true)} f={f} />
                  <Toggle label="Defect" on={v === false} tone="danger" onPress={() => setItem(i.key, false)} f={f} />
                </View>
              </View>
              {v === false ? (
                <TextInput value={state[i.key]?.note || ''} onChangeText={(t) => setNote(i.key, t)}
                  placeholder="What's wrong? (optional)" placeholderTextColor={theme.textFaint}
                  style={{ marginTop: f(8), backgroundColor: theme.input, color: theme.text, borderRadius: radius.md, paddingHorizontal: f(12), paddingVertical: f(10), fontSize: f(14), borderWidth: 1, borderColor: theme.border }} />
              ) : null}
            </View>
          )
        })}
      </ScrollView>
      {criticalDefect ? (
        <Text style={{ color: theme.danger, fontSize: f(12.5), marginTop: f(4), marginBottom: f(10) }}>
          A critical defect (*) will hold the vehicle and alert the office.
        </Text>
      ) : null}
      <Button title={criticalDefect ? 'Report defect' : 'File inspection'} onPress={submit} loading={busy} variant={criticalDefect ? 'danger' : 'primary'} />
    </Sheet>
  )
}

// ── Incident / exception reporting (F7) ─────────────────────────────────────
const INCIDENT_TYPES = [
  { value: 'breakdown', label: 'Breakdown' }, { value: 'accident', label: 'Accident' },
  { value: 'delay', label: 'Traffic delay' }, { value: 'deviation', label: 'Route deviation' },
  { value: 'document', label: 'Document issue' }, { value: 'other', label: 'Other' },
]

export function IncidentModal({ tripId, visible, onClose, onDone }) {
  const { f, gutter, bottomInset } = useLayout()
  const [type, setType] = useState(null)
  const [desc, setDesc] = useState('')
  const [photo, setPhoto] = useState(null)
  const [busy, setBusy] = useState(false)

  const pick = async (fromCamera) => {
    try {
      const perm = fromCamera ? await ImagePicker.requestCameraPermissionsAsync() : await ImagePicker.requestMediaLibraryPermissionsAsync()
      if (!perm.granted) return Alert.alert('Permission needed', 'Allow access to attach a photo.')
      const shot = fromCamera ? await ImagePicker.launchCameraAsync({ quality: 0.5 }) : await ImagePicker.launchImageLibraryAsync({ quality: 0.5 })
      if (!shot.canceled && shot.assets?.length) setPhoto(shot.assets[0])
    } catch (e) { Alert.alert('Could not open', String(e?.message || e)) }
  }

  const submit = async () => {
    if (!type) return Alert.alert('Pick a type', 'What kind of incident is this?')
    if (!desc.trim()) return Alert.alert('Add a note', 'Briefly describe what happened.')
    setBusy(true)
    try {
      const form = new FormData()
      form.append('type', type.value)
      form.append('description', desc.trim())
      if (photo) form.append('photo', { uri: photo.uri, name: photo.fileName || `incident-${Date.now()}.jpg`, type: photo.mimeType || 'image/jpeg' })
      await api.reportIncident(tripId, form)
      Alert.alert('Reported', 'The office has been notified.', [{ text: 'OK', onPress: () => { onClose(); onDone?.() } }])
    } catch (e) {
      if (isNotEnabled(e)) Alert.alert('Not switched on yet', 'The office is enabling incident reports. Nothing was sent.')
      else Alert.alert('Could not report it', e?.message || 'Try again.')
    } finally { setBusy(false) }
  }

  return (
    <Sheet visible={visible} onClose={onClose} title="Report an incident" subtitle="Tell the office what happened." gutter={gutter} bottomInset={bottomInset} f={f}>
      <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: f(8), marginBottom: f(14) }}>
        {INCIDENT_TYPES.map((t) => (
          <Toggle key={t.value} label={t.label} on={type?.value === t.value} tone="primary" onPress={() => setType(t)} f={f} />
        ))}
      </View>
      <TextInput value={desc} onChangeText={setDesc} placeholder="What happened?" placeholderTextColor={theme.textFaint} multiline
        style={{ backgroundColor: theme.input, color: theme.text, borderRadius: radius.md, padding: f(12), fontSize: f(14.5), minHeight: f(90), textAlignVertical: 'top', borderWidth: 1, borderColor: theme.border, marginBottom: f(12) }} />
      <View style={{ flexDirection: 'row', gap: f(10), marginBottom: f(14) }}>
        <View style={{ flex: 1 }}><Button title={photo ? 'Photo added ✓' : 'Camera'} icon="📷" variant="secondary" onPress={() => pick(true)} /></View>
        <View style={{ flex: 1 }}><Button title="Gallery" icon="🖼️" variant="secondary" onPress={() => pick(false)} /></View>
      </View>
      <Button title="Send report" onPress={submit} loading={busy} />
    </Sheet>
  )
}

// ── Handover feedback (F11) — the 10-second prompt ──────────────────────────
const HANDOVER_CATS = ['Delay', 'Damage', 'Temperature', 'Documentation', 'Customer']

export function HandoverModal({ tripId, visible, onClose, onDone }) {
  const { f, gutter, bottomInset } = useLayout()
  const [rating, setRating] = useState(null)
  const [cat, setCat] = useState(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)

  const submit = async () => {
    if (!rating) return Alert.alert('One tap', 'How was the handover?')
    setBusy(true)
    try {
      await api.handoverFeedback(tripId, { rating, category: rating === 'good' ? null : cat, note: note.trim() || null })
      Alert.alert('Thanks', 'Your feedback is recorded.', [{ text: 'OK', onPress: () => { onClose(); onDone?.() } }])
    } catch (e) {
      if (isNotEnabled(e)) Alert.alert('Not switched on yet', 'The office is enabling handover feedback.')
      else Alert.alert('Could not send', e?.message || 'Try again.')
    } finally { setBusy(false) }
  }

  return (
    <Sheet visible={visible} onClose={onClose} title="How was the handover?" subtitle="Ten seconds — it helps the office." gutter={gutter} bottomInset={bottomInset} f={f}>
      <View style={{ flexDirection: 'row', gap: f(8), marginBottom: f(14) }}>
        {[['good', '🙂 Good'], ['okay', '😐 Okay'], ['issue', '⚠️ Issue']].map(([v, l]) => (
          <Pressable key={v} onPress={() => setRating(v)}
            style={{ flex: 1, alignItems: 'center', paddingVertical: f(14), borderRadius: radius.md, borderWidth: 1.5,
              backgroundColor: rating === v ? theme.primaryTint : theme.input, borderColor: rating === v ? theme.primary : theme.border }}>
            <Text style={{ color: rating === v ? theme.primary : theme.text, fontSize: f(13.5), fontWeight: '700' }}>{l}</Text>
          </Pressable>
        ))}
      </View>
      {rating && rating !== 'good' ? (
        <View style={{ flexDirection: 'row', flexWrap: 'wrap', gap: f(8), marginBottom: f(14) }}>
          {HANDOVER_CATS.map((c) => <Toggle key={c} label={c} on={cat === c} tone="warning" onPress={() => setCat(c)} f={f} />)}
        </View>
      ) : null}
      <TextInput value={note} onChangeText={setNote} placeholder="Anything to add? (optional)" placeholderTextColor={theme.textFaint}
        style={{ backgroundColor: theme.input, color: theme.text, borderRadius: radius.md, paddingHorizontal: f(12), paddingVertical: f(10), fontSize: f(14), borderWidth: 1, borderColor: theme.border, marginBottom: f(14) }} />
      <Button title="Submit" onPress={submit} loading={busy} />
    </Sheet>
  )
}

// ── Shared bottom sheet + toggle chip ───────────────────────────────────────
function Sheet({ visible, onClose, title, subtitle, children, gutter, bottomInset, f }) {
  return (
    <Modal visible={visible} transparent animationType="slide" onRequestClose={onClose}>
      <Pressable onPress={onClose} style={{ flex: 1, backgroundColor: 'rgba(0,0,0,0.55)', justifyContent: 'flex-end' }}>
        <Pressable style={{ backgroundColor: theme.surface, borderTopLeftRadius: radius.xl, borderTopRightRadius: radius.xl, padding: gutter, paddingBottom: gutter + bottomInset }}>
          <View style={{ alignItems: 'center', marginBottom: f(14) }}>
            <View style={{ width: 40, height: 4, borderRadius: 2, backgroundColor: theme.borderStrong }} />
          </View>
          <Text style={{ color: theme.text, fontSize: f(18), fontWeight: '800' }}>{title}</Text>
          {subtitle ? <Text style={{ color: theme.textMuted, fontSize: f(13.5), marginTop: f(2), marginBottom: f(16) }}>{subtitle}</Text> : <View style={{ height: f(16) }} />}
          {children}
        </Pressable>
      </Pressable>
    </Modal>
  )
}

function Toggle({ label, on, tone = 'primary', onPress, f }) {
  const color = { primary: theme.primary, success: theme.success, danger: theme.danger, warning: theme.warning }[tone]
  const tint = { primary: theme.primaryTint, success: theme.successTint, danger: theme.dangerTint, warning: theme.warningTint }[tone]
  return (
    <Pressable onPress={onPress}
      style={{ paddingHorizontal: f(14), paddingVertical: f(9), borderRadius: radius.pill, borderWidth: 1.5,
        backgroundColor: on ? tint : theme.input, borderColor: on ? color : theme.border }}>
      <Text style={{ color: on ? color : theme.text, fontSize: f(13), fontWeight: '700' }}>{label}</Text>
    </Pressable>
  )
}

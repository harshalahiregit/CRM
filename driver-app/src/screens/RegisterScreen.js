import { useState, useEffect } from 'react'
import { View, Text, Pressable, Alert } from 'react-native'
import { theme, radius } from '../theme'
import { api } from '../api'
import { getBaseUrl, setBaseUrl } from '../storage'
import { DEFAULT_SERVER } from '../config'
import { useLayout } from '../responsive'
import { Screen, Field, Button } from '../ui'

export default function RegisterScreen({ onDone, onBack }) {
  const { f } = useLayout()
  const [server, setServer] = useState('')
  const [form, setForm] = useState({ name: '', email: '', password: '', phone: '', licence_number: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { getBaseUrl().then((u) => setServer(u || DEFAULT_SERVER)) }, [])
  const set = (k, v) => setForm({ ...form, [k]: v })

  const submit = async () => {
    setErr('')
    const base = (server || DEFAULT_SERVER).trim().replace(/\/+$/, '')
    if (!form.name.trim() || !form.email.trim() || !form.password) return setErr('Name, email and password are required.')
    if (form.password.length < 6) return setErr('Password must be at least 6 characters.')
    setBusy(true)
    try {
      await setBaseUrl(base)
      await api.register({
        name: form.name.trim(), email: form.email.trim().toLowerCase(), password: form.password,
        phone: form.phone.trim() || null, licence_number: form.licence_number.trim() || null,
      })
      Alert.alert('Request sent',
        'The office will approve your account. Once approved, you’ll get an email and can sign in with this email and password.',
        [{ text: 'OK', onPress: onDone }])
    } catch (e) {
      setErr(e?.message || 'Could not send your request. Try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen>
      <View style={{ marginBottom: f(22) }}>
        <View style={{ width: f(52), height: f(52), borderRadius: radius.lg, backgroundColor: theme.primaryTint, alignItems: 'center', justifyContent: 'center', marginBottom: f(14) }}>
          <Text style={{ fontSize: f(26) }}>📝</Text>
        </View>
        <Text style={{ color: theme.text, fontSize: f(26), fontWeight: '900' }}>Register as a driver</Text>
        <Text style={{ color: theme.textMuted, fontSize: f(14.5), marginTop: f(6), lineHeight: f(20) }}>The office approves your account, then you can sign in.</Text>
      </View>

      <Field label="Full name" value={form.name} onChangeText={(v) => set('name', v)} placeholder="Ramesh Kumar" />
      <View style={{ height: f(14) }} />
      <Field label="Email" value={form.email} onChangeText={(v) => set('email', v)} placeholder="you@email.com" autoCapitalize="none" keyboardType="email-address" />
      <View style={{ height: f(14) }} />
      <Field label="Password" value={form.password} onChangeText={(v) => set('password', v)} placeholder="At least 6 characters" secureTextEntry />
      <View style={{ height: f(14) }} />
      <Field label="Phone" value={form.phone} onChangeText={(v) => set('phone', v)} placeholder="98765 43210" keyboardType="phone-pad" hint="Optional" />
      <View style={{ height: f(14) }} />
      <Field label="Licence number" value={form.licence_number} onChangeText={(v) => set('licence_number', v)} placeholder="MH0120110012345" autoCapitalize="characters" hint="Optional" />

      {err ? <Text style={{ color: theme.danger, fontSize: f(14), marginTop: f(14), lineHeight: f(19) }}>{err}</Text> : null}

      <View style={{ height: f(24) }} />
      <Button title="Send request" onPress={submit} loading={busy} icon="✉️" />
      <Pressable onPress={onBack} hitSlop={10} style={({ pressed }) => ({ alignItems: 'center', marginTop: f(18), opacity: pressed ? 0.6 : 1 })}>
        <Text style={{ color: theme.primary, fontSize: f(14.5), fontWeight: '800' }}>Back to sign in</Text>
      </Pressable>
    </Screen>
  )
}

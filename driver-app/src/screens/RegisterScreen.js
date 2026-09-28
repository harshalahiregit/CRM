import { useState, useEffect } from 'react'
import { View, Text, TextInput, TouchableOpacity, ActivityIndicator, ScrollView, KeyboardAvoidingView, Platform, Alert } from 'react-native'
import { theme } from '../theme'
import { api } from '../api'
import { getBaseUrl, setBaseUrl } from '../storage'
import { DEFAULT_SERVER } from '../config'
import { useLayout } from '../responsive'

export default function RegisterScreen({ onDone, onBack }) {
  const { topInset, f, gutter, maxContent } = useLayout()
  const [server, setServer] = useState('')
  const [form, setForm] = useState({ name: '', email: '', password: '', phone: '', licence_number: '' })
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { getBaseUrl().then((u) => setServer(u || DEFAULT_SERVER)) }, [])

  const set = (k, v) => setForm({ ...form, [k]: v })

  const submit = async () => {
    setErr('')
    const base = server.trim().replace(/\/+$/, '')
    if (!base) return setErr('Enter the server address (ask the office).')
    if (!form.name.trim() || !form.email.trim() || !form.password) return setErr('Name, email and password are required.')
    if (form.password.length < 6) return setErr('Password must be at least 6 characters.')

    setBusy(true)
    try {
      await setBaseUrl(base)
      await api.register({
        name: form.name.trim(), email: form.email.trim().toLowerCase(), password: form.password,
        phone: form.phone.trim() || null, licence_number: form.licence_number.trim() || null,
      })
      Alert.alert(
        'Request sent',
        'The office will approve your account. Once approved, sign in with this email and password.',
        [{ text: 'OK', onPress: onDone }]
      )
    } catch (e) {
      setErr(e?.message || 'Could not send your request.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <ScrollView contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', paddingHorizontal: gutter, paddingVertical: f(28) }} keyboardShouldPersistTaps="handled">
        <View style={{ width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>
          <Text style={{ color: theme.text, fontSize: f(26), fontWeight: '900', marginBottom: 4 }}>Register as a driver</Text>
          <Text style={{ color: theme.textMuted, fontSize: f(14.5), marginBottom: f(26) }}>The office approves your account, then you can sign in.</Text>

          <Label f={f}>Server address</Label>
          <Input f={f} value={server} onChangeText={setServer} placeholder="https://your-server" autoCapitalize="none" keyboardType="url" />

          <Label f={f} style={{ marginTop: f(16) }}>Full name</Label>
          <Input f={f} value={form.name} onChangeText={(v) => set('name', v)} placeholder="Ramesh Kumar" />

          <Label f={f} style={{ marginTop: f(16) }}>Email</Label>
          <Input f={f} value={form.email} onChangeText={(v) => set('email', v)} placeholder="you@email.com" autoCapitalize="none" keyboardType="email-address" />

          <Label f={f} style={{ marginTop: f(16) }}>Password</Label>
          <Input f={f} value={form.password} onChangeText={(v) => set('password', v)} placeholder="At least 6 characters" secureTextEntry />

          <Label f={f} style={{ marginTop: f(16) }}>Phone (optional)</Label>
          <Input f={f} value={form.phone} onChangeText={(v) => set('phone', v)} placeholder="98765 43210" keyboardType="phone-pad" />

          <Label f={f} style={{ marginTop: f(16) }}>Licence number (optional)</Label>
          <Input f={f} value={form.licence_number} onChangeText={(v) => set('licence_number', v)} placeholder="MH0120110012345" autoCapitalize="characters" />

          {err ? <Text style={{ color: theme.danger, fontSize: f(14), marginTop: 16 }}>{err}</Text> : null}

          <TouchableOpacity onPress={submit} disabled={busy} activeOpacity={0.85}
            style={{ backgroundColor: theme.accent, borderRadius: 14, paddingVertical: f(16), alignItems: 'center', marginTop: f(24), opacity: busy ? 0.6 : 1 }}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={{ color: '#fff', fontSize: f(16), fontWeight: '800' }}>Send request</Text>}
          </TouchableOpacity>

          <TouchableOpacity onPress={onBack} style={{ alignItems: 'center', marginTop: f(18) }}>
            <Text style={{ color: theme.accent, fontSize: f(14.5), fontWeight: '700' }}>Back to sign in</Text>
          </TouchableOpacity>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

function Label({ children, style, f }) {
  return <Text style={[{ color: theme.text, fontSize: f(14), fontWeight: '700', marginBottom: 8 }, style]}>{children}</Text>
}
function Input({ f, ...props }) {
  return (
    <TextInput placeholderTextColor={theme.textMuted} {...props}
      style={{ backgroundColor: theme.input, borderColor: theme.border, borderWidth: 1, borderRadius: 12,
        paddingHorizontal: 14, paddingVertical: f(14), color: theme.text, fontSize: f(16) }} />
  )
}

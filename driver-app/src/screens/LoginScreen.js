import { useState, useEffect } from 'react'
import { View, Text, TextInput, TouchableOpacity, ActivityIndicator, ScrollView, KeyboardAvoidingView, Platform } from 'react-native'
import { theme } from '../theme'
import { api } from '../api'
import { setToken, setUser, getBaseUrl, setBaseUrl } from '../storage'
import { DEFAULT_SERVER } from '../config'
import { useLayout } from '../responsive'

export default function LoginScreen({ onLoggedIn, onRegister }) {
  const { topInset, f, gutter, maxContent } = useLayout()
  const [server, setServer] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  // Pre-fill the live server; remember a custom one if the driver set it.
  useEffect(() => { getBaseUrl().then((u) => setServer(u || DEFAULT_SERVER)) }, [])

  const submit = async () => {
    setErr('')
    const base = server.trim().replace(/\/+$/, '')
    if (!base) return setErr('Enter the server address (ask the office).')
    if (!email.trim() || !password) return setErr('Enter your email and password.')

    setBusy(true)
    try {
      await setBaseUrl(base)
      const result = await api.login(email.trim(), password)
      await setToken(result.access_token)
      if (result.user) await setUser(result.user)
      onLoggedIn(result.user)
    } catch (e) {
      setErr(e?.message || 'Could not sign in.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: theme.bg, paddingTop: topInset }}>
      <ScrollView contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', paddingHorizontal: gutter, paddingVertical: f(28) }} keyboardShouldPersistTaps="handled">
        <View style={{ width: '100%', maxWidth: maxContent, alignSelf: 'center' }}>
          <Text style={{ color: theme.text, fontSize: f(28), fontWeight: '900', marginBottom: 4 }}>Sangoé Driver</Text>
          <Text style={{ color: theme.textMuted, fontSize: f(15), marginBottom: f(32) }}>Sign in to see your trip.</Text>

          <Label f={f}>Server address</Label>
          <Input f={f} value={server} onChangeText={setServer} placeholder="http://192.168.1.5:8000"
            autoCapitalize="none" keyboardType="url" />
          <Hint f={f}>The address the office gives you — the machine STOS runs on.</Hint>

          <Label f={f} style={{ marginTop: f(18) }}>Email</Label>
          <Input f={f} value={email} onChangeText={setEmail} placeholder="you@company.com"
            autoCapitalize="none" keyboardType="email-address" />

          <Label f={f} style={{ marginTop: f(18) }}>Password</Label>
          <Input f={f} value={password} onChangeText={setPassword} placeholder="••••••••" secureTextEntry />

          {err ? <Text style={{ color: theme.danger, fontSize: f(14), marginTop: 16 }}>{err}</Text> : null}

          <TouchableOpacity onPress={submit} disabled={busy} activeOpacity={0.85}
            style={{ backgroundColor: theme.accent, borderRadius: 14, paddingVertical: f(16), alignItems: 'center', marginTop: f(28), opacity: busy ? 0.6 : 1 }}>
            {busy ? <ActivityIndicator color="#fff" /> : <Text style={{ color: '#fff', fontSize: f(16), fontWeight: '800' }}>Sign in</Text>}
          </TouchableOpacity>

          <TouchableOpacity onPress={onRegister} style={{ alignItems: 'center', marginTop: f(20) }}>
            <Text style={{ color: theme.textMuted, fontSize: f(14.5) }}>
              New driver?  <Text style={{ color: theme.accent, fontWeight: '700' }}>Register</Text>
            </Text>
          </TouchableOpacity>
        </View>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

function Label({ children, style, f }) {
  return <Text style={[{ color: theme.text, fontSize: f(14), fontWeight: '700', marginBottom: 8 }, style]}>{children}</Text>
}
function Hint({ children, f }) {
  return <Text style={{ color: theme.textMuted, fontSize: f(12.5), marginTop: 6 }}>{children}</Text>
}
function Input({ f, ...props }) {
  return (
    <TextInput
      placeholderTextColor={theme.textMuted}
      {...props}
      style={{ backgroundColor: theme.input, borderColor: theme.border, borderWidth: 1, borderRadius: 12,
        paddingHorizontal: 14, paddingVertical: f(14), color: theme.text, fontSize: f(16) }} />
  )
}

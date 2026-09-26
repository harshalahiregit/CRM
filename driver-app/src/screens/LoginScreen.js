import { useState, useEffect } from 'react'
import { View, Text, TextInput, TouchableOpacity, ActivityIndicator, ScrollView, KeyboardAvoidingView, Platform } from 'react-native'
import { theme } from '../theme'
import { api } from '../api'
import { setToken, setUser, getBaseUrl, setBaseUrl } from '../storage'

export default function LoginScreen({ onLoggedIn }) {
  const [server, setServer] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  // Remember the server address between sessions.
  useEffect(() => { getBaseUrl().then((u) => u && setServer(u)) }, [])

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
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: theme.bg }}>
      <ScrollView contentContainerStyle={{ flexGrow: 1, justifyContent: 'center', padding: 24 }}>
        <Text style={{ color: theme.text, fontSize: 28, fontWeight: '900', marginBottom: 4 }}>Sangoé Driver</Text>
        <Text style={{ color: theme.textMuted, fontSize: 15, marginBottom: 32 }}>Sign in to see your trip.</Text>

        <Label>Server address</Label>
        <Input value={server} onChangeText={setServer} placeholder="http://192.168.1.5:8000"
          autoCapitalize="none" keyboardType="url" />
        <Hint>The address the office gives you — the machine STOS runs on.</Hint>

        <Label style={{ marginTop: 18 }}>Email</Label>
        <Input value={email} onChangeText={setEmail} placeholder="you@company.com"
          autoCapitalize="none" keyboardType="email-address" />

        <Label style={{ marginTop: 18 }}>Password</Label>
        <Input value={password} onChangeText={setPassword} placeholder="••••••••" secureTextEntry />

        {err ? <Text style={{ color: theme.danger, fontSize: 14, marginTop: 16 }}>{err}</Text> : null}

        <TouchableOpacity onPress={submit} disabled={busy} activeOpacity={0.85}
          style={{ backgroundColor: theme.accent, borderRadius: 14, paddingVertical: 16, alignItems: 'center', marginTop: 28, opacity: busy ? 0.6 : 1 }}>
          {busy ? <ActivityIndicator color="#fff" /> : <Text style={{ color: '#fff', fontSize: 16, fontWeight: '800' }}>Sign in</Text>}
        </TouchableOpacity>
      </ScrollView>
    </KeyboardAvoidingView>
  )
}

function Label({ children, style }) {
  return <Text style={[{ color: theme.text, fontSize: 14, fontWeight: '700', marginBottom: 8 }, style]}>{children}</Text>
}
function Hint({ children }) {
  return <Text style={{ color: theme.textMuted, fontSize: 12.5, marginTop: 6 }}>{children}</Text>
}
function Input(props) {
  return (
    <TextInput
      placeholderTextColor={theme.textMuted}
      {...props}
      style={{ backgroundColor: theme.input, borderColor: theme.border, borderWidth: 1, borderRadius: 12,
        paddingHorizontal: 14, paddingVertical: 14, color: theme.text, fontSize: 16 }} />
  )
}

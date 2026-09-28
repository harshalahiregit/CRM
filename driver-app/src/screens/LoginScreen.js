import { useState, useEffect } from 'react'
import { View, Text, Pressable } from 'react-native'
import { theme, radius } from '../theme'
import { api } from '../api'
import { setToken, setUser, getBaseUrl, setBaseUrl } from '../storage'
import { DEFAULT_SERVER } from '../config'
import { useLayout } from '../responsive'
import { Screen, Field, Button } from '../ui'

export default function LoginScreen({ onLoggedIn, onRegister, onForgot }) {
  const { f } = useLayout()
  const [server, setServer] = useState('')
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [showServer, setShowServer] = useState(false)
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  useEffect(() => { getBaseUrl().then((u) => setServer(u || DEFAULT_SERVER)) }, [])

  const submit = async () => {
    setErr('')
    const base = (server || DEFAULT_SERVER).trim().replace(/\/+$/, '')
    if (!email.trim() || !password) return setErr('Enter your email and password.')
    setBusy(true)
    try {
      await setBaseUrl(base)
      const result = await api.login(email.trim(), password)
      await setToken(result.access_token)
      if (result.user) await setUser(result.user)
      onLoggedIn(result.user)
    } catch (e) {
      setErr(e?.message || 'Could not sign in. Check your details and try again.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <Screen center>
      {/* Brand */}
      <View style={{ alignItems: 'center', marginBottom: f(28) }}>
        <View style={{ width: f(64), height: f(64), borderRadius: radius.xl, backgroundColor: theme.primaryTint, alignItems: 'center', justifyContent: 'center', marginBottom: f(16) }}>
          <Text style={{ fontSize: f(32) }}>🚚</Text>
        </View>
        <Text style={{ color: theme.text, fontSize: f(28), fontWeight: '900' }}>Sangoé Driver</Text>
        <Text style={{ color: theme.textMuted, fontSize: f(14.5), marginTop: f(5) }}>Sign in to see your trip.</Text>
      </View>

      <Field label="Email" value={email} onChangeText={setEmail} placeholder="you@company.com"
        autoCapitalize="none" keyboardType="email-address" />
      <View style={{ height: f(14) }} />
      <Field label="Password" value={password} onChangeText={setPassword} placeholder="••••••••" secureTextEntry />

      {err ? <Text style={{ color: theme.danger, fontSize: f(14), marginTop: f(14), lineHeight: f(19) }}>{err}</Text> : null}

      <View style={{ height: f(24) }} />
      <Button title="Sign in" onPress={submit} loading={busy} />

      <Pressable onPress={onForgot} hitSlop={10} style={({ pressed }) => ({ alignItems: 'center', marginTop: f(16), opacity: pressed ? 0.6 : 1 })}>
        <Text style={{ color: theme.primary, fontSize: f(14), fontWeight: '700' }}>Forgot password?</Text>
      </Pressable>

      <Pressable onPress={onRegister} hitSlop={10} style={({ pressed }) => ({ alignItems: 'center', marginTop: f(16), opacity: pressed ? 0.6 : 1 })}>
        <Text style={{ color: theme.textMuted, fontSize: f(14.5) }}>
          New driver?  <Text style={{ color: theme.primary, fontWeight: '800' }}>Register</Text>
        </Text>
      </Pressable>

      {/* Server address is an advanced setting; hidden by default so the screen
          is clean, but reachable for testing against another server. */}
      <Pressable onPress={() => setShowServer((v) => !v)} hitSlop={8} style={{ alignItems: 'center', marginTop: f(28) }}>
        <Text style={{ color: theme.textFaint, fontSize: f(12.5) }}>{showServer ? 'Hide server settings' : 'Server settings'}</Text>
      </Pressable>
      {showServer ? (
        <View style={{ marginTop: f(12) }}>
          <Field label="Server address" value={server} onChangeText={setServer}
            placeholder={DEFAULT_SERVER} autoCapitalize="none" keyboardType="url"
            hint="Leave as-is unless the office told you otherwise." />
        </View>
      ) : null}
    </Screen>
  )
}

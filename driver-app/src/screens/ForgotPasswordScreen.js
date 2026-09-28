import { useState } from 'react'
import { View, Text, Pressable, Alert } from 'react-native'
import { theme, radius } from '../theme'
import { api } from '../api'
import { getBaseUrl, setBaseUrl } from '../storage'
import { DEFAULT_SERVER } from '../config'
import { useLayout } from '../responsive'
import { Screen, Field, Button } from '../ui'

/**
 * Drivers' accounts are managed by the office, so "forgot password" asks the
 * office to reset it (they do it from the drivers board) rather than emailing a
 * self-service link. We tell the driver what happens next, and never reveal
 * whether the email is registered.
 */
export default function ForgotPasswordScreen({ onBack }) {
  const { f, gutter } = useLayout()
  const [email, setEmail] = useState('')
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState('')

  const submit = async () => {
    setErr('')
    if (!email.trim()) return setErr('Enter your email.')
    setBusy(true)
    try {
      const base = (await getBaseUrl()) || DEFAULT_SERVER
      await setBaseUrl(base)
      await api.forgotPassword(email.trim().toLowerCase())
      Alert.alert(
        'Ask the office',
        'The office resets driver passwords from the drivers board. Ask them to reset yours, then sign in with the new password they give you.',
        [{ text: 'OK', onPress: onBack }]
      )
    } catch (e) {
      setErr(e?.message || 'Could not send the request. Try again.')
    } finally { setBusy(false) }
  }

  return (
    <Screen>
      <View style={{ marginBottom: f(22) }}>
        <View style={{ width: f(52), height: f(52), borderRadius: radius.lg, backgroundColor: theme.primaryTint, alignItems: 'center', justifyContent: 'center', marginBottom: f(14) }}>
          <Text style={{ fontSize: f(26) }}>🔑</Text>
        </View>
        <Text style={{ color: theme.text, fontSize: f(26), fontWeight: '900' }}>Forgot password</Text>
        <Text style={{ color: theme.textMuted, fontSize: f(14.5), marginTop: f(6), lineHeight: f(20) }}>
          Your office manages driver passwords. Enter your email and they'll reset it for you.
        </Text>
      </View>

      <Field label="Email" value={email} onChangeText={setEmail} placeholder="you@email.com"
        autoCapitalize="none" keyboardType="email-address" />

      {err ? <Text style={{ color: theme.danger, fontSize: f(14), marginTop: f(14) }}>{err}</Text> : null}

      <View style={{ height: f(24) }} />
      <Button title="Ask the office to reset" onPress={submit} loading={busy} icon="✉️" />
      <Pressable onPress={onBack} hitSlop={10} style={({ pressed }) => ({ alignItems: 'center', marginTop: f(18), opacity: pressed ? 0.6 : 1 })}>
        <Text style={{ color: theme.primary, fontSize: f(14.5), fontWeight: '800' }}>Back to sign in</Text>
      </Pressable>
    </Screen>
  )
}

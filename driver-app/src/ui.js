// Sangoé Driver — shared UI primitives.
//
// Every screen is built from these, so the whole app shares one look, one set of
// spacing, one way a button presses. This is what makes it read as a real app.
import { useState, useRef, useEffect } from 'react'
import {
  View, Text, TextInput, Pressable, ScrollView, ActivityIndicator,
  KeyboardAvoidingView, Platform, Animated,
} from 'react-native'
import { theme, radius, shadow } from './theme'
import { useLayout } from './responsive'

// A short fade + rise on mount — the entrance every screen and card uses so the
// app moves instead of snapping. Cheap (native driver), subtle, fast.
export function Enter({ children, style, delay = 0, distance = 12 }) {
  const a = useRef(new Animated.Value(0)).current
  useEffect(() => {
    Animated.timing(a, { toValue: 1, duration: 280, delay, useNativeDriver: true }).start()
  }, [a, delay])
  return (
    <Animated.View style={[{ opacity: a, transform: [{ translateY: a.interpolate({ inputRange: [0, 1], outputRange: [distance, 0] }) }] }, style]}>
      {children}
    </Animated.View>
  )
}

// A pulsing placeholder block for loading states — reads as "content is coming",
// not "the screen is empty".
export function Skeleton({ height = 16, width = '100%', radius: r = 10, style }) {
  const a = useRef(new Animated.Value(0.35)).current
  useEffect(() => {
    Animated.loop(Animated.sequence([
      Animated.timing(a, { toValue: 0.85, duration: 750, useNativeDriver: true }),
      Animated.timing(a, { toValue: 0.35, duration: 750, useNativeDriver: true }),
    ])).start()
  }, [a])
  return <Animated.View style={[{ height, width, borderRadius: r, backgroundColor: theme.elevated, opacity: a }, style]} />
}

// A page. Handles the status-bar inset, the keyboard, optional scrolling, and
// centres content with a max width on big screens.
export function Screen({ children, scroll = true, center = false, padded = true, bg = theme.bg }) {
  const { topInset, bottomInset, gutter, maxContent, f } = useLayout()
  const inner = (
    <View style={{ width: '100%', maxWidth: maxContent, alignSelf: 'center', paddingHorizontal: padded ? gutter : 0 }}>
      {children}
    </View>
  )
  return (
    <KeyboardAvoidingView behavior={Platform.OS === 'ios' ? 'padding' : undefined} style={{ flex: 1, backgroundColor: bg, paddingTop: topInset }}>
      {scroll ? (
        <ScrollView
          contentContainerStyle={{ flexGrow: 1, justifyContent: center ? 'center' : 'flex-start', paddingVertical: f(20), paddingBottom: f(20) + bottomInset }}
          keyboardShouldPersistTaps="handled" showsVerticalScrollIndicator={false}>
          {inner}
        </ScrollView>
      ) : (
        <View style={{ flex: 1, paddingBottom: bottomInset }}>{inner}</View>
      )}
    </KeyboardAvoidingView>
  )
}

// Top bar: optional back, title + subtitle, optional right action.
export function AppBar({ title, subtitle, onBack, right }) {
  const { f, gutter } = useLayout()
  return (
    <View style={{ paddingHorizontal: gutter, paddingTop: f(6), paddingBottom: f(12), flexDirection: 'row', alignItems: 'center', gap: f(12) }}>
      {onBack ? (
        <Pressable onPress={onBack} hitSlop={12} style={({ pressed }) => ({ opacity: pressed ? 0.5 : 1, marginLeft: -4 })}>
          <Text style={{ color: theme.primary, fontSize: f(26), fontWeight: '400', marginTop: -2 }}>‹</Text>
        </Pressable>
      ) : null}
      <View style={{ flex: 1 }}>
        <Text numberOfLines={1} style={{ color: theme.text, fontSize: f(22), fontWeight: '900' }}>{title}</Text>
        {subtitle ? <Text numberOfLines={1} style={{ color: theme.textMuted, fontSize: f(13.5), marginTop: 2 }}>{subtitle}</Text> : null}
      </View>
      {right ? <View>{right}</View> : null}
    </View>
  )
}

// Button. variant: primary | secondary | ghost | danger.
export function Button({ title, onPress, variant = 'primary', loading, disabled, icon, style }) {
  const { f } = useLayout()
  const off = disabled || loading
  const bg = { primary: theme.primary, secondary: theme.elevated, ghost: 'transparent', danger: theme.danger }[variant]
  const fg = { primary: theme.onPrimary, secondary: theme.text, ghost: theme.primary, danger: '#fff' }[variant]
  const border = variant === 'ghost' ? theme.borderStrong : (variant === 'secondary' ? theme.border : 'transparent')
  return (
    <Pressable onPress={off ? undefined : onPress} disabled={off}
      style={({ pressed }) => [{
        backgroundColor: bg, borderRadius: radius.lg, paddingVertical: f(16), paddingHorizontal: f(18),
        alignItems: 'center', justifyContent: 'center', flexDirection: 'row', gap: f(8),
        borderWidth: border === 'transparent' ? 0 : 1.5, borderColor: border,
        opacity: off ? 0.5 : (pressed ? 0.85 : 1), transform: [{ scale: pressed && !off ? 0.985 : 1 }],
      }, variant === 'primary' && !off && shadow.bar, style]}>
      {loading ? <ActivityIndicator color={fg} /> : (
        <>
          {icon ? <Text style={{ fontSize: f(16) }}>{icon}</Text> : null}
          <Text style={{ color: fg, fontSize: f(16), fontWeight: '800' }}>{title}</Text>
        </>
      )}
    </Pressable>
  )
}

// A raised block.
export function Card({ children, style, onPress }) {
  const { f } = useLayout()
  const base = { backgroundColor: theme.surface, borderColor: theme.border, borderWidth: 1, borderRadius: radius.lg, padding: f(16) }
  if (onPress) {
    return (
      <Pressable onPress={onPress} style={({ pressed }) => [base, shadow.card, { opacity: pressed ? 0.9 : 1, transform: [{ scale: pressed ? 0.99 : 1 }] }, style]}>
        {children}
      </Pressable>
    )
  }
  return <View style={[base, shadow.card, style]}>{children}</View>
}

// Labelled text field with focus ring, hint and error.
export function Field({ label, hint, error, style, ...props }) {
  const { f } = useLayout()
  const [focus, setFocus] = useState(false)
  return (
    <View style={[{ marginBottom: f(4) }, style]}>
      {label ? <Text style={{ color: theme.textMuted, fontSize: f(13.5), fontWeight: '700', marginBottom: f(7) }}>{label}</Text> : null}
      <TextInput
        placeholderTextColor={theme.textFaint}
        onFocus={() => setFocus(true)} onBlur={() => setFocus(false)}
        {...props}
        style={{
          backgroundColor: theme.input, color: theme.text, fontSize: f(16),
          borderRadius: radius.md, paddingHorizontal: f(14), paddingVertical: f(14),
          borderWidth: 1.5, borderColor: error ? theme.danger : (focus ? theme.primary : theme.border),
        }} />
      {error ? <Text style={{ color: theme.danger, fontSize: f(12.5), marginTop: f(6) }}>{error}</Text>
        : hint ? <Text style={{ color: theme.textFaint, fontSize: f(12.5), marginTop: f(6) }}>{hint}</Text> : null}
    </View>
  )
}

// A small status chip. tone: primary | success | warning | danger | muted.
export function Pill({ label, tone = 'muted' }) {
  const { f } = useLayout()
  const map = {
    primary: [theme.primaryTint, theme.primary], success: [theme.successTint, theme.success],
    warning: [theme.warningTint, theme.warning], danger: [theme.dangerTint, theme.danger],
    muted: [theme.elevated, theme.textMuted],
  }
  const [bg, fg] = map[tone] || map.muted
  return (
    <View style={{ backgroundColor: bg, borderRadius: radius.pill, paddingHorizontal: f(11), paddingVertical: f(5) }}>
      <Text style={{ color: fg, fontSize: f(12), fontWeight: '800' }}>{label}</Text>
    </View>
  )
}

export function SectionLabel({ children }) {
  const { f } = useLayout()
  return <Text style={{ color: theme.textFaint, fontSize: f(12), fontWeight: '800', letterSpacing: 0.7, textTransform: 'uppercase', marginBottom: f(12) }}>{children}</Text>
}

export function EmptyState({ icon = '📭', title, subtitle, action }) {
  const { f } = useLayout()
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: f(32) }}>
      <Text style={{ fontSize: f(44), marginBottom: f(12) }}>{icon}</Text>
      <Text style={{ color: theme.text, fontSize: f(17), fontWeight: '800', textAlign: 'center' }}>{title}</Text>
      {subtitle ? <Text style={{ color: theme.textMuted, fontSize: f(14), textAlign: 'center', marginTop: f(6), lineHeight: f(20) }}>{subtitle}</Text> : null}
      {action ? <View style={{ marginTop: f(20), alignSelf: 'stretch' }}>{action}</View> : null}
    </View>
  )
}

export function Loading({ label }) {
  const { f } = useLayout()
  return (
    <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center', padding: f(24) }}>
      <ActivityIndicator color={theme.primary} size="large" />
      {label ? <Text style={{ color: theme.textMuted, fontSize: f(14), marginTop: f(12) }}>{label}</Text> : null}
    </View>
  )
}

export function Divider({ style }) {
  return <View style={[{ height: 1, backgroundColor: theme.border }, style]} />
}

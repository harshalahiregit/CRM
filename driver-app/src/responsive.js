// One hook that makes every screen fit the phone it is on — the responsive
// engine behind the design system.
//
// - topInset/bottomInset : keep content clear of the status bar and the gesture
//   bar / home indicator.
// - f(n)  : scale a size to the screen width, gently clamped.
// - sp    : the spacing scale, already scaled for the device.
// - gutter, maxContent : side padding and a max content width (centred on wide
//   screens) so a form or list never stretches ugly on a tablet.
import { useWindowDimensions, StatusBar, Platform } from 'react-native'
import { space } from './theme'

export function useLayout() {
  const { width, height } = useWindowDimensions()

  const topInset = Platform.OS === 'android'
    ? (StatusBar.currentHeight || 24)
    : (height >= 812 ? 47 : 20)
  // Android draws over the gesture bar; leave room so buttons aren't under it.
  const bottomInset = Platform.OS === 'ios' ? (height >= 812 ? 34 : 12) : 12

  const scale = Math.min(Math.max(width / 380, 0.9), 1.3)
  const f = (n) => Math.round(n * scale)

  // Spacing, scaled once here so screens can write sp.lg etc.
  const sp = Object.fromEntries(Object.entries(space).map(([k, v]) => [k, f(v)]))

  const isSmall = width < 350
  const isTablet = width >= 700
  const gutter = isSmall ? 16 : isTablet ? 40 : 20
  const maxContent = 600

  return { width, height, topInset, bottomInset, f, sp, gutter, maxContent, isSmall, isTablet }
}

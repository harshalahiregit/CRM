// One place that makes every screen fit the phone it is on.
//
// - topInset  : keeps content out from under the status bar / notch. The old
//               screens hard-coded 56px, which overlapped the clock on some
//               phones and left a gap on others.
// - f(n)      : scales a font/size to the screen width, gently clamped so a
//               small phone shrinks a little and a big one grows a little,
//               never to extremes.
// - gutter    : the side padding, tighter on narrow phones, roomier on wide.
// - maxContent: caps how wide a form/card gets on a tablet, and we centre it.
import { useWindowDimensions, StatusBar, Platform } from 'react-native'

export function useLayout() {
  const { width, height } = useWindowDimensions()

  // Reserve the status-bar height. Android reports it; iOS we approximate from
  // whether the device is a notched (tall) one.
  const topInset = Platform.OS === 'android'
    ? (StatusBar.currentHeight || 24)
    : (height >= 812 ? 44 : 20)

  const scale = Math.min(Math.max(width / 380, 0.9), 1.25)
  const f = (n) => Math.round(n * scale)

  const gutter = width < 350 ? 16 : width >= 600 ? 32 : 22
  const maxContent = 560
  const isTablet = width >= 600

  return { width, height, topInset, f, gutter, maxContent, isTablet }
}

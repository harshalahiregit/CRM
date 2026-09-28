import { useState, useEffect } from 'react'
import { View, StatusBar } from 'react-native'
import { theme } from './src/theme'
import { getToken, getUser } from './src/storage'
import { Enter, Loading } from './src/ui'
import LoginScreen from './src/screens/LoginScreen'
import RegisterScreen from './src/screens/RegisterScreen'
import TripsScreen from './src/screens/TripsScreen'
import TripDetailScreen from './src/screens/TripDetailScreen'
import ProfileScreen from './src/screens/ProfileScreen'

export default function App() {
  const [screen, setScreen] = useState('loading') // loading | login | register | trips | detail | profile
  const [user, setUser] = useState(null)
  const [trip, setTrip] = useState(null)

  // Log in once: if a token is already stored, go straight to the trips.
  useEffect(() => {
    (async () => {
      const token = await getToken()
      if (token) { setUser(await getUser()); setScreen('trips') }
      else setScreen('login')
    })()
  }, [])

  // Keying the wrapper by screen remounts it on every change, so each screen
  // fades and rises in instead of snapping — the app feels like it moves.
  const render = () => {
    switch (screen) {
      case 'loading': return <Loading />
      case 'login': return <LoginScreen onLoggedIn={(u) => { setUser(u); setScreen('trips') }} onRegister={() => setScreen('register')} />
      case 'register': return <RegisterScreen onDone={() => setScreen('login')} onBack={() => setScreen('login')} />
      case 'trips': return <TripsScreen user={user} onOpen={(t) => { setTrip(t); setScreen('detail') }} onProfile={() => setScreen('profile')} onSignOut={() => { setUser(null); setScreen('login') }} />
      case 'detail': return trip ? <TripDetailScreen trip={trip} onBack={() => setScreen('trips')} /> : null
      case 'profile': return <ProfileScreen user={user} onBack={() => setScreen('trips')} />
      default: return null
    }
  }

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg }}>
      <StatusBar barStyle="light-content" backgroundColor={theme.bg} />
      <Enter key={screen} style={{ flex: 1 }}>
        {render()}
      </Enter>
    </View>
  )
}

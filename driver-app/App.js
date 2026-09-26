import { useState, useEffect } from 'react'
import { View, ActivityIndicator, StatusBar } from 'react-native'
import { theme } from './src/theme'
import { getToken, getUser } from './src/storage'
import LoginScreen from './src/screens/LoginScreen'
import TripsScreen from './src/screens/TripsScreen'
import TripDetailScreen from './src/screens/TripDetailScreen'

export default function App() {
  const [screen, setScreen] = useState('loading') // loading | login | trips | detail
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

  return (
    <View style={{ flex: 1, backgroundColor: theme.bg }}>
      <StatusBar barStyle="light-content" backgroundColor={theme.bg} />

      {screen === 'loading' && (
        <View style={{ flex: 1, alignItems: 'center', justifyContent: 'center' }}>
          <ActivityIndicator color={theme.accent} />
        </View>
      )}

      {screen === 'login' && (
        <LoginScreen onLoggedIn={(u) => { setUser(u); setScreen('trips') }} />
      )}

      {screen === 'trips' && (
        <TripsScreen
          user={user}
          onOpen={(t) => { setTrip(t); setScreen('detail') }}
          onSignOut={() => { setUser(null); setScreen('login') }}
        />
      )}

      {screen === 'detail' && trip && (
        <TripDetailScreen trip={trip} onBack={() => setScreen('trips')} />
      )}
    </View>
  )
}

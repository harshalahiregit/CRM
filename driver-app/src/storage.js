// Small persistent store: the login token, the server address, and who is
// logged in. AsyncStorage is the phone's local key/value — it survives the app
// being closed, so the driver logs in once.
import AsyncStorage from '@react-native-async-storage/async-storage'

const TOKEN = 'stos.driver.token'
const BASE = 'stos.driver.baseUrl'
const USER = 'stos.driver.user'

export const getToken = () => AsyncStorage.getItem(TOKEN)
export const setToken = (t) => AsyncStorage.setItem(TOKEN, t)

export const getBaseUrl = () => AsyncStorage.getItem(BASE)
export const setBaseUrl = (u) => AsyncStorage.setItem(BASE, u)

export const getUser = async () => {
  const v = await AsyncStorage.getItem(USER)
  try { return v ? JSON.parse(v) : null } catch { return null }
}
export const setUser = (u) => AsyncStorage.setItem(USER, JSON.stringify(u))

// Sign out: forget the token and who was here, keep the server address so the
// next person does not have to type it again.
export const signOut = () => AsyncStorage.multiRemove([TOKEN, USER])

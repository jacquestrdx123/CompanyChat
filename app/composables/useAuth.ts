import type { ApiUser, AuthResponse } from '~/types/api'

// Bearer token from the Laravel API (Sanctum), kept in localStorage so the browser, the PWA and the
// desktop app stay signed in. Every API call and the Reverb channel auth send it.
const TOKEN_KEY = 'companychat:token'

function readToken(): string | null {
  try {
    return localStorage.getItem(TOKEN_KEY)
  } catch {
    return null
  }
}

export function useAuth() {
  const token = useState<string | null>('auth-token', readToken)
  /** The signed-in person (from /api/me), null while unknown or signed out. */
  const user = useState<ApiUser | null>('auth-user', () => null)
  const signedIn = computed(() => !!token.value)

  function setToken(value: string | null) {
    token.value = value
    try {
      if (value) localStorage.setItem(TOKEN_KEY, value)
      else localStorage.removeItem(TOKEN_KEY)
    } catch {
      // private mode: stays signed in for this tab only
    }
  }

  /** After login / accepting an invite / resetting a password. */
  function signInWith(res: AuthResponse) {
    setToken(res.token)
    user.value = res.user
  }

  async function signIn(email: string, password: string) {
    const res = await useApi()<AuthResponse>('/auth/login', {
      method: 'POST',
      body: { email, password, device_name: deviceName() },
    })
    signInWith(res)
  }

  /** Forget the session locally (and on the server, if it still answers). */
  async function signOut() {
    if (token.value) await useApi()('/auth/logout', { method: 'POST' }).catch(() => {})
    clearSession()
  }

  function clearSession() {
    disconnectEcho()
    setToken(null)
    user.value = null
  }

  return { token, user, signedIn, signIn, signInWith, signOut, clearSession }
}

export function deviceName(): string {
  if (typeof navigator === 'undefined') return 'app'
  const desktop = /Electron/.test(navigator.userAgent) ? 'Desktop app' : 'Browser'
  return `${desktop} (${navigator.platform || 'unknown'})`.slice(0, 100)
}

import type { FetchOptions } from 'ofetch'

type ApiOptions = Omit<FetchOptions<'json'>, 'baseURL' | 'method'> & { method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE' }

// Plain (not route-typed) $fetch: our paths point at the Laravel API, not at Nitro routes.
const request = $fetch as unknown as <T>(url: string, options: FetchOptions<'json'>) => Promise<T>

// Pages that work without signing in; a 401 there is just a failed form, not an expired session.
export const PUBLIC_PATHS = ['/login', '/accept-invite', '/reset-password', '/forgot-password']
export const isPublicPath = (path: string) => PUBLIC_PATHS.includes(path.replace(/\/+$/, '') || '/')

/**
 * Calls the Laravel API: adds the bearer token and the Reverb socket id (so our own broadcasts are not
 * echoed back), and ends the session when the server says it is no longer valid.
 */
export function useApi() {
  const config = useRuntimeConfig()
  const base = `${String(config.public.apiUrl).replace(/\/$/, '')}/api`
  const { token } = useAuth()

  return <T = unknown>(path: string, options: ApiOptions = {}) =>
    request<T>(path, {
      baseURL: base,
      ...options,
      headers: {
        Accept: 'application/json',
        ...(token.value ? { Authorization: `Bearer ${token.value}` } : {}),
        ...(echoSocketId() ? { 'X-Socket-ID': echoSocketId()! } : {}),
        ...(options.headers as Record<string, string> | undefined),
      },
      async onResponseError({ response }) {
        // Expired or revoked token, or a deactivated account: back to the sign-in page.
        const deactivated = response.status === 403 && /deactivated/i.test(String(response._data?.message ?? ''))
        if ((response.status === 401 || deactivated) && token.value && !path.startsWith('/auth/')) {
          useAuth().clearSession()
          useChatStore().reset()
          useProfileStore().profile = null
          if (!isPublicPath(useRoute().path)) await navigateTo('/login')
        }
      },
    })
}

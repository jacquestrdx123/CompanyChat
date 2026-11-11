/**
 * A readable message from anything thrown: API errors ({ message, errors } bodies from Laravel),
 * network failures and plain Errors.
 */
export function errorMessage(e: unknown): string {
  if (typeof e === 'object' && e) {
    const data = (e as { data?: { message?: unknown; errors?: Record<string, unknown> } }).data
    if (data && typeof data === 'object') {
      const first = data.errors && Object.values(data.errors).flat()[0]
      if (typeof first === 'string') return first
      if (typeof data.message === 'string' && data.message) return data.message
    }
    const status = (e as { statusCode?: number }).statusCode
    if (status === undefined && (e as { name?: string }).name === 'FetchError') return 'Could not reach the server. Check your connection.'
    if ('message' in e) return String((e as { message: unknown }).message)
  }
  return String(e)
}

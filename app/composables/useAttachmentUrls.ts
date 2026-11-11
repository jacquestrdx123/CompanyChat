// Signed URLs for private attachments, cached across the app and keyed by attachment id. The API signs them
// for 6 hours; we refresh a little before that. Images open inline, other files download.
const REFRESH_AFTER_MS = 5 * 60 * 60 * 1000

interface FileRef {
  id: string
}

export function useAttachmentUrls() {
  const api = useApi()
  const urls = useState<Record<string, string>>('attachment-urls', () => ({}))
  const fetchedAt = useState<Record<string, number>>('attachment-urls-at', () => ({}))

  async function ensure(files: FileRef[]) {
    const now = Date.now()
    const missing = files.filter((f) => !urls.value[f.id] || now - (fetchedAt.value[f.id] ?? 0) > REFRESH_AFTER_MS).map((f) => f.id)
    if (!missing.length) return
    const res = await api<{ urls: Record<string, string> | unknown[] }>('/attachments/urls', { method: 'POST', body: { ids: missing } })
    const signed = Array.isArray(res.urls) ? {} : res.urls // PHP sends an empty object as []
    urls.value = { ...urls.value, ...signed }
    fetchedAt.value = { ...fetchedAt.value, ...Object.fromEntries(Object.keys(signed).map((id) => [id, now])) }
  }

  return { urls, ensure }
}

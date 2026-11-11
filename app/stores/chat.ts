import { defineStore } from 'pinia'
import type { ApiChannel, ApiClient, ApiMessage, ApiUser } from '~/types/api'
import { effectiveStatus, type DisplayStatus } from '~/utils/status'

export type Client = Pick<ApiClient, 'id' | 'name'>
export interface ArchivedProject { id: string; name: string | null; client_id: string; clientName: string }
export type DirectoryUser = Pick<ApiUser, 'id' | 'display_name' | 'status' | 'app_role' | 'is_active'>

/** Live events of one channel (Reverb private-channel.{id}), passed on to whoever shows that channel. */
export type ChannelEvent =
  | { type: 'created'; message: ApiMessage }
  | { type: 'updated'; message: ApiMessage }
  | { type: 'deleted'; id: string; channel_id: string }
export interface Channel {
  id: string
  kind: 'project' | 'dm'
  client_id: string | null
  name: string | null
  is_private: boolean
  member_ids: string[]
}

export const useChatStore = defineStore('chat', () => {
  const api = useApi()
  const auth = useAuth()

  const clients = ref<Client[]>([])
  const channels = ref<Channel[]>([])
  /** Archived clients and projects that I may restore (admins). Hidden everywhere else. */
  const archivedClients = ref<Client[]>([])
  const archivedProjects = ref<ArchivedProject[]>([])
  const directory = ref<Record<string, DirectoryUser>>({})
  const onlineIds = ref<string[]>([])
  const unread = ref<Record<string, number>>({})
  const mentions = ref<Record<string, number>>({})
  const activeChannelId = ref<string | null>(null)
  const watchedChannelIds = ref<string[]>([])
  const loaded = ref(false)

  const myId = computed(() => auth.user.value?.id ?? null)

  const projectsByClient = computed(() => {
    const map: Record<string, Channel[]> = {}
    for (const c of channels.value) {
      if (c.kind === 'project' && c.client_id) (map[c.client_id] ||= []).push(c)
    }
    for (const list of Object.values(map)) list.sort((a, b) => (a.name ?? '').localeCompare(b.name ?? ''))
    return map
  })

  const totalMentions = computed(() => Object.values(mentions.value).reduce((a, b) => a + b, 0))
  const totalUnread = computed(() => Object.values(unread.value).reduce((a, b) => a + b, 0))

  const activePeople = computed(() => Object.values(directory.value).filter((u) => u.is_active))

  const dms = computed(() => channels.value.filter((c) => c.kind === 'dm'))

  function otherUser(channel: Channel): DirectoryUser | undefined {
    const otherId = channel.member_ids.find((id) => id !== myId.value)
    return otherId ? directory.value[otherId] : undefined
  }

  function channelTitle(channel: Channel) {
    return channel.kind === 'dm' ? (otherUser(channel)?.display_name ?? 'Direct message') : `# ${channel.name}`
  }

  function userName(id: string | null) {
    return (id && directory.value[id]?.display_name) || 'Unknown'
  }

  const profileStore = useProfileStore()
  const notifier = useNotifier()
  const push = usePush()

  // UI hints only: the API is what actually enforces these.
  const canManageClient = (_clientId?: string) => profileStore.isAdmin
  const canManageChannel = (channel: Channel | undefined) => !!channel && channel.kind === 'project' && profileStore.isAdmin

  async function load() {
    const [c, ch, p, watches] = await Promise.all([
      api<ApiClient[]>('/clients'),
      api<ApiChannel[]>('/channels'),
      api<ApiUser[]>('/users'),
      api<string[]>('/watches'),
    ])
    const archivedClientIds = new Set(c.filter((x) => x.archived_at).map((x) => x.id))
    clients.value = c.filter((x) => !x.archived_at).map(({ id, name }) => ({ id, name }))
    archivedClients.value = c.filter((x) => x.archived_at).map(({ id, name }) => ({ id, name }))
    const clientNames = Object.fromEntries(c.map((x) => [x.id, x.name]))
    // A project is archived on its own, or because its client is (the latter is restored with the client).
    archivedProjects.value = ch
      .filter((row) => row.kind === 'project' && row.archived_at && row.client_id && !archivedClientIds.has(row.client_id))
      .map((row) => ({ id: row.id, name: row.name, client_id: row.client_id!, clientName: clientNames[row.client_id!] ?? '' }))
    channels.value = ch.filter((row) => !row.archived_at && !(row.client_id && archivedClientIds.has(row.client_id))).map((row) => ({
      id: row.id,
      kind: row.kind,
      client_id: row.client_id,
      name: row.name,
      is_private: row.is_private,
      member_ids: row.member_ids,
    }))
    watchedChannelIds.value = watches
    directory.value = Object.fromEntries(p.map((u) => [u.id, { id: u.id, display_name: u.display_name, status: u.status, app_role: u.app_role, is_active: u.is_active }]))
    loaded.value = true
    syncChannelSubscriptions()
    await loadUnread()
  }

  function displayStatus(userId: string | null | undefined): DisplayStatus {
    if (!userId) return 'offline'
    return effectiveStatus(directory.value[userId]?.status, onlineIds.value.includes(userId))
  }

  // --- unread counts ---------------------------------------------------------------------
  const viewing = () => typeof document !== 'undefined' && document.hasFocus()

  /** False when the counts could not be fetched (e.g. still offline). */
  async function loadUnread(): Promise<boolean> {
    let data: { unread: Record<string, number>; mentions: Record<string, number> }
    try {
      data = await api('/unread')
    } catch {
      return false // counts are a nicety; never block the app on them
    }
    // PHP sends an empty object as [] when nothing is unread
    const counts = { ...(Array.isArray(data.unread) ? {} : data.unread) }
    const mentionCounts = { ...(Array.isArray(data.mentions) ? {} : data.mentions) }
    if (activeChannelId.value && viewing()) {
      delete counts[activeChannelId.value]
      delete mentionCounts[activeChannelId.value]
    }
    unread.value = counts
    mentions.value = mentionCounts
    return true
  }

  async function markRead(channelId: string) {
    if (unread.value[channelId]) unread.value = { ...unread.value, [channelId]: 0 }
    if (mentions.value[channelId]) mentions.value = { ...mentions.value, [channelId]: 0 }
    await api(`/channels/${channelId}/read`, { method: 'POST' }).catch(() => {})
  }

  function setActive(channelId: string | null) {
    activeChannelId.value = channelId
    if (channelId) markRead(channelId)
  }

  async function onIncomingMessage(m: { channel_id: string; user_id: string | null; body?: string }) {
    if (m.user_id === myId.value) return
    if (!channels.value.some((c) => c.id === m.channel_id)) {
      await load() // e.g. someone just started a DM with us
      return
    }
    if (m.channel_id === activeChannelId.value && viewing()) markRead(m.channel_id)
    else unread.value = { ...unread.value, [m.channel_id]: (unread.value[m.channel_id] ?? 0) + 1 }
    notifyAbout(m as { channel_id: string; user_id: string | null; body: string })
  }

  function notifyAbout(m: { channel_id: string; user_id: string | null; body: string }) {
    console.debug('[notify] message received in realtime')
    const channel = channels.value.find((c) => c.id === m.channel_id)
    if (!channel) return
    const body = m.body ?? ''
    const mentionsMe = mentionsUser(body, myId.value)
    const decision = notifyDecision({
      fromSelf: m.user_id === myId.value,
      windowFocused: viewing(),
      status: profileStore.profile?.status,
      kind: channel.kind,
      mentionsMe,
      watching: watchedChannelIds.value.includes(channel.id),
    })
    console.debug('[notify]', decision.notify ? 'showing' : 'skipped', '-', decision.reason)
    if (!decision.notify) return
    if (push.active.value) {
      // The service worker shows the notification (also when this tab is open but not focused); don't double up.
      // It plays the system sound itself unless a CompanyChat window is visible, so only chime when we are.
      if (document.visibilityState === 'visible') notifier.chime()
      return
    }
    const text = notificationText({
      kind: channel.kind,
      senderName: userName(m.user_id),
      channelName: channel.name,
      body,
      nameOf: userName,
    })
    void notifier.show({ channelId: channel.id, ...text }).then((problem) => {
      if (problem) console.debug('[notify] not shown:', problem)
    })
  }

  // --- catching up after sleep or a dropped connection -------------------------------------
  // Live events sent while the socket was down are gone. When it comes back, reload the unread counts and tell
  // the person in one notification what arrived meanwhile (unless push already did, or they are looking).
  let catchingUp: Promise<void> | null = null
  let lastCatchUp = 0

  function catchUp(reason: string) {
    if (!loaded.value || catchingUp || Date.now() - lastCatchUp < 5000) return
    lastCatchUp = Date.now()
    catchingUp = (async () => {
      const before = { unread: { ...unread.value }, mentions: { ...mentions.value } }
      if (!(await loadUnread())) {
        lastCatchUp = 0 // network not back yet: let the reconnect try again
        return
      }
      const summary = catchUpSummary({
        before,
        after: { unread: unread.value, mentions: mentions.value },
        channels: channels.value,
        watched: watchedChannelIds.value,
        windowFocused: viewing(),
        status: profileStore.profile?.status,
      })
      console.debug('[notify] catch-up after', reason, summary ? `- ${summary.count} missed` : '- nothing missed')
      if (!summary || push.active.value) return // with push on, the service worker already showed them
      const first = summary.channelIds[0]!
      const problem = await notifier.show({ channelId: 'catch-up', url: `/c/${first}`, forcePreview: true, ...catchUpText(summary) })
      if (problem) console.debug('[notify] catch-up not shown:', problem)
    })().finally(() => { catchingUp = null })
  }

  async function setWatching(channelId: string, on: boolean) {
    await api(`/channels/${channelId}/watch`, { method: on ? 'PUT' : 'DELETE' })
    watchedChannelIds.value = on ? [...watchedChannelIds.value, channelId] : watchedChannelIds.value.filter((id) => id !== channelId)
  }

  // --- live updates over Reverb --------------------------------------------------------
  //   presence-office          who is online, profile changes, "directory changed" (refetch)
  //   private-channel.{id}     messages in every channel we can open (unread badges, notifications, open page)
  //   private-user.{me}        our own @mentions
  let started: string | null = null
  let subscribed = new Set<string>()
  let stopConnected: (() => void) | null = null
  let stopResume: (() => void) | null = null
  const channelListeners = new Set<(e: ChannelEvent) => void>()
  const onFocus = () => { if (activeChannelId.value) markRead(activeChannelId.value) }

  /** Hear the live events of channels (used by the open channel page). Returns an unsubscribe function. */
  function onChannelEvent(fn: (e: ChannelEvent) => void): () => void {
    channelListeners.add(fn)
    return () => channelListeners.delete(fn)
  }

  function emit(e: ChannelEvent) {
    for (const fn of channelListeners) fn(e)
  }

  /** Follow exactly the channels we can open: join new ones, leave ones we lost. */
  function syncChannelSubscriptions() {
    if (!started) return
    const echo = useEcho()
    const wanted = new Set(channels.value.map((c) => c.id))
    for (const id of subscribed) {
      if (!wanted.has(id)) echo.leave(`channel.${id}`)
    }
    for (const id of wanted) {
      if (subscribed.has(id)) continue
      echo.private(`channel.${id}`)
        .listen('.message.created', (e: { message: ApiMessage }) => {
          emit({ type: 'created', message: e.message })
          onIncomingMessage(e.message)
        })
        .listen('.message.updated', (e: { message: ApiMessage }) => emit({ type: 'updated', message: e.message }))
        .listen('.message.deleted', (e: { id: string; channel_id: string }) => emit({ type: 'deleted', ...e }))
    }
    subscribed = wanted
  }

  /** Idempotent per signed-in user. */
  function start() {
    const id = myId.value
    if (!id || started === id) return
    stop()
    started = id
    const echo = useEcho()

    echo.join('office')
      .here((members: { id: string }[]) => { onlineIds.value = members.map((m) => m.id) })
      .joining((m: { id: string }) => { if (!onlineIds.value.includes(m.id)) onlineIds.value = [...onlineIds.value, m.id] })
      .leaving((m: { id: string }) => { onlineIds.value = onlineIds.value.filter((x) => x !== m.id) })
      .listen('.profile.updated', (e: { user: ApiUser }) => {
        const u = e.user
        directory.value = { ...directory.value, [u.id]: { id: u.id, display_name: u.display_name, status: u.status, app_role: u.app_role, is_active: u.is_active } }
        if (u.id === myId.value) {
          if (!u.is_active) return // the next API call ends the session
          const profileStore = useProfileStore()
          if (profileStore.profile) profileStore.profile = { ...profileStore.profile, ...u, email: profileStore.profile.email }
        }
      })
      .listen('.directory.changed', () => { void load().catch(() => {}) })

    // Only our own mentions are sent to this channel.
    echo.private(`user.${id}`).listen('.mention.created', (e: { channel_id: string }) => {
      if (e.channel_id === activeChannelId.value && viewing()) return // already being read
      mentions.value = { ...mentions.value, [e.channel_id]: (mentions.value[e.channel_id] ?? 0) + 1 }
    })

    syncChannelSubscriptions()

    // Reconnected after the socket dropped: something may have been missed.
    let connectedBefore = false
    stopConnected = onEchoConnected(() => {
      if (connectedBefore) catchUp('reconnect')
      connectedBefore = true
    })
    window.addEventListener('focus', onFocus)
    // Desktop app woke from sleep: give Wi-Fi a moment, then see what was missed.
    stopResume = desktopBridge()?.onResume?.(() => { setTimeout(() => catchUp('wake'), 3000) }) ?? null
  }

  function stop() {
    const echo = currentEcho() // never open a new connection just to leave
    if (started && echo) {
      echo.leave('office')
      echo.leave(`user.${started}`)
      for (const id of subscribed) echo.leave(`channel.${id}`)
    }
    subscribed = new Set()
    stopConnected?.()
    stopConnected = null
    if (typeof window !== 'undefined') window.removeEventListener('focus', onFocus)
    stopResume?.()
    stopResume = null
    started = null
    onlineIds.value = []
    unread.value = {}
    mentions.value = {}
  }

  async function createClient(name: string) {
    await api('/clients', { method: 'POST', body: { name: name.trim() } })
    await load()
  }

  async function createProject(clientId: string, name: string, isPrivate: boolean) {
    const data = await api<ApiChannel>('/channels', { method: 'POST', body: { client_id: clientId, name: name.trim(), is_private: isPrivate } })
    await load()
    return data.id
  }

  async function setProjectArchived(channelId: string, archived: boolean) {
    await api(`/channels/${channelId}/archived`, { method: 'PUT', body: { archived } })
    await load()
  }

  async function setClientArchived(clientId: string, archived: boolean) {
    await api(`/clients/${clientId}/archived`, { method: 'PUT', body: { archived } })
    await load()
  }

  async function startDm(userId: string) {
    const data = await api<ApiChannel>('/dms', { method: 'POST', body: { user_id: userId } })
    await load()
    return data.id
  }

  function reset() {
    stop()
    clients.value = []
    channels.value = []
    archivedClients.value = []
    archivedProjects.value = []
    directory.value = {}
    watchedChannelIds.value = []
    loaded.value = false
  }

  return { clients, channels, directory, onlineIds, activePeople, displayStatus, start, stop, watchedChannelIds, setWatching, unread, mentions, totalUnread, totalMentions, activeChannelId, setActive, markRead, canManageClient, canManageChannel, loaded, myId, projectsByClient, dms, otherUser, channelTitle, userName, load, createClient, createProject, setProjectArchived, setClientArchived, archivedClients, archivedProjects, startDm, reset, onChannelEvent }
})

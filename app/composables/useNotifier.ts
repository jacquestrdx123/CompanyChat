interface Prefs {
  enabled: boolean // master switch for this browser/device
  sound: boolean
  preview: boolean // show the message text in the notification
  dismissed: boolean // user said "not now" to the permission banner
}

const STORAGE_KEY = 'companychat:notifications'
const DEFAULTS: Prefs = { enabled: true, sound: true, preview: true, dismissed: false }

function loadPrefs(): Prefs {
  try {
    return { ...DEFAULTS, ...JSON.parse(localStorage.getItem(STORAGE_KEY) ?? '{}') }
  } catch {
    return { ...DEFAULTS }
  }
}

type Permission = NotificationPermission | 'unsupported'

let lastBeep = 0

function beep() {
  const now = Date.now()
  if (now - lastBeep < 1500) return // one sound per burst of messages
  lastBeep = now
  try {
    const ctx = new AudioContext()
    const osc = ctx.createOscillator()
    const gain = ctx.createGain()
    osc.type = 'sine'
    osc.frequency.value = 880
    gain.gain.setValueAtTime(0.0001, ctx.currentTime)
    gain.gain.exponentialRampToValueAtTime(0.15, ctx.currentTime + 0.02)
    gain.gain.exponentialRampToValueAtTime(0.0001, ctx.currentTime + 0.25)
    osc.connect(gain).connect(ctx.destination)
    osc.start()
    osc.stop(ctx.currentTime + 0.3)
    osc.onended = () => ctx.close()
  } catch {
    // blocked by the browser's autoplay rules: the visual notification still shows
  }
}

/** Desktop notifications for this browser. Preferences live in localStorage (per device). */
export function useNotifier() {
  const prefs = useState<Prefs>('notify-prefs', loadPrefs)
  const permission = useState<Permission>('notify-permission', () => (typeof Notification === 'undefined' ? 'unsupported' : Notification.permission))

  function save() {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(prefs.value))
    } catch {
      // private mode etc.: preferences just won't persist
    }
  }

  function set<K extends keyof Prefs>(key: K, value: Prefs[K]) {
    prefs.value = { ...prefs.value, [key]: value }
    save()
  }

  /** Ask the browser for permission. Must be called from a click. */
  async function request(): Promise<Permission> {
    if (typeof Notification === 'undefined') return 'unsupported'
    const result = await Notification.requestPermission()
    permission.value = result
    if (result === 'granted') set('enabled', true)
    return result
  }

  const supported = computed(() => permission.value !== 'unsupported')
  const canAsk = computed(() => permission.value === 'default' && !prefs.value.dismissed)
  const active = computed(() => prefs.value.enabled && permission.value === 'granted')

  /** Show a notification. Resolves to an error message if the browser refused (for the test button). */
  async function show(opts: { channelId: string; title: string; body: string; url?: string; forcePreview?: boolean }): Promise<string | null> {
    if (!active.value) return 'Notifications are switched off or not allowed for this site.'
    const options = {
      body: prefs.value.preview || opts.forcePreview ? opts.body : 'New message',
      tag: opts.channelId, // a newer message in the same channel replaces the older one
      silent: true, // we play our own sound so it can be switched off
      // Without this a notification that replaces an earlier one with the same tag is shown silently.
      renotify: true,
    } as NotificationOptions
    const url = opts.url ?? `/c/${opts.channelId}`
    try {
      const n = new Notification(opts.title, options)
      n.onclick = () => {
        desktopBridge()?.showWindow?.() // the desktop app's window may be hidden in the tray
        window.focus()
        navigateTo(url)
        n.close()
      }
    } catch (e) {
      // Android Chrome (and installed PWAs) refuse `new Notification()`: they only allow showing one through
      // the service worker, whose click handler takes you to the conversation.
      try {
        const registration = await navigator.serviceWorker?.getRegistration()
        if (!registration) return e instanceof Error ? e.message : String(e)
        await registration.showNotification(opts.title, { ...options, data: { url } })
      } catch (e2) {
        return e2 instanceof Error ? e2.message : String(e2)
      }
    }
    if (prefs.value.sound) beep()
    return null
  }

  /** Fire a sample notification right now, regardless of focus, to check the whole chain. */
  function test(): Promise<string | null> {
    return show({ channelId: 'test', title: 'CompanyChat', body: 'This is a test notification.', url: '/', forcePreview: true })
  }

  /** Just the sound (used when a background push shows the visual notification instead). */
  function chime() {
    if (prefs.value.sound) beep()
  }

  return { prefs, permission, supported, canAsk, active, set, request, show, test, chime }
}

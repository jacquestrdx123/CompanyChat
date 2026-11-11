<script setup lang="ts">
import { STATUS_OPTIONS, effectiveStatus } from '~/utils/status'

const auth = useAuth()
const store = useProfileStore()
const chat = useChatStore()
const toast = useToast()
const notifier = useNotifier()
const installer = useInstallPrompt()
const push = usePush()

// On phones the sidebar is a slide-over drawer opened from the header; it closes when you pick something.
const menuOpen = ref(false)
const route = useRoute()
watch(() => route.fullPath, () => { menuOpen.value = false })

useHead({
  title: computed(() => {
    if (chat.totalMentions) return `(@${chat.totalMentions}) CompanyChat`
    return chat.totalUnread ? `(${chat.totalUnread}) CompanyChat` : 'CompanyChat'
  }),
})

watch(
  auth.token,
  async (token) => {
    if (!token) return
    try {
      await store.load() // also tells us who we are; a revoked token or deactivated account ends the session here
    } catch (e) {
      if (auth.signedIn.value) toast.add({ title: 'Could not load your profile', description: errorMessage(e), color: 'error' })
      return
    }
    chat.start()
    push.sync()
    chat.load().catch((e) => toast.add({ title: 'Could not load chats', description: errorMessage(e), color: 'error' }))
  },
  { immediate: true },
)

async function toggleNotifications() {
  if (notifier.permission.value === 'granted') return notifier.set('enabled', !notifier.prefs.value.enabled)
  const result = await notifier.request()
  if (result === 'denied') {
    toast.add({
      title: 'Notifications are blocked',
      description: 'Allow notifications for this site in your browser settings, then try again.',
      color: 'warning',
    })
  }
}

const onOff = (v: boolean) => (v ? 'On' : 'Off')

async function togglePush() {
  try {
    if (push.active.value) await push.disable()
    else await push.enable()
    toast.add({ title: push.active.value ? 'Background notifications are on for this device' : 'Background notifications are off for this device' })
  } catch (e) {
    toast.add({ title: 'Could not change background notifications', description: errorMessage(e), color: 'error' })
  }
}

function togglePreview() {
  notifier.set('preview', !notifier.prefs.value.preview)
  push.syncPreview()
}

function notificationState() {
  if (notifier.permission.value === 'denied') return 'Blocked in browser'
  return onOff(notifier.active.value)
}

// Desktop app only: start CompanyChat (hidden in the tray) when you sign in to the computer.
const desktop = desktopBridge()
const launchAtLogin = ref<{ supported: boolean; on: boolean } | null>(null)
onMounted(async () => {
  launchAtLogin.value = (await desktop?.getLaunchAtLogin?.().catch(() => null)) ?? null
})
async function toggleLaunchAtLogin() {
  if (!desktop?.setLaunchAtLogin || !launchAtLogin.value) return
  const on = await desktop.setLaunchAtLogin(!launchAtLogin.value.on)
  launchAtLogin.value = { ...launchAtLogin.value, on }
}

async function sendTest() {
  if (notifier.permission.value !== 'granted') {
    toast.add({
      title: 'Notifications are not allowed yet',
      description: notifier.permission.value === 'denied'
        ? 'Your browser is blocking them for this site. Allow notifications in the site settings, then try again.'
        : 'Turn on desktop notifications first.',
      color: 'warning',
    })
    return
  }
  const problem = await notifier.test()
  toast.add(
    problem
      ? { title: 'Could not show a notification', description: problem, color: 'error' }
      : {
          title: 'Test notification sent',
          description: 'If you saw nothing, check your device\'s notification settings for this app or browser and that Focus / Do Not Disturb is off.',
        },
  )
}

const items = computed(() => [
  STATUS_OPTIONS.map((s) => ({
    label: s.label,
    onSelect: () => store.setStatus(s.value).catch(() => toast.add({ title: 'Could not update status', color: 'error' })),
  })),
  ...(notifier.supported.value
    ? [[
        { label: `Desktop notifications: ${notificationState()}`, icon: 'i-lucide-bell', onSelect: toggleNotifications },
        { label: 'Send a test notification', icon: 'i-lucide-bell-ring', onSelect: sendTest },
        ...(push.supported.value ? [{ label: `Notify when the app is closed: ${onOff(push.active.value)}`, icon: 'i-lucide-bell-dot', onSelect: togglePush }] : []),
        { label: `Notification sound: ${onOff(notifier.prefs.value.sound)}`, icon: 'i-lucide-volume-2', onSelect: () => notifier.set('sound', !notifier.prefs.value.sound) },
        { label: `Message previews: ${onOff(notifier.prefs.value.preview)}`, icon: 'i-lucide-eye', onSelect: togglePreview },
      ]]
    : []),
  ...(launchAtLogin.value?.supported
    ? [[{ label: `Start at login: ${onOff(launchAtLogin.value.on)}`, icon: 'i-lucide-power', onSelect: toggleLaunchAtLogin }]]
    : []),
  ...(installer.canInstall.value ? [[{ label: 'Install app', icon: 'i-lucide-download', onSelect: installer.install }]] : []),
  ...(store.isAdmin ? [[{ label: 'Manage users', icon: 'i-lucide-shield', to: '/admin/users' }]] : []),
  [{
    label: 'Sign out',
    icon: 'i-lucide-log-out',
    onSelect: async () => {
      await push.forget().catch(() => {}) // before signing out, while we can still authenticate
      chat.reset()
      await auth.signOut()
      store.profile = null
      await navigateTo('/login')
    },
  }],
])
</script>

<template>
  <div class="flex h-dvh flex-col">
    <header class="app-header flex items-center justify-between border-b border-accented bg-default px-4 py-2">
      <div class="flex items-center gap-1">
      <!-- On phones the sidebar is closed: show what it would show, so nothing goes unnoticed. -->
      <div class="relative md:hidden">
        <UButton
          icon="i-lucide-menu"
          color="neutral"
          variant="ghost"
          :aria-label="chat.totalMentions ? `Open menu, ${chat.totalMentions} mentions` : chat.totalUnread ? `Open menu, ${chat.totalUnread} unread` : 'Open menu'"
          @click="menuOpen = true"
        />
        <UBadge
          v-if="chat.totalMentions || chat.totalUnread"
          size="sm"
          :color="chat.totalMentions ? 'error' : 'secondary'"
          class="pointer-events-none absolute -right-1 -top-1 min-w-5 justify-center px-1"
        >{{ chat.totalMentions ? `@${chat.totalMentions}` : chat.totalUnread > 99 ? '99+' : chat.totalUnread }}</UBadge>
      </div>
      <NuxtLink to="/" class="flex items-center gap-2 font-semibold text-highlighted">
        <span class="inline-flex size-7 items-center justify-center rounded-lg bg-primary/15 text-primary"><UIcon name="i-lucide-message-square-text" class="size-4" /></span>
        CompanyChat
      </NuxtLink>
      </div>
      <div class="flex items-center gap-1">
        <ClientOnly>
          <UColorModeButton color="neutral" variant="ghost" aria-label="Switch between light and dark mode" />
          <template #fallback><div class="size-8" /></template>
        </ClientOnly>
        <UDropdownMenu v-if="store.profile" :items="items">
          <UButton color="neutral" variant="ghost">
            <StatusDot :status="effectiveStatus(store.profile.status, true)" />
            {{ store.profile.display_name }}
          </UButton>
        </UDropdownMenu>
      </div>
    </header>
    <div v-if="notifier.canAsk.value" class="flex items-center justify-between gap-3 border-b border-default bg-elevated px-4 py-2 text-sm">
      <span>Get desktop notifications for direct messages and @mentions?</span>
      <span class="flex gap-2">
        <UButton size="xs" @click="toggleNotifications">Turn on</UButton>
        <UButton size="xs" color="neutral" variant="ghost" @click="notifier.set('dismissed', true)">Not now</UButton>
      </span>
    </div>
    <div class="flex min-h-0 flex-1">
      <div v-if="menuOpen" class="fixed inset-0 z-30 bg-black/60 md:hidden" @click="menuOpen = false" />
      <div
        class="fixed inset-y-0 left-0 z-40 w-72 max-w-[85vw] transition-transform duration-200 md:static md:z-auto md:max-w-none md:translate-x-0"
        :class="menuOpen ? 'translate-x-0' : '-translate-x-full'"
      >
        <AppSidebar class="h-full w-full" />
      </div>
      <main class="min-w-0 flex-1 bg-default"><slot /></main>
    </div>
  </div>
</template>
